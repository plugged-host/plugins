<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterval;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use PluggedHost\Core\Filament\App\Widgets\PerkDiscoveryWidget;
use PluggedHost\Servers\Filament\App\Widgets\UserResourceLimitsOverview;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\ResourceStoreService;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * The Economy page. Both currencies, side by side, and everything either of them buys.
 *
 * Credits and tokens are not interchangeable and the page is built to make that
 * obvious rather than to hide it. Credits come from Discord invites and boosts and buy
 * CPU, memory, disk and server slots — permanent things that make an account bigger.
 * Tokens come from Linkvertise and buy a priority window in the start queue: rented,
 * measured in days, and gone when it runs out.
 *
 * It is called "Economy" and the class is still `ResourceStorePage` at slug `store`.
 * That is deliberate: `AccountChips`, `UserResourceLimitsOverview`, `PerkDiscovery`,
 * plugged-host-discord's profile tab and the two waiting rooms all resolve this page,
 * and the URL is in people's browser history. Only `getTitle()`,
 * `getNavigationLabel()` and `getSubheading()` carry the new name. Renaming the class
 * would be five edits and a dead bookmark for no gain.
 *
 * Earning is on this page too, and it is not ours: plugged-host-ads renders into the
 * `plugged-host-economy::store.after-tokens` render hook below the token wallet. The
 * hook is a plain string on purpose — economy does not know the ads plugin exists, and
 * with ads uninstalled or disabled nothing renders there and the page is unchanged.
 * The outbound sponsored anchor deliberately stays on the ads plugin's own page; see
 * EarnTokensPage for why it cannot live on a wire:navigate-reachable page.
 *
 * The priority tiers are sold here as well as on the waiting page, and this is the more
 * important of the two places. The waiting page can only be reached by pressing Start on
 * a node that is already full, which is precisely the moment somebody does not want to
 * be reading about tiers — and it means the offer was invisible to anyone who had not
 * yet been made to wait.
 *
 * The queue plugin is reached by string, never imported. With it uninstalled the token
 * balance still renders and the priority section simply is not there.
 */
class ResourceStorePage extends Page
{
    /** The queue's pass service. Resolved by name; see the class docblock. */
    protected const QUEUE_PASSES = 'PluggedHost\\Queue\\Services\\PriorityPassService';

    /**
     * Where another plugin may add to this page, directly under the token wallet card.
     *
     * A literal string rather than a shared constant, the same convention QUEUE_PASSES
     * uses: the whole point is that neither plugin has to be installed for the other to
     * work. plugged-host-ads registers the earning block here.
     */
    public const HOOK_AFTER_TOKENS = 'plugged-host-economy::store.after-tokens';

    /**
     * The pass service's own result strings, repeated rather than imported.
     *
     * Four literals against one hard dependency on another plugin's class is the
     * cheaper trade; `purchase()` returns exactly these and anything unrecognised is
     * treated as a failure below.
     */
    protected const PASS_OK = 'ok';

    protected const PASS_SHORT = 'short';

    protected const PASS_UNAVAILABLE = 'unavailable';

    protected static string|BackedEnum|null $navigationIcon = 'tabler-shopping-bag';

    protected static ?string $slug = 'store';

    protected static ?int $navigationSort = 15;

    protected string $view = 'plugged-host-economy::filament.pages.resource-store';

    public function getTitle(): string
    {
        return trans('plugged-host-economy::strings.economy.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-economy::strings.economy.nav_label');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-economy::strings.economy.subheading');
    }

    /**
     * Who gets in.
     *
     * The resource-limits profile is no longer the whole test. It gates the upgrade
     * cards — with no profile there is nothing to raise, and `getUserResourceSummary()`
     * answers zeroes with every `can_buy_*` false — but priority is sold per account
     * and has nothing to do with a profile, so an account that can buy a pass and
     * nothing else still has a shop to go to.
     */
    public static function canAccess(): bool
    {
        if (!static::creditsEnabled()) {
            return false;
        }

        return RoleResourceLimits::forUser(auth()->user()) !== null
            || static::sellsPriority();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::creditsEnabled() && parent::shouldRegisterNavigation();
    }

    /**
     * The master switch for the credit half of the economy.
     *
     * Turning it off hides the store rather than emptying it: balances and purchased
     * resources are untouched, so flipping it back leaves everyone exactly where they
     * were. PerkDiscovery reads the same key, which is why the "spend credits" perk
     * disappears at the same moment the page it links to does.
     */
    protected static function creditsEnabled(): bool
    {
        return (bool) config('plugged-host-economy.credits.enabled', true);
    }

