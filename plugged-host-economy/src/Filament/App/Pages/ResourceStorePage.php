<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Livewire\DiscordRewards;
use PluggedHost\Economy\Filament\App\Widgets\PerkDiscoveryWidget;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\ResourceStoreService;
use PluggedHost\Servers\Support\AccountAllowance;

/**
 * The Resource Store: the credit balance and the resources credits buy -- CPU, memory and
 * disk, kept for good. Tokens and what they buy are the Token Store's (TokenStorePage); the
 * two currencies are not interchangeable.
 *
 * The class and the `store` slug are the ones the single store page had, so bookmarks, the
 * perk card and the sidebar keep working.
 *
 * Each resource is one row with one meter for three numbers: what the account's servers use,
 * its limit (the plan on noprofit.space, bought resources included), and the soft limit, where
 * prices get steep. Nothing caps an account: every step is priced on noprofit.space's curve
 * (ComputePricing), cheap at first and dearer the more the account has.
 */
class ResourceStorePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-shopping-bag';

    protected static ?string $slug = 'store';

    protected static ?int $navigationSort = 15;

    protected string $view = 'plugged-host-economy::filament.pages.resource-store';

    /**
     * The offers per resource, in steps (the same as noprofit.space's store).
     */
    public const OFFER_STEPS = [1, 2, 4];

    public function getTitle(): string
    {
        return trans('plugged-host-economy::strings.resource_store.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-economy::strings.resource_store.nav_label');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-economy::strings.resource_store.subheading');
    }

    /**
     * Turning credits off hides the store rather than emptying it: balances and bought
     * resources are kept, so switching it back on leaves everyone where they were.
     */
    public static function canAccess(): bool
    {
        return (bool) config('plugged-host-economy.credits.enabled', true) && parent::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && parent::shouldRegisterNavigation();
    }

    protected function getHeaderWidgets(): array
    {
        // Somebody about to spend credits on RAM should first be told they can have some for
        // free (the Discord tier). Disappears once there is nothing left to claim.
        return Plugins::available(PerkDiscoveryWidget::class) ? [PerkDiscoveryWidget::class] : [];
    }

    public function buyCpu(int $amount, ResourceStoreService $service): void
    {
        $this->purchased(fn (User $user) => $service->purchaseCpu($user, $amount));
    }

    public function buyMemory(int $amount, ResourceStoreService $service): void
    {
        $this->purchased(fn (User $user) => $service->purchaseMemory($user, $amount));
    }

    public function buyDisk(int $amount, ResourceStoreService $service): void
    {
        $this->purchased(fn (User $user) => $service->purchaseDisk($user, $amount));
    }

    /**
     * @param  callable(User): array{success: bool, message: string}  $purchase
     */
    protected function purchased(callable $purchase): void
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        $result = $purchase($user);

        Notification::make()
            ->title(trans($result['success']
                ? 'plugged-host-economy::strings.credits.purchase_successful'
                : 'plugged-host-economy::strings.credits.purchase_failed'))
            ->body($result['message'])
            ->status($result['success'] ? 'success' : 'danger')
            ->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();

        /** @var ResourceStoreService $store */
        $store = app(ResourceStoreService::class);

        $userCredits = $user instanceof User ? UserCredits::forUser($user) : null;

        return [
            'credits' => (int) ($userCredits?->credits ?? 0),
            // "Earn credits on Discord": the Discord rewards tab on the profile page.
            'rewardsUrl' => Plugins::available(DiscordRewards::class) ? DiscordRewards::url() : null,
            // No plan yet (noprofit.space has not synced this account): nothing to show or sell.
            'resources' => $user instanceof User && AccountAllowance::forUser($user) !== null
                ? $this->resources($user, (int) ($userCredits?->credits ?? 0), $store)
                : [],
            'transactions' => $userCredits ? $userCredits->transactions()->take(10)->get() : collect(),
        ];
    }

    /**
     * One row per resource: the meter's three numbers, how they read, and what can be bought.
     * Prices are asked for again from noprofit.space when the ones here are a minute old.
     *
     * @return list<array<string, mixed>>
     */
    protected function resources(User $user, int $credits, ResourceStoreService $store): array
    {
        $allowance = AccountAllowance::forUser($user);
        $usage = AccountAllowance::getUsage($user);
        $pricing = $store->pricing(60);
        $sells = $pricing !== null && $store->sellsComputeTo($user);
        $percent = fn (int $n): string => $n . '%';
        $size = fn (int $mb): string => $this->size($mb);

        $rows = [];

        foreach ([
            'cpu' => [trans('plugged-host-core::strings.cpu'), $percent, 'buyCpu'],
            'memory' => [trans('plugged-host-core::strings.memory'), $size, 'buyMemory'],
            'disk' => [trans('plugged-host-core::strings.disk'), $size, 'buyDisk'],
        ] as $key => [$label, $format, $method]) {
            $limit = (int) ($allowance?->{$key} ?? 0);
            $used = (int) ($usage[$key] ?? 0);
            $current = $store->current($user, $key);
            $priceLimit = $store->limit($user, $key);
            $soft = $pricing?->softLimit($priceLimit) ?? 0;
            $step = $sells ? $pricing->step($key) : 0;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'meter' => $this->meter($used, $limit, $soft),
                'used' => $format($used),
                'limit' => $format($limit),
                'over' => $used > $limit ? $format($used - $limit) : null,
                'price_note' => $soft <= 0 ? null : match (true) {
                    $current >= $soft => trans('plugged-host-economy::strings.resource_store.price_steep', ['soft' => $format($soft)]),
                    $current >= $priceLimit => trans('plugged-host-economy::strings.resource_store.price_climbing', ['limit' => $format($priceLimit), 'soft' => $format($soft)]),
                    default => trans('plugged-host-economy::strings.resource_store.price_rising', ['limit' => $format($priceLimit)]),
                },
                'offers' => $step <= 0 ? [] : array_map(function (int $steps) use ($pricing, $key, $step, $current, $priceLimit, $method, $format, $credits): array {
                    $price = $pricing->cost($key, $current, $steps * $step, $priceLimit);

                    return [
                        'label' => '+' . $format($steps * $step),
                        'amount' => $steps * $step,
                        'method' => $method,
                        'price' => $price,
                        'disabled' => $credits < $price,
                    ];
                }, self::OFFER_STEPS),
            ];
        }

        return $rows;
    }

    /**
     * Widths, in % of the track, of the used part and of the limit behind it. The track runs to
     * the soft limit, where prices get steep (further when the account is past it); a limit of 0
     * on the panel means unlimited.
     *
     * @return array{used: float, limit: float, over: bool}
     */
    protected function meter(int $used, int $limit, int $soft): array
    {
        $track = max($soft, $limit, $used, 1);

        return [
            'used' => round(min($used, $track) / $track * 100, 1),
            'limit' => round(min($limit, $track) / $track * 100, 1),
            'over' => $limit > 0 && $used > $limit,
        ];
    }

    /**
     * Megabytes as the panel shows sizes elsewhere: "4 GB", "1.5 GB", "512 MB".
     */
    protected function size(int $mb): string
    {
        $binary = (bool) config('panel.use_binary_prefix');

        if ($mb < 1024) {
            return $mb . ($binary ? ' MiB' : ' MB');
        }

        return rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ($binary ? ' GiB' : ' GB');
    }
}
