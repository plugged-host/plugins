<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Core\Filament\App\Widgets\PerkDiscoveryWidget;
use PluggedHost\Discord\Livewire\DiscordRewards;
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
 * its limit (the plan on noprofit.space, bought resources included), and the most it can buy
 * up to. That replaces the "used / limit" stat tiles that sat above the old store.
 */
class ResourceStorePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-shopping-bag';

    protected static ?string $slug = 'store';

    protected static ?int $navigationSort = 15;

    protected string $view = 'plugged-host-economy::filament.pages.resource-store';

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
        return class_exists(PerkDiscoveryWidget::class) ? [PerkDiscoveryWidget::class] : [];
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

        // No plan yet (noprofit.space has not synced this account): nothing to show or sell.
        $summary = $user instanceof User && AccountAllowance::forUser($user) !== null
            ? $store->getUserResourceSummary($user)
            : null;
        $userCredits = $user instanceof User ? UserCredits::forUser($user) : null;

        return [
            'credits' => (int) ($userCredits?->credits ?? 0),
            // "Earn credits on Discord": the Discord rewards tab on the profile page.
            'rewardsUrl' => class_exists(DiscordRewards::class) ? DiscordRewards::url() : null,
            'resources' => $summary !== null && $user instanceof User ? $this->resources($user, $summary, $store) : [],
            'transactions' => $userCredits ? $userCredits->transactions()->take(10)->get() : collect(),
        ];
    }

    /**
     * One row per resource: the meter's three numbers, how they read, and what can be bought.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array<string, mixed>>
     */
    protected function resources(User $user, array $summary, ResourceStoreService $store): array
    {
        $usage = AccountAllowance::getUsage($user);
        $credits = (int) ($summary['user_credits'] ?? 0);
        $percent = fn (int $n): string => $n . '%';
        $size = fn (int $mb): string => $this->size($mb);

        $offer = function (string $label, int $amount, string $method, int $price, bool $available) use ($credits): array {
            return [
                'label' => $label,
                'amount' => $amount,
                'method' => $method,
                'price' => $price,
                'disabled' => !$available || $credits < $price,
            ];
        };

        $rows = [];

        foreach ([
            'cpu' => [trans('plugged-host-core::strings.cpu'), $percent, 'buyCpu', fn (int $n) => $store->getPriceForCpu($n)],
            'memory' => [trans('plugged-host-core::strings.memory'), $size, 'buyMemory', fn (int $n) => $store->getPriceForMemory($n)],
            'disk' => [trans('plugged-host-core::strings.disk'), $size, 'buyDisk', fn (int $n) => $store->getPriceForDisk($n)],
        ] as $key => [$label, $format, $method, $price]) {
            $limit = (int) ($summary[$key] ?? 0);
            $cap = max($limit, (int) ($summary["max_{$key}"] ?? 0));
            $used = (int) ($usage[$key] ?? 0);
            $step = $store->step($key);
            $canBuy = (bool) ($summary["can_buy_{$key}"] ?? false);

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'meter' => $this->meter($used, $limit, $cap),
                'used' => $format($used),
                'limit' => $format($limit),
                'cap' => $format($cap),
                'over' => $used > $limit ? $format($used - $limit) : null,
                'at_cap' => $limit > 0 && $limit >= $cap,
                'offers' => $step > 0 ? [
                    $offer('+' . $format($step), $step, $method, $price($step), $canBuy),
                    $offer('+' . $format($step * 2), $step * 2, $method, $price($step * 2), $canBuy),
                ] : [],
            ];
        }

        return $rows;
    }

    /**
     * Widths, in % of the track, of the used part and of the limit behind it. The track is the
     * cap (the most that can be bought); a limit of 0 on the panel means unlimited.
     *
     * @return array{used: float, limit: float, over: bool}
     */
    protected function meter(int $used, int $limit, int $cap): array
    {
        $track = max($cap, $limit, $used, 1);

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
