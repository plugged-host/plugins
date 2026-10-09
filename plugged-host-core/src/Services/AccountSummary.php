<?php

namespace PluggedHost\Core\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * One flat, cheap snapshot of everything the topbar chips display.
 *
 * This exists because the chips render on *every* page of the app and server panels, and the
 * obvious way to build them is ruinous: AccountAllowance::getCpuLeft(), getMemoryLeft() and
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
        $limits = AccountAllowance::forUser($user);

        if (!$limits) {
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

        return [
            'tokens' => $this->tokens($user),
            'credits' => $this->credits($user),
            'cpu_used' => (int) ($used->cpu_used ?? 0),
            'cpu_limit' => (int) $limits->cpu,
            'memory_used' => (int) ($used->memory_used ?? 0),
            'memory_limit' => (int) $limits->memory,
            'disk_used' => (int) ($used->disk_used ?? 0),
            'disk_limit' => (int) $limits->disk,
            'servers_used' => (int) ($used->servers_used ?? 0),
        ];
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
