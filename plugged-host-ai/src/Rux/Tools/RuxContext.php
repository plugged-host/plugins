<?php

namespace PluggedHost\Ai\Rux\Tools;

use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Support\RuxAccess;

/**
 * Who ordered what, and where: handed to every tool. The actor is a noprofit.space admin, or a
 * member of a rux moderator role (level "moderator", fewer tools), or rux itself (a standing
 * rule or a watched channel); RuxAgent checks before the model is ever called. actorAccountId is
 * the actor's noprofit.space account when they have one.
 */
final class RuxContext
{
    public function __construct(
        public readonly string $guildId,
        public readonly string $actorDiscordId,
        public readonly string $actorName,
        public readonly ?int $actorAccountId,
        public readonly string $channelId,
        public readonly bool $direct,
        public readonly ?string $sourceMessageId,
        public readonly ?RuxMessage $order = null,
        public readonly string $level = RuxAccess::ADMIN,
    ) {}

    public function isAdmin(): bool
    {
        return $this->level === RuxAccess::ADMIN;
    }

    /**
     * What Discord's audit log shows for an action rux took on this order.
     */
    public function auditReason(?string $reason): string
    {
        return 'rux for ' . $this->actorName . ($reason !== null && trim($reason) !== '' ? ': ' . trim($reason) : '');
    }
}
