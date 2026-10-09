<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Discord\Resolver;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxCase;
use PluggedHost\Ai\Rux\Moderation\ModerationService;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Discord\Contracts\DirectoryUnavailable;
use PluggedHost\Discord\Contracts\DiscordAccounts;

abstract class ModerationTool extends RuxTool
{
    public function __construct(
        protected readonly ModerationService $moderation,
        protected readonly Resolver $resolver,
        protected readonly RuxDiscord $discord,
        protected readonly RuxAccess $access,
    ) {}

    /**
     * Moderators may not act on staff (the owner, people with moderation permissions, other
     * moderators) or on noprofit.space admins, whatever their Discord roles; admins may.
     *
     * @param  array{id: string, member?: array<string, mixed>}  $target
     */
    protected function guard(RuxContext $context, array $target): void
    {
        if ($context->isAdmin()) {
            return;
        }

        $roles = isset($target['member']) ? array_values(array_map('strval', (array) ($target['member']['roles'] ?? []))) : null;

        if ($this->isProtected($context, $target['id'], $roles)) {
            throw new InvalidArgumentException('Moderators cannot do this to staff, admins, other moderators or themselves. Tell them to ask an admin.');
        }
    }

    /**
     * Whether a moderator must leave this person alone (see guard()). Works for people who are
     * not in the server too: their roles are looked up, and noprofit.space admins are checked
     * either way.
     *
     * @param  ?list<string>  $roles  the member's role ids, when already known
     */
    protected function isProtected(RuxContext $context, string $discordId, ?array $roles = null): bool
    {
        if ($discordId === $context->actorDiscordId || $this->access->isStaff($discordId, $roles)) {
            return true;
        }

        try {
            return (bool) (app(DiscordAccounts::class)->account($discordId, 4)['is_admin'] ?? false);
        } catch (DirectoryUnavailable) {
            // Cannot tell: refusing is the safe mistake.
            return true;
        }
    }

    /**
     * Moderators may not act on the mod-log channel, where rux records what they do.
     *
     * @param  array{id: string}  $channel
     */
    protected function guardChannel(RuxContext $context, array $channel): void
    {
        $modLog = trim((string) config('plugged-host-ai.rux.mod_log_channel_id', ''));

        if (!$context->isAdmin() && $modLog !== '' && (string) $channel['id'] === $modLog) {
            throw new InvalidArgumentException('Moderators cannot do this in the mod-log channel. Tell them to ask an admin.');
        }
    }

    /**
     * Moderators only read or delete in channels they can open on Discord themselves: rux sees
     * every channel, staff-only ones included.
     *
     * @param  array<string, mixed>  $channel
     */
    protected function guardVisible(RuxContext $context, array $channel): void
    {
        if (!$context->isAdmin() && !$this->access->canView($context->actorDiscordId, $channel)) {
            throw new InvalidArgumentException("This moderator cannot see <#{$channel['id']}> on Discord, so rux does not read or delete there for them.");
        }
    }

    /**
     * A channel that holds messages (text, announcement, voice and stage chats, threads), or the
     * one the order came from when none is named.
     *
     * @return array<string, mixed>
     */
    protected function messageChannel(RuxContext $context, ?string $input): array
    {
        if ($input === null && $context->direct) {
            throw new InvalidArgumentException('This order came by DM, so say which channel.');
        }

        $input ??= $context->channelId;

        try {
            return $this->resolver->channel($context->guildId, $input, [0, 2, 5, 13]);
        } catch (InvalidArgumentException $e) {
            // Threads are not in the server's channel list.
            return preg_match('/^(?:<#)?(\d{17,20})>?$/', trim($input), $m) === 1
                ? $this->resolver->messageChannel($context->guildId, $m[1])
                : throw $e;
        }
    }

    /**
     * A member, or for someone who left (or was banned) the Discord user behind a mention or id.
     *
     * @return array{id: string, name: string, member?: array<string, mixed>}
     */
    protected function memberOrUser(RuxContext $context, string $input): array
    {
        try {
            return $this->resolver->member($context->guildId, $input);
        } catch (InvalidArgumentException $e) {
            return preg_match('/^<@!?\d{17,20}>$|^\d{17,20}$/', trim($input)) === 1 ? $this->resolver->user($input) : throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function memberProperty(string $description = 'The member: <@mention>, user id, or their name.'): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reasonProperty(): array
    {
        return ['type' => 'string', 'description' => 'Why, in a few words. Shown to the member and in the audit log.'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function caseResult(RuxCase $case, array $extra = []): array
    {
        return $extra + ['case' => $case->id, 'action' => $case->action, 'member' => $case->target_id !== null ? "<@{$case->target_id}>" : null];
    }
}
