<?php

namespace PluggedHost\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Rux\Support\RuxTime;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class ChannelSettingsTool extends ModerationTool
{
    public function name(): string
    {
        return 'channel_settings';
    }

    public function description(): string
    {
        return 'Lock or unlock a channel for @everyone, or set its slowmode.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'channel' => ['type' => 'string', 'description' => 'Channel (#name, <#id> or id). Leave out for the channel the admin is talking in.'],
            'locked' => ['type' => 'boolean', 'description' => 'true locks (nobody but staff can write), false unlocks.'],
            'slowmode' => ['type' => 'string', 'description' => 'Slowmode per member, like "10s", "1m", "6h" (max). "0" turns it off.'],
            'reason' => $this->reasonProperty(),
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $channelInput = $this->string($arguments, 'channel');

        if ($channelInput === null && $context->direct) {
            throw new InvalidArgumentException('This order came by DM, so say which channel.');
        }

        $channel = $this->resolver->channel($context->guildId, $channelInput ?? $context->channelId, [0, 5, 15]);
        $reason = $this->string($arguments, 'reason');
        $done = [];

        if (array_key_exists('locked', $arguments)) {
            $locked = $this->bool($arguments, 'locked');
            $this->moderation->lock($context, $channel, $locked, $reason);
            $done['locked'] = $locked;
        }

        if (($slowmode = $this->string($arguments, 'slowmode')) !== null) {
            $seconds = in_array(strtolower($slowmode), ['0', 'off', 'none'], true) ? 0 : RuxTime::duration($slowmode);
            $this->moderation->slowmode($context, $channel, $seconds, $reason);
            $done['slowmode_seconds'] = min(21600, $seconds);
        }

        if ($done === []) {
            throw new InvalidArgumentException('Say whether to lock/unlock or which slowmode to set.');
        }

        return ToolResult::ok(['channel' => '<#' . $channel['id'] . '>'] + $done);
    }
}
