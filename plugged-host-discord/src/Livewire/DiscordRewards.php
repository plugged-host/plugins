<?php

namespace PluggedHost\Discord\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Contracts\DiscordServersStatus;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Economy\Models\CreditTransaction;
use Throwable;

/**
 * The "Discord rewards" tab on the profile page: what inviting friends to the plugged.host
 * Discord server and boosting any of the Discord servers has earned, what is still on its way,
 * and the rules. Boosts are noprofit.space's (DiscordServersStatus) when it is set up.
 *
 * Linking the Discord account is not here: that is the account portal's (AccountPortal: the
 * panel's Discord OAuth, or noprofit.space with plugged-host-noprofit), which mirrors the
 * link to the panel. Without a link the tab explains that and points there.
 *
 * Everything shown is read from the panel's own reward tables, the same numbers the gateway
 * daemon and the hourly sync pay from; "Check for new rewards" runs that sync for this one
 * account (at most once a minute).
 */
class DiscordRewards extends Component
{
    public const TAB_ID = 'discord-rewards';

    /** Ledger types shown in the history: Discord rewards and giveaways paid on the panel. */
    private const HISTORY_TYPES = ['discord_invite', 'discord_boost', 'discord_invite_clawback', 'discord_invite_debt', 'giveaway'];

    /** Ledger types that make up "earned on Discord". */
    private const EARNED_TYPES = ['discord_invite', 'discord_boost', 'discord_invite_clawback'];

    /**
     * The tab's address (the profile page opened on it), or null while Discord is not set up
     * and the tab is hidden. For the Resource Store's "Earn credits on Discord" and the perks.
     */
    public static function url(): ?string
    {
        if (!app(DiscordVerificationService::class)->isConfigured()) {
            return null;
        }

        try {
            $profile = Filament::getPanel('app')->getProfileUrl();
        } catch (Throwable) {
            return null;
        }

        return $profile !== null ? $profile . '?tab=' . self::TAB_ID : null;
    }

    public function checkNow(DiscordVerificationService $discord): void
    {
        $user = auth()->user();
        $link = $user instanceof User ? DiscordLink::query()->where('user_id', $user->getKey())->first() : null;

        if (!$user instanceof User || !filled($link?->discord_id)) {
            return;
        }

        $key = 'plugged-host-discord:rewards-check:' . $user->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            Notification::make()
                ->title(trans('plugged-host-discord::strings.rewards.check_wait', ['seconds' => RateLimiter::availableIn($key)]))
                ->warning()
                ->send();

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $credits = (int) ($discord->syncRewards($user, $link)['total_credits'] ?? 0);
            // Boosts on any of the Discord servers are paid by noprofit.space.
            $credits += (int) (app(DiscordServersStatus::class)->forUser($user, check: true)['paid'] ?? 0);
        } catch (Throwable $e) {
            report($e);

            Notification::make()->title(trans('plugged-host-discord::strings.rewards.check_failed'))->danger()->send();

            return;
        }