    protected function getHeaderWidgets(): array
    {
        return array_values(array_filter([
            class_exists(UserResourceLimitsOverview::class) ? UserResourceLimitsOverview::class : null,
            // Somebody about to spend credits on RAM should first be told they can have some
            // for free. Disappears once there is no unclaimed perk left. Guarded because the
            // widget lives in plugged-host-core — a missing core should drop the widget, not
            // 500 the store page.
            class_exists(PerkDiscoveryWidget::class) ? PerkDiscoveryWidget::class : null,
        ]));
    }

    public function buyCpu(int $amount, ResourceStoreService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $result = $service->purchaseCpu($user, $amount);

        if ($result['success']) {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_successful'))
                ->body($result['message'])
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_failed'))
                ->body($result['message'])
                ->danger()
                ->send();
        }
    }

    public function buyMemory(int $amount, ResourceStoreService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $result = $service->purchaseMemory($user, $amount);

        if ($result['success']) {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_successful'))
                ->body($result['message'])
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_failed'))
                ->body($result['message'])
                ->danger()
                ->send();
        }
    }

    public function buyDisk(int $amount, ResourceStoreService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $result = $service->purchaseDisk($user, $amount);

        if ($result['success']) {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_successful'))
                ->body($result['message'])
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_failed'))
                ->body($result['message'])
                ->danger()
                ->send();
        }
    }

