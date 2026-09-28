<?php

namespace PluggedHost\Core\Services;

use App\Models\User;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\NoprofitCreditSync;
use PluggedHost\Economy\Services\ResourceStoreService;
use PluggedHost\Servers\Support\AccountAllowance;

/**
 * The perks a user has not claimed yet, as a list any view can render.
 *
 * This exists so the "you can get more RAM by linking Discord" message can stop being a
 * banner that is always on screen: the same data now feeds a card on the server-installing
 * page, the profile page and the dashboard, and each surface simply renders nothing once
 * there is nothing left to claim.
 *
 * Every number is derived, never written twice. The Discord headline in particular is the
 * difference between noprofit.space's Discord tier and its default tier (both sent with the
 * credit sync), so changing a tier on noprofit.space changes the copy, and a user who
 * already bought RAM with credits is still told the tier is worth its full amount.
 *
 * The Discord and economy plugins are optional: every branch that touches them is guarded
 * by class_exists.
 */
class PerkDiscovery
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $perks = [];

    /** @var array<int, ?DiscordLink> */
    private array $links = [];

    /**
     * @return array<int, array{id: string, icon: string, color: string, title: string, body: string, cta: string, url: string, external: bool}>
     */
    public function forUser(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return $this->perks[$user->id] ??= array_values(array_filter(
            [
                $this->discordTierPerk($user),
                $this->discordEarnPerk($user),
                $this->storePerk($user),
            ],
            fn (?array $perk): bool => $perk !== null && filled($perk['url']),
        ));
    }

    /**
     * "Join our Discord for +4 GB RAM" -- or, before linking, "link Discord" on noprofit.space.
     *
     * Suppressed entirely once the user is in the server, while the tiers are not known, or
     * when the Discord tier would not improve on the default one.
     */
    private function discordTierPerk(User $user): ?array
    {
        $gain = $this->discordTierGain($user);

        if ($gain === null) {
            return null;
        }

        $link = $this->discordLink($user);
        $inviteUrl = config('plugged-host-discord.invite_url');

        // Linked but not a member: the tier follows verified membership, so the next step is
        // the invite rather than linking again.
        if ($link?->isLinked() && filled($inviteUrl)) {
            return [
                'id' => 'discord-join',
                'icon' => 'tabler-brand-discord',
                'color' => 'info',
                'title' => trans('plugged-host-core::strings.perks.discord_join_title', ['gain' => $gain]),
                'body' => trans('plugged-host-core::strings.perks.discord_join_body'),
                'cta' => trans('plugged-host-core::strings.perks.discord_join_cta'),
                'url' => $inviteUrl,
                'external' => true,
            ];
        }

        return [
            'id' => 'discord-link',
            'icon' => 'tabler-brand-discord',
            'color' => 'info',
            'title' => trans('plugged-host-core::strings.perks.discord_link_title', ['gain' => $gain]),
            'body' => trans('plugged-host-core::strings.perks.discord_link_body'),
            'cta' => trans('plugged-host-core::strings.perks.discord_link_cta'),
            'url' => NoprofitLink::accountsUrl('/settings'),
            'external' => true,
        ];
    }

    /**
     * What noprofit.space's Discord tier adds to the default tier ("+4 GB RAM"), or null when
     * the user is already in the Discord server, the tiers are unknown, or it adds nothing.
     */
    public function discordTierGain(User $user): ?string
    {
        if (!class_exists(DiscordLink::class) || !class_exists(NoprofitCreditSync::class)) {
            return null;
        }

        if ($this->discordLink($user)?->isVerified()) {
            return null;
        }

        $tiers = NoprofitCreditSync::tiers();

        if ($tiers === null) {
            return null;
        }

        $gains = [];

        foreach (['memory', 'cpu', 'disk'] as $resource) {
            $delta = (int) $tiers['discord'][$resource] - (int) $tiers['default'][$resource];

            if ($delta > 0) {
                $gains[] = trans("plugged-host-core::strings.perks.gain_$resource", [
                    'amount' => $resource === 'cpu' ? $delta . '%' : $this->formatMebibytes($delta),
                ]);
            }
        }

        return $this->joinGains($gains);
    }

    /**
     * Only offered once membership is verified, because that is the point at which invites
     * and boosts are actually attributed to the account.
     */
    private function discordEarnPerk(User $user): ?array
    {
        if (!class_exists(DiscordLink::class) || !config('plugged-host-economy.credits.enabled', true)) {
            return null;
        }

        if (!$this->discordLink($user)?->isVerified()) {
            return null;
        }

        $perInvite = (int) config('plugged-host-discord.credits_per_invite', 0);
        $perBoost = (int) config('plugged-host-discord.credits_per_boost_1', 0);

        // The Discord rewards tab on the profile page (plugged-host-discord).
        $url = class_exists(\PluggedHost\Discord\Livewire\DiscordRewards::class)
            ? \PluggedHost\Discord\Livewire\DiscordRewards::url()
            : null;

        if (($perInvite <= 0 && $perBoost <= 0) || $url === null) {
            return null;
        }

        return [
            'id' => 'discord-earn',
            'icon' => 'tabler-gift',
            'color' => 'primary',
            'title' => trans('plugged-host-core::strings.perks.discord_earn_title', [
                'credits' => number_format($perInvite),
            ]),
            'body' => $perBoost > 0
                ? trans('plugged-host-core::strings.perks.discord_earn_body_boost', ['credits' => number_format($perBoost)])
                : trans('plugged-host-core::strings.perks.discord_earn_body'),
            'cta' => trans('plugged-host-core::strings.perks.discord_earn_cta'),
            'url' => $url,
            'external' => false,
        ];
    }

    /**
     * Priced in the same units the store sells in, so the card doubles as the price list.
     * Skipped for users with no limit profile at all — they have nothing to upgrade.
     */
    private function storePerk(User $user): ?array
    {
        if (!config('plugged-host-economy.credits.enabled', true) || !class_exists(ResourceStorePage::class)) {
            return null;
        }

        if (AccountAllowance::forUser($user) === null) {
            return null;
        }

        $price = class_exists(ResourceStoreService::class) ? app(ResourceStoreService::class)->getPriceForMemory(1024) : 0;

        if ($price <= 0) {
            return null;
        }

        return [
            'id' => 'store',
            'icon' => 'tabler-shopping-bag',
            'color' => 'success',
            'title' => trans('plugged-host-core::strings.perks.store_title', [
                'price' => number_format($price),
                'amount' => $this->formatMebibytes(1024),
            ]),
            'body' => trans('plugged-host-core::strings.perks.store_body', [
                'balance' => number_format(UserCredits::forUser($user)?->credits ?? 0),
            ]),
            'cta' => trans('plugged-host-core::strings.perks.store_cta'),
            'url' => ResourceStorePage::getUrl(panel: 'app'),
            'external' => false,
        ];
    }

    /** @param array<int, string> $gains */
    private function joinGains(array $gains): ?string
    {
        if ($gains === []) {
            return null;
        }

        if (count($gains) === 1) {
            return $gains[0];
        }

        $last = array_pop($gains);

        return implode(', ', $gains) . ' ' . trans('plugged-host-core::strings.perks.gain_and') . ' ' . $last;
    }

    private function formatMebibytes(int $amount): string
    {
        $binary = (bool) config('panel.use_binary_prefix');

        if ($amount % 1024 === 0) {
            return intdiv($amount, 1024) . ($binary ? ' GiB' : ' GB');
        }

        return $amount . ($binary ? ' MiB' : ' MB');
    }

    private function discordLink(User $user): ?DiscordLink
    {
        if (!class_exists(DiscordLink::class)) {
            return null;
        }

        return $this->links[$user->id] ?? ($this->links[$user->id] = DiscordLink::query()
            ->where('user_id', $user->id)
            ->first());
    }
}
