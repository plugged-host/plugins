<?php

namespace PluggedHost\Core\Contracts;

/**
 * Where people manage their account and link Discord.
 *
 * Standalone (PanelAccountPortal) that is the panel itself: its profile page and Pelican's own
 * Discord OAuth. With plugged-host-noprofit enabled it is noprofit.space, which then owns
 * sign-in, the account and the Discord link. Plugins ask this instead of naming either.
 */
interface AccountPortal
{
    /** Whether accounts are managed outside the panel (sign-in details, 2FA, Discord link). */
    public function external(): bool;

    /** The name to show for that place, e.g. "noprofit.space" or the panel's name. */
    public function name(): string;

    /**
     * Where the signed-in user manages their account; $page is a hint such as '/settings' that
     * only an external portal uses. Null when there is nowhere to send them.
     */
    public function manageUrl(?string $page = null): ?string;

    /** Where the signed-in user links their Discord account, or null when nothing can link it. */
    public function discordLinkUrl(): ?string;
}
