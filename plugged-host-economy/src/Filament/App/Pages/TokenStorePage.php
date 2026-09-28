<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterval;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * The Token Store: the token balance, how to earn more, and everything tokens buy.
 *
 * Tokens come from sponsored links and are spent on things that are rented or unlocked rather
 * than on hardware: a priority window in the start queue (plugged-host-queue) and the Plus and
 * Premium locations (plugged-host-servers). Credits and the resources they buy are the
 * Resource Store's (ResourceStorePage); the two currencies are not interchangeable, and
 * giving each its own page is what makes that obvious.
 *
 * Earning is shown here too, and it is not this page's: the earn block renders into the
 * `plugged-host-economy::store.after-tokens` hook under the balance (EarnPluginServiceProvider).
 * The sponsored anchor itself stays on EarnTokensPage, which is the only page that loads the
 * link-rewriting script.
 *
 * The queue and servers plugins are reached by class name, never imported: with either one
 * uninstalled its section is simply not there.
 */
class TokenStorePage extends Page
{
    /** The queue's pass service. */
    protected const QUEUE_PASSES = 'PluggedHost\\Queue\\Services\\PriorityPassService';

    /** The servers plugin's Plus/Premium unlock. */
    protected const PREMIUM_LOCATIONS = 'PluggedHost\\Servers\\Services\\PremiumLocationService';

    protected const PREMIUM_ACCESS = 'PluggedHost\\Servers\\Support\\PremiumAccess';

    /**
     * Where the earn block renders, directly under the balance. A literal string on both sides
     * (see EarnPluginServiceProvider::STORE_HOOK), so the page does not import who fills it.
     */
    public const HOOK_AFTER_TOKENS = 'plugged-host-economy::store.after-tokens';

    /** The pass service's result strings, repeated rather than imported. */
    protected const PASS_OK = 'ok';

    protected const PASS_SHORT = 'short';

    protected const PASS_UNAVAILABLE = 'unavailable';

    protected static string|BackedEnum|null $navigationIcon = 'tabler-coins';

    protected static ?string $slug = 'token-store';

    protected static ?int $navigationSort = 16;

    protected string $view = 'plugged-host-economy::filament.pages.token-store';

