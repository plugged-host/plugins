<?php

namespace PluggedHost\Economy\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Contracts\ComputePlans;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * One flat, cheap snapshot of everything the topbar chips display.
 *
 * This exists because the chips render on *every* page of the app and server panels, and the
 * obvious way to build them is ruinous: servers' AccountAllowance::getCpuLeft(), getMemoryLeft() and
 * getDiskLeft() each run their own `$user->servers()->sum(...)`, and getOverage() runs four
 * more. Reusing those would put half a dozen aggregate queries on every request in the panel
 * to render three small numbers.
 *
 * So usage is read with a single grouped aggregate, and the whole snapshot is memoised for the
 * life of the request — the service is bound with scoped(), so "the life of the request" is
 * also the life of the instance. A page that renders the chips *and* the stats widget still
 * only pays once.
 *
 * Tokens and credits both live in plugged-host-economy, and the token *tables* are owned by
 * plugged-host-ads, so either can be absent. Both reads are guarded and degrade to null, which
 * the view renders as a missing chip rather than a zero — "0 tokens" and "tokens are not a
 * thing here" are different statements and must not look the same.
 */
class AccountSummary
{
    /**
     * Keyed by user id. Holds null for a user with no plan yet, which is a real
     * answer and must be cached as one, hence array_key_exists() rather than ??.
     *
     * @var array<int, ?array<string, int|null>>
     */
    private array $memo = [];

    /**
     * @return null|array{
     *     tokens: ?int, credits: ?int,
     *     cpu_used: int, cpu_limit: int, memory_used: int, memory_limit: int,
     *     disk_used: int, disk_limit: int, servers_used: int,
     *     cpu_elsewhere: int, memory_elsewhere: int, disk_elsewhere: int,
     * }
     */
    public function forUser(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        $key = (int) $user->getKey();

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $cacheKey = "users:{$key}:account_summary";

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->memo[$key] = $cached;
            }
        } catch (Throwable) {}

        $summary = $this->build($user);

        if ($summary !== null) {
            try {
                Cache::put($cacheKey, $summary, 60);
            } catch (Throwable) {}
        }

        return $this->memo[$key] = $summary;
    }

    /**
     * Drop the memo after something that changes the numbers — a purchase, a server create.
     *
     * Within one request that is rarely needed; it matters for the queue worker and for
     * Livewire round-trips that buy something and then re-render the chips in the same tick.
     */
    public function forget(?User $user = null): void
    {
        if ($user) {
            $key = (int) $user->getKey();
            unset($this->memo[$key]);

            try {
                Cache::forget("users:{$key}:account_summary");
            } catch (Throwable) {}

            return;
        }

        foreach (array_keys($this->memo) as $key) {
            try {
                Cache::forget("users:{$key}:account_summary");
            } catch (Throwable) {}
        }

        $this->memo = [];
    }

    /** @return ?array<string, int|null> */
    private function build(User $user): ?array
    {
        $limits = AccountLimits::forUser($user);

        if ($limits === null) {
            return null;
        }

        // toBase() keeps this a single scalar row instead of hydrating every Server model
        // just to add four columns up.
        $used = Server::query()
            ->where('owner_id', $user->getKey())
            ->toBase()
            ->selectRaw('COUNT(*) AS servers_used')
            ->selectRaw('COALESCE(SUM(cpu), 0) AS cpu_used')
            ->selectRaw('COALESCE(SUM(memory), 0) AS memory_used')
            ->selectRaw('COALESCE(SUM(disk), 0) AS disk_used')
            ->first();

        $summary = [
            'tokens' => $this->tokens($user),
            'credits' => $this->credits($user),
            'servers_used' => (int) ($used->servers_used ?? 0),
        ];

        // Where the plan is a pool other services share (noprofit.space: switched.cloud too),
        // shown as they show it: the whole pool (plus what the allowance adds here, PlusPlus
        // CPU) and what every service uses of it. What is left is the same as before (the
        // allowance less these servers): only the enforced share is smaller than the pool by
        // what the other services use. A plan of this panel's alone has no pool.
        $pool = $this->pool($user);

        foreach (['cpu', 'memory', 'disk'] as $resource) {
            $own = (int) ($used->{"{$resource}_used"} ?? 0);
            $allowance = (int) $limits[$resource];
            $part = $pool[$resource] ?? null;

            $summary["{$resource}_used"] = $part === null ? $own : $own + $part['elsewhere'];
            $summary["{$resource}_limit"] = $part === null ? $allowance : $part['total'] + max(0, $allowance - $part['share']);
            $summary["{$resource}_elsewhere"] = $part['elsewhere'] ?? 0;
        }

        return $summary;
    }

    /**
     * @return array<string, array{total: int, elsewhere: int, share: int}>|null
     */
    private function pool(User $user): ?array
    {
        try {
            return app(ComputePlans::class)->pool($user);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Null rather than 0 when the economy plugin is gone or the token feature is switched off.
     */
    private function tokens(User $user): ?int
    {
        if (!Plugins::available(TokenService::class)) {
            return null;
        }

        try {
            $tokens = app(TokenService::class);

            return $tokens->isEnabled() ? $tokens->balance($user) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function credits(User $user): ?int
    {
        if (!Plugins::available(UserCredits::class)) {
            return null;
        }

        try {
            return UserCredits::forUser($user)?->credits;
        } catch (Throwable) {
            return null;
        }
    }
}