        Notification::make()
            ->title($credits > 0
                ? trans('plugged-host-discord::strings.rewards.check_paid', ['credits' => number_format($credits)])
                : trans('plugged-host-discord::strings.rewards.check_nothing'))
            ->success()
            ->send();
    }

    public function render(DiscordVerificationService $discord): View
    {
        $user = auth()->user();
        $link = $user instanceof User ? DiscordLink::query()->where('user_id', $user->getKey())->first() : null;
        $discordId = filled($link?->discord_id) ? (string) $link->discord_id : null;

        $perInvite = $discord->getCreditsPerInvite();

        return view('plugged-host-discord::livewire.rewards', [
            'linked' => $discordId !== null,
            'username' => $link?->discord_username,
            'inGuild' => (bool) $link?->is_in_guild,
            'inviteUrl' => (string) config('plugged-host-discord.invite_url'),
            'linkUrl' => app(AccountPortal::class)->discordLinkUrl(),
            'linkElsewhere' => app(AccountPortal::class)->external() ? app(AccountPortal::class)->name() : null,
            'perInvite' => $perInvite,
            'maturityHours' => $discord->getInviteMaturityHours(),
            'minAgeDays' => $discord->getMinAccountAgeDays(),
            'clawback' => $discord->inviteClawbackEnabled(),
            'invites' => $discordId !== null ? $this->invites($discord, $discordId, $link) : null,
            'inviteCredits' => $user instanceof User ? $this->sumOf($user, ['discord_invite']) : 0,
            'boosts' => $this->boosts($discord, $user instanceof User ? $user : null, $link),
            'earned' => $user instanceof User ? $this->sumOf($user, self::EARNED_TYPES) : 0,
            'history' => $user instanceof User ? $this->history($user) : collect(),
        ]);
    }

    /**
     * Boosts and their levels. With noprofit.space they are its numbers: boosts on any of its
     * Discord servers add up there, and it pays the levels. Without it, plugged.host's own.
     *
     * @return array{boosting: bool, count: int, servers: ?string, levels: list<array{boosts: int, credits: int, total: int, received: bool}>}
     */
    private function boosts(DiscordVerificationService $discord, ?User $user, ?DiscordLink $link): array
    {
        $status = $user !== null ? app(DiscordServersStatus::class)->forUser($user) : null;

        if ($status !== null) {
            $levels = [];
            $previous = 0;

            foreach ($status['boost_credits'] as $boosts => $total) {
                $levels[] = ['boosts' => (int) $boosts, 'credits' => max(0, $total - $previous), 'total' => $total, 'received' => (int) $status['boosts_rewarded'] >= $boosts];
                $previous = $total;
            }

            return [
                'boosting' => (int) $status['boosts'] > 0,
                'count' => (int) $status['boosts'],
                'servers' => collect($status['servers'])->pluck('name')->implode(', '),
                'levels' => $levels,
            ];
        }

        $boostOne = $discord->getCreditsPerBoost1();
        $boostTwo = max($boostOne, $discord->getCreditsPerBoost2());

        return [
            'boosting' => (bool) $link?->is_boosting,
            'count' => (int) ($link?->boost_count ?? 0),
            'servers' => null,
            'levels' => [
                ['boosts' => 1, 'credits' => $boostOne, 'total' => $boostOne, 'received' => (int) ($link?->boosts_rewarded ?? 0) >= 1],
                ['boosts' => 2, 'credits' => $boostTwo - $boostOne, 'total' => $boostTwo, 'received' => (int) ($link?->boosts_rewarded ?? 0) >= 2],
            ],
        ];
    }

    /**
     * @return array{paid: int, waiting: int, next_payout: ?Carbon, left: int, too_new: int, debt: int, legacy: int}
     */
    private function invites(DiscordVerificationService $discord, string $discordId, ?DiscordLink $link): array
    {
        $counts = $discord->inviteCountsFor($discordId);

        $next = DiscordInvitedMember::query()
            ->where('inviter_discord_id', $discordId)
            ->counted()
            ->where('is_rewarded', false)
            ->whereNotNull('matured_at')
            ->min('matured_at');

        return [
            'paid' => (int) $counts['rewarded'],
            'waiting' => (int) $counts['pending'],
            'next_payout' => $next !== null ? Carbon::parse($next) : null,
            'left' => (int) $counts['left'],
            'too_new' => (int) $counts['ineligible'],
            'debt' => (int) ($link?->invite_debt ?? 0),
            'legacy' => $discord->legacyInviteCountFor($discordId),
        ];
    }

    /** @param  list<string>  $types */
    private function sumOf(User $user, array $types): int
    {
        if (!Plugins::available(CreditTransaction::class)) {
            return 0;
        }

        return (int) CreditTransaction::query()
            ->where('user_id', $user->getKey())
            ->whereIn('type', $types)
            ->sum('amount');
    }

    private function history(User $user): Collection
    {
        if (!Plugins::available(CreditTransaction::class)) {
            return collect();
        }

        return CreditTransaction::query()
            ->where('user_id', $user->getKey())
            ->whereIn('type', self::HISTORY_TYPES)
            ->latest('created_at')
            ->latest('id')
            ->limit(15)
            ->get();
    }
}