    public function getTitle(): string
    {
        return trans('plugged-host-economy::strings.token_store.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-economy::strings.token_store.nav_label');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-economy::strings.token_store.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) config('plugged-host-economy.tokens.enabled', true) && parent::canAccess();
    }

    /**
     * Buy a priority window with tokens.
     *
     * The tier arrives from the browser and is not trusted: the pass service normalises anything
     * it does not recognise, or has stopped selling, down to the cheap tier at the cheap price.
     * Charging (and refunding a failed grant) happens in the queue plugin.
     */
    public function buyPriority(string $tier = 'standard'): void
    {
        $user = auth()->user();
        $passes = $this->service(self::QUEUE_PASSES);

        if (!$user instanceof User || $passes === null) {
            return;
        }

        try {
            $offer = (array) $passes->describe($tier);
            $result = (string) $passes->purchase($user, $tier);
        } catch (Throwable) {
            $this->notice('priority.notify_failed', 'danger');

            return;
        }

        $days = (int) ($offer['days'] ?? 0);

        match ($result) {
            self::PASS_OK => $this->notice(($offer['ad_free'] ?? false) ? 'priority.notify_bought_ad_free' : 'priority.notify_bought', 'success', ['days' => $days]),
            self::PASS_SHORT => $this->notice('priority.notify_short', 'warning', ['cost' => number_format((int) ($offer['cost'] ?? 0))]),
            self::PASS_UNAVAILABLE => $this->notice('priority.notify_unavailable', 'warning'),
            default => $this->notice('priority.notify_failed', 'danger'),
        };
    }

    /**
     * Unlock the Plus and Premium locations: one token purchase, kept forever. Charging and the
     * refund on a failed write happen in PremiumLocationService.
     */
    public function unlockPremium(): void
    {
        $user = auth()->user();
        $premium = $this->service(self::PREMIUM_LOCATIONS);

        if (!$user instanceof User || $premium === null) {
            return;
        }

        try {
            $result = (array) $premium->unlock($user);
        } catch (Throwable) {
            $this->notice('token_store.premium_failed', 'danger');

            return;
        }

        if ($result['ok'] ?? false) {
            $this->notice('token_store.premium_unlocked', 'success');

            return;
        }

        ($result['reason'] ?? null) === 'insufficient_tokens'
            ? $this->notice('token_store.premium_short', 'warning', ['cost' => number_format((int) ($result['cost'] ?? 0))])
            : $this->notice('token_store.premium_failed', 'danger');
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function notice(string $key, string $type, array $replace = []): void
    {
        $notification = Notification::make()->title(trans("plugged-host-economy::strings.{$key}", $replace));

        match ($type) {
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->success(),
        };

        $notification->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();

        /** @var TokenService $tokens */
        $tokens = app(TokenService::class);
        $balance = $tokens->balance($user);

        return [
            'tokens' => $balance,
            'earnTokensUrl' => $this->earnTokensUrl(),
            'priority' => $this->priorityData($user, $balance),
            'premium' => $this->premiumData($user, $balance),
            'transactions' => $tokens->recentTransactions($user, 10),
        ];
    }

    /**
     * What priority is on offer and what this account already holds. Null when the queue plugin
     * is absent, or it stopped selling and this account holds no window (a paid window keeps
     * running after the offer is withdrawn, so its receipt still shows).
     *
     * @return array<string, mixed>|null
     */
    protected function priorityData(mixed $user, int $balance): ?array
    {
        $passes = $this->service(self::QUEUE_PASSES);

        if ($passes === null || !$user instanceof User) {
            return null;
        }

        try {
            $sellable = (bool) $passes->available();
            $active = (bool) $passes->isActive($user);

            if (!$sellable && !$active) {
                return null;
            }

            $tiers = [];

            foreach ($sellable ? (array) $passes->tiers() : [] as $tier) {
                $cost = (int) ($tier['cost'] ?? 0);

                $tiers[] = [
                    'key' => (string) ($tier['key'] ?? 'standard'),
                    'days' => (int) ($tier['days'] ?? 0),
                    'cost' => $cost,
                    'ad_free' => (bool) ($tier['ad_free'] ?? false),
                    'unaffordable' => $balance < $cost,
                ];
            }

            return [
                'active' => $active,
                'remaining' => $this->humanDuration((int) $passes->remainingSeconds($user)),
                'adFree' => (bool) $passes->isAdFree($user),
                'adFreeRemaining' => $this->humanDuration((int) $passes->adFreeRemainingSeconds($user)),
                'tiers' => $tiers,
            ];
        } catch (Throwable) {
            // A pass service that cannot answer costs this page one section.
            return null;
        }
    }

    /**
     * The Plus/Premium unlock, or null when the servers plugin (or its column) is not there.
     *
     * @return array{unlocked: bool, cost: int, unaffordable: bool}|null
     */
    protected function premiumData(mixed $user, int $balance): ?array
    {
        $premium = $this->service(self::PREMIUM_LOCATIONS);
        $access = self::PREMIUM_ACCESS;

        if ($premium === null || !$user instanceof User || !class_exists($access)) {
            return null;
        }

        try {
            if (!$access::supported()) {
                return null;
            }

            $cost = (int) $premium->cost();

            return [
                'unlocked' => (bool) $premium->isUnlocked($user),
                'cost' => $cost,
                'unaffordable' => $balance < $cost,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    protected function service(string $class): mixed
    {
        if (!class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "2 days 4 hours" rather than 187,412 seconds.
     */
    protected function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        try {
            return CarbonInterval::seconds($seconds)->cascade()->forHumans(['parts' => 2]);
        } catch (Throwable) {
            return (string) $seconds;
        }
    }

    protected function earnTokensUrl(): ?string
    {
        try {
            return EarnTokensPage::canAccess() ? EarnTokensPage::getUrl(panel: 'app') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
