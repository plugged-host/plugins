<?php

namespace PluggedHost\Discord\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Component;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;

class DiscordProfileTab extends Component
{
    public function mount(DiscordVerificationService $service): void
    {
        $user = auth()->user();
        if ($user) {
            $link = DiscordLink::where('user_id', $user->id)->first();
            // Automatically verify and sync if user has a linked account
            if ($link && $link->access_token && !$link->is_in_guild) {
                $service->syncUser($user);
            } elseif ($link && !empty($service->getBotToken())) {
                $service->syncRewards($user, $link);
            }
        }
    }

    public function linkDiscord(): mixed
    {
        $url = Socialite::driver('discord')
            ->scopes(['identify', 'email', 'guilds'])
            ->redirect()
            ->getTargetUrl();

        return redirect()->away($url);
    }

    public function verifyMembership(DiscordVerificationService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $result = $service->syncUser($user);

        match ($result['status']) {
            'verified' => Notification::make()
                ->title(trans('plugged-host-discord::strings.verified_title'))
                ->body(trans('plugged-host-discord::strings.verified_body', ['role' => $service->getRoleName()]))
                ->success()
                ->send(),
            'not_in_guild' => Notification::make()
                ->title(trans('plugged-host-discord::strings.not_in_server_title'))
                ->body(trans('plugged-host-discord::strings.not_in_server_body'))
                ->warning()
                ->send(),
            'not_linked' => Notification::make()
                ->title(trans('plugged-host-discord::strings.not_linked_title'))
                ->body(trans('plugged-host-discord::strings.not_linked_body'))
                ->danger()
                ->send(),
            default => Notification::make()
                ->title(trans('plugged-host-discord::strings.error_title'))
                ->body($result['message'] ?? trans('plugged-host-discord::strings.error_body'))
                ->danger()
                ->send(),
        };
    }

    public function unlinkDiscord(DiscordVerificationService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $service->unlinkUser($user);

        Notification::make()
            ->title(trans('plugged-host-discord::strings.unlinked_title'))
            ->body(trans('plugged-host-discord::strings.unlinked_body'))
            ->success()
            ->send();
    }

    public function syncRewards(DiscordVerificationService $service): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        if (empty($service->getBotToken())) {
            Notification::make()
                ->title(trans('plugged-host-discord::strings.bot_token_required_title'))
                ->body(trans('plugged-host-discord::strings.bot_token_required_body'))
                ->warning()
                ->send();

            return;
        }

        $result = $service->syncRewards($user);
        $totalCredits = $result['total_credits'] ?? 0;

        if ($totalCredits > 0) {
            Notification::make()
                ->title(trans('plugged-host-discord::strings.rewards_claimed_title'))
                ->body(trans('plugged-host-discord::strings.rewards_claimed_body', ['credits' => number_format($totalCredits)]))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans('plugged-host-discord::strings.rewards_checked_title'))
                ->body(trans('plugged-host-discord::strings.rewards_checked_body'))
                ->info()
                ->send();
        }
    }

    public function render(DiscordVerificationService $service): View
    {
        $user = auth()->user();
        $discordId = $user ? $service->getLinkedDiscordId($user) : null;
        $link = $user ? DiscordLink::where('user_id', $user->id)->first() : null;
        $roleName = $service->getRoleName();
        $hasRole = $user ? $service->userHasRole($user, $roleName) : false;
        $isConfigured = $service->isConfigured();
        $creditsPerInvite = $service->getCreditsPerInvite();

        // Counts come from the tracked joins, not from the denormalised columns, so the
        // tab agrees with the /invites embed even if a counter refresh is pending. These
        // are plain DB aggregates — no Discord API call on render.
        $counts = $discordId
            ? $service->inviteCountsFor((string) $discordId)
            : ['total' => 0, 'rewarded' => 0, 'pending' => 0, 'ineligible' => 0, 'left' => 0];

        return view('plugged-host-discord::filament.profile.discord-profile-tab-content', [
            'user' => $user,
            'discordId' => $discordId,
            'link' => $link,
            'isLinked' => !empty($discordId),
            'isVerified' => $link?->is_in_guild ?? false,
            'hasRole' => $hasRole,
            'roleName' => $roleName,
            'inviteUrl' => $service->getInviteUrl(),
            'isConfigured' => $isConfigured,
            'hasBotToken' => !empty($service->getBotToken()),
            'inviteCount' => $counts['total'],
            'invitesRewarded' => $counts['rewarded'],
            'pendingInvites' => $counts['pending'],
            'ineligibleInvites' => $counts['ineligible'],
            'leftInvites' => $counts['left'],
            // Pre-tracking invites, shown apart from the counted total because they were
            // never verified and pay nothing. Zero for everyone unless an admin has run
            // plugged-host:discord-invite-baseline.
            'legacyInvites' => $discordId ? $service->legacyInviteCountFor((string) $discordId) : 0,
            'creditsPerInvite' => $creditsPerInvite,
            'creditsFromInvites' => $counts['rewarded'] * $creditsPerInvite,
            'creditsPending' => $counts['pending'] * $creditsPerInvite,
            'inviteDebt' => (int) ($link?->invite_debt ?? 0),
            'maturityHours' => $service->getInviteMaturityHours(),
            'isBoosting' => (bool) ($link?->is_boosting ?? false),
            'boostCount' => ($link?->is_boosting && ($link?->boost_count ?? 0) < 1) ? 1 : (int) ($link?->boost_count ?? 0),
            'boostsRewarded' => $link?->boosts_rewarded ?? 0,
            'creditsPerBoost1' => $service->getCreditsPerBoost1(),
            'creditsPerBoost2' => $service->getCreditsPerBoost2(),
            'minAccountAgeDays' => $service->getMinAccountAgeDays(),
            // Resolved here rather than hardcoded in the view, so the link keeps
            // working if the store page's slug or panel changes — and degrades to
            // no button at all if the core plugin is absent.
            'storeUrl' => class_exists(ResourceStorePage::class)
                ? ResourceStorePage::getUrl(panel: 'app')
                : null,
        ]);
    }
}
