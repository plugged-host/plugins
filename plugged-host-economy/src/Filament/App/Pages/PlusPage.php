<?php

namespace PluggedHost\Economy\Filament\App\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Devbox\Support\DevboxAccess;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\MembershipService;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * Plus ("<APP_NAME> Plus"): the page that sells it, and nothing else. Plus is bought once with tokens
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
        return trans('plugged-host-economy::strings.plus_page.title', ['app' => config('app.name')]);
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-economy::strings.plus_page.nav_label', ['app' => config('app.name')]);
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
        $notification = Notification::make()->title(trans("plugged-host-economy::strings.{$key}", $replace + ['app' => config('app.name')]));

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
        $durations = [];

        foreach ([MembershipService::PAY_TOKENS, MembershipService::PAY_CREDITS] as $pay) {
            if ($pay === MembershipService::PAY_CREDITS && !config('plugged-host-economy.credits.enabled', true)) {
                continue;
            }

            $offer = $membership->plusPlusOffer($pay);
            $durations[$pay] = $this->duration($offer['days']);
            $offers[] = $offer + [
                'pay' => $pay,
                'unaffordable' => ($pay === MembershipService::PAY_CREDITS ? $credits : $tokens) < $offer['cost'],
            ];
        }

        // "a week, or credits for two weeks", from the day settings rather than written out.
        $plusPlusTerm = isset($durations[MembershipService::PAY_CREDITS])
            ? trans('plugged-host-economy::strings.plus_page.plusplus_term', [
                'tokens' => $durations[MembershipService::PAY_TOKENS],
                'credits' => $durations[MembershipService::PAY_CREDITS],
            ])
            : trans('plugged-host-economy::strings.plus_page.plusplus_term_tokens', ['tokens' => $durations[MembershipService::PAY_TOKENS]]);

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
            'plusPlusTerm' => $plusPlusTerm,
            'creditDuration' => $this->duration($membership->plusPlusOffer(MembershipService::PAY_CREDITS)['days']),
            'credits' => $credits,
            'devbox' => $this->devboxPerk(),
        ];
    }

    /**
     * VS Code on the server (plugged-host-devbox), the resources each tier's devbox gets, from the
     * devbox settings. Null unless devboxes are switched on and migrated (DevboxAccess::ready()):
     * a perk nobody can use yet is not sold.
     *
     * @return null|array<string, array{cpu: string, memory: string, disk: string}>
     */
    protected function devboxPerk(): ?array
    {
        if (!Plugins::available(DevboxAccess::class) || !DevboxAccess::ready()) {
            return null;
        }

        $perk = [];

        foreach (['plus', 'plusplus'] as $tier) {
            $limits = (array) config("plugged-host-devbox.tiers.{$tier}", []);
            $perk[$tier] = [
                // % of one thread: 200 is 2 vCPU
                'cpu' => rtrim(rtrim(number_format(max(0, (int) ($limits['cpu'] ?? 0)) / 100, 2, '.', ''), '0'), '.'),
                'memory' => $this->formatMebibytes((int) ($limits['memory'] ?? 0)),
                'disk' => $this->formatMebibytes((int) ($limits['disk'] ?? 0)),
            ];
        }

        return $perk;
    }

    /** "a week", "two weeks", "10 days": whole weeks as weeks, anything else in days. */
    private function duration(int $days): string
    {
        return $days % 7 === 0
            ? trans_choice('plugged-host-economy::strings.plus_page.duration_weeks', intdiv($days, 7))
            : trans_choice('plugged-host-economy::strings.plus_page.duration_days', $days);
    }

    /** Like PerkDiscovery: whole GiB/GB when it divides, else MiB/MB. */
    private function formatMebibytes(int $amount): string
    {
        $binary = (bool) config('panel.use_binary_prefix');

        if ($amount > 0 && $amount % 1024 === 0) {
            return intdiv($amount, 1024) . ($binary ? ' GiB' : ' GB');
        }

        return $amount . ($binary ? ' MiB' : ' MB');
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
