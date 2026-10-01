<?php

namespace PluggedHost\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Rux\Discord\Resolver;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class PurgeMessagesTool extends ModerationTool
{
    public function name(): string
    {
        return 'purge_messages';
    }

    public function description(): string
    {
        return 'Delete the most recent messages in a channel, optionally only one member\'s. Pinned messages and messages older than 14 days are kept.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'count' => ['type' => 'integer', 'description' => 'How many messages (1 to 200).'],
            'channel' => ['type' => 'string', 'description' => 'Channel (#name, <#id> or id). Leave out for the channel the admin is talking in.'],
            'from' => $this->memberProperty('Only this person\'s messages (optional).'),
            'reason' => $this->reasonProperty(),
        ], ['count']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $channelInput = $this->string($arguments, 'channel');

        if ($channelInput === null && $context->direct) {
            throw new InvalidArgumentException('This order came by DM, so say which channel to clean up.');
        }

        $channel = $this->resolver->channel($context->guildId, $channelInput ?? $context->channelId, [0, 5, 10, 11, 12, 15]);
        $fromInput = $this->string($arguments, 'from');
        $from = null;

        if ($fromInput !== null) {
            try {
                $from = $this->resolver->member($context->guildId, $fromInput);
            } catch (InvalidArgumentException $e) {
                // Someone who left (or was just banned) is not a member any more.
                $from = preg_match('/^<@!?\d{17,20}>$|^\d{17,20}$/', $fromInput) === 1 ? $this->resolver->user($fromInput) : throw $e;
            }
        }

        if ($from !== null && isset($from['member'])) {
            $this->guard($context, $from);
        }

        $result = $this->moderation->purge($context, $channel, max(1, (int) $this->required($arguments, 'count')), $from, $this->string($arguments, 'reason'));

        return ToolResult::ok($this->caseResult($result['case'], ['deleted' => $result['deleted'], 'channel' => '<#' . $channel['id'] . '>']));
    }
}
