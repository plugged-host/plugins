<?php

namespace PluggedHost\Core\Services;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\Role;
use App\Models\User;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;
use PluggedHost\Economy\Models\UserCredits;

/**
 * The perks a user has not claimed yet, as a list any view can render.
 *
 * This exists so the "you can get more RAM by linking Discord" message can stop being a
 * banner that is always on screen: the same data now feeds a card on the server-installing
 * page, the profile page and the dashboard, and each surface simply renders nothing once
 * there is nothing left to claim.
 *
 * Every number is derived, never written twice. The Discord headline in particular is a
 * diff between two RoleResourceLimits profiles — the roles the user has now versus those
 * plus the Discord role — so changing the role's limits in the admin panel changes the
 * copy, and a user who already bought RAM with credits is still told the role is worth
 * its full amount (see RoleResourceLimits::forRoleIds()).
 *
 * The Discord plugin is optional: every branch that touches it is guarded by class_exists.
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
                $this->discordRolePerk($user),
                $this->discordEarnPerk($user),
                $this->storePerk($user),
            ],
            fn (?array $perk): bool => $perk !== null && filled($perk['url']),
        ));
    }

    /**
     * "Link Discord for +4 GB RAM" — or, once linked, "join the server to unlock it".
     *
     * Suppressed entirely when the user already holds the role, when the role has no limit
     * profile attached, or when that profile would not actually improve on what they have.
     */
    private function discordRolePerk(User $user): ?array
    {
        if (!class_exists(DiscordLink::class)) {
            return null;
        }

        $roleName = config('plugged-host-discord.role_name');

        if (blank($roleName)) {
            return null;
        }

        /** @var ?Role $role */
        $role = Role::query()->where('name', $roleName)->first();

        if (!$role || $user->roles()->where('roles.id', $role->id)->exists()) {
            return null;
        }

        $roleIds = $user->roles()->pluck('roles.id')->all();

        $gain = $this->describeGain(
            RoleResourceLimits::forRoleIds($roleIds),
            RoleResourceLimits::forRoleIds([...$roleIds, (int) $role->id]),
        );

        if ($gain === null) {
            return null;
        }

        $link = $this->discordLink($user);
        $inviteUrl = config('plugged-host-discord.invite_url');

        // Linked but not a member: the role lands on verified membership, so the next step
        // is the invite rather than the profile tab they have already been through.
        if ($link?->isLinked() && !$link->isVerified() && filled($inviteUrl)) {
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
            'url' => $this->profileDiscordUrl(),
            'external' => false,
        ];
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

        if ($perInvite <= 0 && $perBoost <= 0) {
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
            'url' => $this->profileDiscordUrl(),
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

        if (RoleResourceLimits::forUser($user) === null) {
            return null;
        }

        $price = (int) config('plugged-host-economy.credits.price_memory_1024', 0);

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

    /**
     * Turns a before/after limit profile into "+4 GB RAM" or "+4 GB RAM and +100% CPU".
     *
     * Returns null when nothing improves, which is what suppresses the whole perk. A limit
     * of 0 means unlimited here, so it is compared before the subtraction: 0 on the "after"
     * side is the largest possible gain, and 0 on the "before" side means no gain is
     * possible at all.
     */
    private function describeGain(?RoleResourceLimits $from, ?RoleResourceLimits $to): ?string
    {
        if (!$to) {
            return null;
        }

        if (!$from) {
            return trans('plugged-host-core::strings.perks.gain_unlock');
        }

        $gains = [];

        foreach (['memory', 'cpu', 'disk'] as $resource) {
            if ($from->{$resource} === 0) {
                continue;
            }

            if ($to->{$resource} === 0) {
                $gains[] = trans("plugged-host-core::strings.perks.gain_unlimited_$resource");

                continue;
            }

            $delta = $to->{$resource} - $from->{$resource};

            if ($delta <= 0) {
                continue;
            }

            $gains[] = trans("plugged-host-core::strings.perks.gain_$resource", [
                'amount' => $resource === 'cpu' ? $delta . '%' : $this->formatMebibytes($delta),
            ]);
        }

        return $this->joinGains([...$gains, ...$this->serverSlotGain($from, $to)]);
    }

    /** @return array<int, string> */
    private function serverSlotGain(RoleResourceLimits $from, RoleResourceLimits $to): array
    {
        // null is unlimited for server slots, unlike 0 for the other three.
        if ($from->server_limit === null) {
            return [];
        }

        if ($to->server_limit === null) {
            return [trans('plugged-host-core::strings.perks.gain_unlimited_servers')];
        }

        if ($to->server_limit <= $from->server_limit) {
            return [];
        }

        return [trans('plugged-host-core::strings.perks.gain_servers', [
            'amount' => $to->server_limit - $from->server_limit,
        ])];
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

    private function profileDiscordUrl(): ?string
    {
        return env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space') . '/dashboard';
    }
}
