<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\MembershipService;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * plugged.host Plus: the page that sells it, and nothing else. Plus is bought once with tokens
 * (the Plus locations, priority in the start queue, no in-game ads); PlusPlus is its add-on,
 * bought for a few days at a time with tokens or credits (the Premium locations, no ads
 * anywhere, the front of the queue, more CPU). MembershipService does the selling.
 *
 * Replaced the Token Store (slug `token-store`, which redirects here). Earning tokens and the
 * token history live on the Tokens page (EarnTokensPage, `/tokens`).
 */
class PlusPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-sparkles';

    protected static ?string $slug = 'plus';

    protected static ?int $navigationSort = 16;

    protected string $view = 'plugged-host-economy::filament.pages.plus';

    public function getTitle(): string
    {
        return trans('plugged-host-economy::strings.plus_page.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-economy::strings.plus_page.nav_label');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-economy::strings.plus_page.subheading');
    }

    public static function canAccess(): bool
    {
        return (bool) config('plugged-host-economy.membership.enabled', true) && parent::canAccess();
    }

    public function buyPlus(MembershipService $membership): void
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        $result = $membership->buyPlus($user);

        match ($result) {
            MembershipService::RESULT_OK, MembershipService::RESULT_OWNED => $this->notice('membership.plus_bought', 'success'),
            MembershipService::RESULT_SHORT => $this->notice('membership.short_tokens', 'warning', ['cost' => number_format($membership->plusCost())]),
            MembershipService::RESULT_UNAVAILABLE => $this->notice('membership.unavailable', 'warning'),
            default => $this->notice('membership.failed', 'danger'),
        };

        if ($result === MembershipService::RESULT_OK) {
            $this->stampQueue($user);
        }
    }

    /**
     * Buy or extend PlusPlus. The way of paying comes from the browser and is checked by the
     * service: anything but tokens or credits is refused.
     */
    public function buyPlusPlus(string $pay, MembershipService $membership): void
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        $result = $membership->buyPlusPlus($user, $pay);
        $offer = $membership->plusPlusOffer($pay);

        match ($result) {
            MembershipService::RESULT_OK => $this->notice('membership.plusplus_bought', 'success', [
                'until' => $membership->plusPlusUntil($user)?->timezone($user->timezone ?? config('app.timezone'))->isoFormat('LLL') ?? '',
            ]),
            MembershipService::RESULT_NEEDS_PLUS => $this->notice('membership.needs_plus', 'warning'),
            MembershipService::RESULT_SHORT => $this->notice($pay === MembershipService::PAY_CREDITS ? 'membership.short_credits' : 'membership.short_tokens', 'warning', ['cost' => number_format($offer['cost'])]),
            MembershipService::RESULT_UNAVAILABLE => $this->notice('membership.unavailable', 'warning'),
            default => $this->notice('membership.failed', 'danger'),
        };

        if ($result === MembershipService::RESULT_OK) {
            $this->stampQueue($user);
        }
    }

    /**
     * Servers already waiting in the start queue move up now, not on their next start.
     */
    protected function stampQueue(User $user): void
    {
        $passes = 'PluggedHost\\Queue\\Services\\PriorityPassService';

        if (Plugins::available($passes)) {
            try {
                app($passes)->stampWaiting($user);
            } catch (Throwable) {
                // The next start picks the new level up anyway.
            }
        }
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
        $tokens = (int) app(TokenService::class)->balance($user);

        return [
            'tokens' => $tokens,
            'earnTokensUrl' => $this->earnTokensUrl(),
            'membership' => $user instanceof User ? $this->membershipData($user, $tokens) : null,
        ];
    }

    /**
     * Plus and PlusPlus as the view shows them, or null when they are not on sale.
     *
     * @return array<string, mixed>|null
     */
    protected function membershipData(User $user, int $tokens): ?array
    {
        $membership = app(MembershipService::class);

        if (!$membership->enabled()) {
            return null;
        }

        $credits = (int) (UserCredits::forUser($user)?->credits ?? 0);
        $hasPlus = $membership->hasPlus($user);
        $until = $membership->plusPlusUntil($user);
        $plusCost = $membership->plusCost();
        $offers = [];

        foreach ([MembershipService::PAY_TOKENS, MembershipService::PAY_CREDITS] as $pay) {
            if ($pay === MembershipService::PAY_CREDITS && !config('plugged-host-economy.credits.enabled', true)) {
                continue;
            }

            $offer = $membership->plusPlusOffer($pay);
            $offers[] = $offer + [
                'pay' => $pay,
                'unaffordable' => ($pay === MembershipService::PAY_CREDITS ? $credits : $tokens) < $offer['cost'],
            ];
        }

        return [
            'hasPlus' => $hasPlus,
            'plusCost' => $plusCost,
            'plusUnaffordable' => $tokens < $plusCost,
            'plusPlusActive' => $until !== null,
            'plusPlusUntil' => $until?->timezone($user->timezone ?? config('app.timezone'))->isoFormat('LLL'),
            'cpuBonus' => max(0, (int) config('plugged-host-economy.membership.plusplus_cpu_bonus', 50)),
            'plusPlusTokenCost' => $membership->plusPlusOffer(MembershipService::PAY_TOKENS)['cost'],
            // neo (AI) usage over the free limits, in %: Plus, and PlusPlus on top of Plus.
            'aiPlus' => max(0, (int) config('plugged-host-economy.membership.plus_ai_percent', 125) - 100),
            'aiPlusPlus' => max(0, (int) config('plugged-host-economy.membership.plusplus_ai_percent', 125) - 100),
            'aiPlusPlusTotal' => max(0, intdiv(max(100, (int) config('plugged-host-economy.membership.plus_ai_percent', 125)) * max(100, (int) config('plugged-host-economy.membership.plusplus_ai_percent', 125)), 100) - 100),
            'offers' => $offers,
            'credits' => $credits,
        ];
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