    public function buyServerSlot(int $slots, ResourceStoreService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $result = $service->purchaseServerSlot($user, $slots);

        if ($result['success']) {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_successful'))
                ->body($result['message'])
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans('plugged-host-economy::strings.credits.purchase_failed'))
                ->body($result['message'])
                ->danger()
                ->send();
        }
    }

    /**
     * Buy a priority window with tokens.
     *
     * The tier arrives from the browser, so it is not trusted: the pass service
     * normalises anything it does not recognise — and anything it has stopped
     * selling — down to the cheap tier at the cheap price, which is why there is no
     * validation of `$tier` here.
     *
     * Charging happens over in the queue plugin, tokens and all. This method's whole
     * job is to turn the four result strings into something a customer can read.
     */
    public function buyPriority(string $tier = 'standard'): void
    {
        $user = auth()->user();
        $passes = $this->passes();

        if (!$user instanceof User || $passes === null) {
            return;
        }

        try {
            $offer = (array) $passes->describe($tier);
            $result = (string) $passes->purchase($user, $tier);
        } catch (Throwable) {
            // The queue plugin is installed but broken. Nothing was charged: `purchase()`
            // spends before it grants and refunds itself if the grant fails.
            $this->priorityNotice('failed', 'danger');

            return;
        }

        $days = (int) ($offer['days'] ?? 0);
        $adFree = (bool) ($offer['ad_free'] ?? false);

        match ($result) {
            self::PASS_OK => $this->priorityNotice(
                $adFree ? 'bought_ad_free' : 'bought',
                'success',
                ['days' => $days],
            ),
            self::PASS_SHORT => $this->priorityNotice('short', 'warning', [
                'cost' => number_format((int) ($offer['cost'] ?? 0)),
            ]),
            self::PASS_UNAVAILABLE => $this->priorityNotice('unavailable', 'warning'),
            default => $this->priorityNotice('failed', 'danger'),
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    protected function priorityNotice(string $key, string $type, array $replace = []): void
    {
        $notification = Notification::make()
            ->title(trans("plugged-host-economy::strings.priority.notify_{$key}", $replace));

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
        /** @var ResourceStoreService $storeService */
        $storeService = app(ResourceStoreService::class);

        $summary = $user ? $storeService->getUserResourceSummary($user) : [];
        $userCredits = $user ? UserCredits::forUser($user) : null;
        $transactions = $userCredits ? $userCredits->transactions()->take(10)->get() : collect([]);

        $discordProfileUrl = env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space') . '/dashboard';

        $memoryUnit = config('panel.use_binary_prefix') ? 'MiB' : 'MB';

        $upgrades = $summary === [] ? [] : $this->buildUpgradeCards($summary, $storeService, $memoryUnit);

        /** @var TokenService $tokens */
        $tokens = app(TokenService::class);

        $tokenBalance = $tokens->balance($user);

        return [
            'summary' => $summary,
            'credits' => $summary['user_credits'] ?? 0,
            'userCredits' => $userCredits,
            'transactions' => $transactions,
            'discordProfileUrl' => $discordProfileUrl,
            'memoryUnit' => $memoryUnit,
            'upgrades' => $upgrades,
            'upgradeGridClass' => $this->upgradeGridClass($upgrades),
            'tokensEnabled' => $tokens->isEnabled(),
            'tokens' => $tokenBalance,
            'earnTokensUrl' => $this->earnTokensUrl(),
            'priority' => $this->priorityData($user, $tokenBalance),
        ];
    }

    /**
     * The priority section: what is on offer, and what this account already holds.
     *
     * Null when there is nothing to say — the queue plugin is not installed, or it has
     * stopped selling passes and this account is not inside one. An account *is* inside
     * one, the receipt renders even when selling is off, because a window that was paid
     * for keeps running after the offer is withdrawn.
     *
     * @return array<string, mixed>|null
     */
    protected function priorityData(mixed $user, int $tokenBalance): ?array
    {
        $passes = $this->passes();

        if ($passes === null || !$user instanceof User) {
            return null;
        }

        try {
            $sellable = (bool) $passes->available();
            $active = (bool) $passes->isActive($user);

            if (!$sellable && !$active) {
                return null;
            }

            return [
                'sellable' => $sellable,
                'active' => $active,
                'remaining' => $this->humanDuration((int) $passes->remainingSeconds($user)),
                'adFree' => (bool) $passes->isAdFree($user),
                'adFreeRemaining' => $this->humanDuration((int) $passes->adFreeRemainingSeconds($user)),
                'tiers' => $sellable ? $this->priorityTiers($passes, $tokenBalance) : [],
            ];
        } catch (Throwable) {
            // A pass service that cannot answer costs this page one section, not the
            // credits economy underneath it.
            return null;
        }
    }

    /**
     * The tier cards, cheapest first, straight from the queue's own description of
     * what it sells — so the price here cannot drift from the price it charges.
     *
     * @return list<array<string, mixed>>
     */
    protected function priorityTiers(mixed $passes, int $tokenBalance): array
    {
        $tiers = [];

        foreach ((array) $passes->tiers() as $tier) {
            $cost = (int) ($tier['cost'] ?? 0);

            $tiers[] = [
                'key' => (string) ($tier['key'] ?? 'standard'),
                'days' => (int) ($tier['days'] ?? 0),
                'cost' => $cost,
                'ad_free' => (bool) ($tier['ad_free'] ?? false),
                'unaffordable' => $tokenBalance < $cost,
            ];
        }

        return $tiers;
    }

    /**
     * The queue's pass service, or null when that plugin is absent.
     *
     * Same reasoning as `earnTokensUrl()` below: by name, behind `class_exists()`, so
     * the store is not held hostage by a plugin it does not require.
     */
    protected function passes(): mixed
    {
        $class = self::QUEUE_PASSES;

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
     * Can a pass be sold right now? Asked statically from `canAccess()`, which Filament
     * re-runs on every request, so it must stay a config read and a container hit.
     */
    protected static function sellsPriority(): bool
    {
        $class = self::QUEUE_PASSES;

        if (!class_exists($class)) {
            return false;
        }

        try {
            return (bool) app($class)->available();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * "2 days 4 hours" rather than 187,412 seconds. Two parts, so a window in its last
     * hour still reads as a window instead of a stopwatch.
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

    /**
     * Link to where tokens are earned, which lives in plugged-host-ads.
     *
     * Resolved by name through `class_exists()` rather than an import: the token
     * ledger belongs to this plugin but earning does not, and a store page that
     * 500s because the ads plugin was disabled would take the credit economy down
     * with it. Null simply hides the link.
     */
    protected function earnTokensUrl(): ?string
    {
        $page = 'PluggedHost\\Economy\\Filament\\App\\Pages\\EarnTokensPage';

        try {
            return class_exists($page) && $page::canAccess()
                ? $page::getUrl(panel: 'app')
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Presentation model for the four upgrade cards.
     *
     * Accents use Filament's semantic colour tokens rather than raw Tailwind
     * hues, so they resolve through the panel's palette and stay in step with
     * the Nord theme instead of hard-coding a second set of colours.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    protected function buildUpgradeCards(array $summary, ResourceStoreService $storeService, string $memoryUnit): array
    {
        $credits = (int) ($summary['user_credits'] ?? 0);

        $tier = function (string $label, int $amount, string $method, int $price, bool $available) use ($credits): array {
            return [
                'label' => $label,
                'amount' => $amount,
                'method' => $method,
                'price' => $price,
                'disabled' => !$available || $credits < $price,
                'unaffordable' => $credits < $price,
            ];
        };

        $canBuyCpu = (bool) ($summary['can_buy_cpu'] ?? false);
        $canBuyMemory = (bool) ($summary['can_buy_memory'] ?? false);
        $canBuyDisk = (bool) ($summary['can_buy_disk'] ?? false);

        $serverLimit = $summary['server_limit'] ?? null;

        $cards = [
            [
                'key' => 'cpu',
                'icon' => 'tabler-cpu',
                'accent' => 'text-primary-600 dark:text-primary-400',
                'bar' => 'bg-primary-500',
                'label' => trans('plugged-host-core::strings.cpu'),
                'description' => trans('plugged-host-economy::strings.credits.cpu_upgrade_desc'),
                'current' => $summary['cpu'] . '%',
                'max' => $summary['max_cpu'] . '%',
                'percent' => $this->percentOf($summary['cpu'], $summary['max_cpu']),
                'at_max' => !$canBuyCpu && $summary['cpu'] >= $summary['max_cpu'],
                'tiers' => [
                    $tier('+50% CPU', 50, 'buyCpu', $storeService->getPriceForCpu(50), $canBuyCpu),
                    $tier('+100% CPU', 100, 'buyCpu', $storeService->getPriceForCpu(100), $canBuyCpu),
                ],
            ],
            [
                'key' => 'memory',
                'icon' => 'tabler-device-sd-card',
                'accent' => 'text-info-600 dark:text-info-400',
                'bar' => 'bg-info-500',
                'label' => trans('plugged-host-core::strings.memory'),
                'description' => trans('plugged-host-economy::strings.credits.memory_upgrade_desc'),
                'current' => number_format($summary['memory']) . ' ' . $memoryUnit,
                'max' => number_format($summary['max_memory']) . ' ' . $memoryUnit,
                'percent' => $this->percentOf($summary['memory'], $summary['max_memory']),
                'at_max' => !$canBuyMemory && $summary['memory'] >= $summary['max_memory'],
                'tiers' => [
                    $tier('+1 GB RAM', 1024, 'buyMemory', $storeService->getPriceForMemory(1024), $canBuyMemory),
                    $tier('+2 GB RAM', 2048, 'buyMemory', $storeService->getPriceForMemory(2048), $canBuyMemory),
                ],
            ],
            [
                'key' => 'disk',
                'icon' => 'tabler-database',
                'accent' => 'text-success-600 dark:text-success-400',
                'bar' => 'bg-success-500',
                'label' => trans('plugged-host-core::strings.disk'),
                'description' => trans('plugged-host-economy::strings.credits.disk_upgrade_desc'),
                'current' => number_format($summary['disk']) . ' ' . $memoryUnit,
                'max' => number_format($summary['max_disk']) . ' ' . $memoryUnit,
                'percent' => $this->percentOf($summary['disk'], $summary['max_disk']),
                'at_max' => !$canBuyDisk && $summary['disk'] >= $summary['max_disk'],
                'tiers' => [
                    $tier('+5 GB Disk', 5120, 'buyDisk', $storeService->getPriceForDisk(5120), $canBuyDisk),
                    $tier('+10 GB Disk', 10240, 'buyDisk', $storeService->getPriceForDisk(10240), $canBuyDisk),
                ],
            ],
        ];

        // Nothing to sell, and nothing meaningful to show, when slots are unlimited.
        if ($serverLimit !== null) {
            $cards[] = [
                'key' => 'server_slot',
                'icon' => 'tabler-server',
                'accent' => 'text-warning-600 dark:text-warning-400',
                'bar' => 'bg-warning-500',
                'label' => trans('plugged-host-core::strings.server_limit'),
                'description' => trans('plugged-host-economy::strings.credits.server_slot_upgrade_desc'),
                'current' => $serverLimit . ' ' . trans('plugged-host-economy::strings.credits.servers_count'),
                'max' => null,
                'percent' => null,
                'at_max' => false,
                'tiers' => [
                    $tier(
                        '+1 ' . trans('plugged-host-economy::strings.credits.server_slot'),
                        1,
                        'buyServerSlot',
                        $storeService->getPriceForServerSlot(1),
                        true,
                    ),
                ],
            ];
        }

        return $cards;
    }

    /**
     * Grid columns for the upgrade cards. Spelled out as whole class strings so
     * Tailwind can see them while scanning this file — an interpolated count
     * would compile to nothing.
     *
     * @param  list<array<string, mixed>>  $upgrades
     */
    protected function upgradeGridClass(array $upgrades): string
    {
        return count($upgrades) >= 4
            ? 'sm:grid-cols-2 xl:grid-cols-4'
            : 'sm:grid-cols-2 xl:grid-cols-3';
    }

    protected function percentOf(int|float|null $current, int|float|null $max): int
    {
        if (!$max || $max <= 0) {
            return 0;
        }

        return (int) max(0, min(100, round(((float) $current / (float) $max) * 100)));
    }
}
