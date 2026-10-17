<?php

namespace PluggedHost\Discord\Services;

use App\Models\User;
use PluggedHost\Discord\Contracts\DiscordServersStatus;

/**
 * The panel on its own checks only its own Discord server (DiscordVerificationService) and pays
 * that server's boosts itself, so there is no Discord servers status to show.
 */
final class PanelDiscordServersStatus implements DiscordServersStatus
{
    public function available(): bool
    {
        return false;
    }

    public function forUser(User $user, bool $check = false): ?array
    {
        return null;
    }

    public function linkUrl(): ?string
    {
        return null;
    }
}
