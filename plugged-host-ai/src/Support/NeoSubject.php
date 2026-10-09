<?php

namespace PluggedHost\Ai\Support;

use App\Models\User;

/**
 * Whose neo limits a turn is metered against when this panel meters neo (noprofit.space's
 * NeoSubject): a panel user (from the chat, or from Discord by a linked Discord account), or a
 * Discord account nobody has linked, which has windows of its own at a share of a user's limits.
 *
 * usagePercent scales a user's limits (Plus and PlusPlus get more, NeoUsagePercent). Never below
 * 100; an unlinked Discord account ignores it.
 */
final class NeoSubject
{
    private function __construct(
        public readonly ?User $user,
        public readonly ?string $discordId,
        public readonly int $usagePercent = 100,
    ) {}

    public static function account(User $user, ?string $discordId = null): self
    {
        return new self($user, $discordId);
    }

    public static function unlinkedDiscord(string $discordId): self
    {
        return new self(null, $discordId);
    }

    /**
     * The same subject with its limits scaled to $percent (100 to 1000).
     */
    public function withUsagePercent(?int $percent): self
    {
        return new self($this->user, $this->discordId, max(100, min(1000, $percent ?? 100)));
    }

    public function isUnlinkedDiscord(): bool
    {
        return $this->user === null;
    }

    /**
     * One turn at a time per subject: a user's chat and their linked Discord share it.
     */
    public function lockKey(): string
    {
        return $this->user !== null ? (string) $this->user->id : "discord:{$this->discordId}";
    }
}
