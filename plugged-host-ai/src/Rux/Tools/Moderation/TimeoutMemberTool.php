<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class TimeoutMemberTool extends ModerationTool
{
    public function name(): string
    {
        return 'timeout_member';
    }

    public function description(): string
    {
        return 'Time a member out (they cannot talk) for a while, at most 28 days, or lift a timeout with duration "0".';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty(),
            'duration' => ['type' => 'string', 'description' => 'How long: "10m", "2h", "7d". "0" lifts the timeout.'],
            'reason' => $this->reasonProperty(),
        ], ['member', 'duration']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $this->guard($context, $target);
        $duration = $this->required($arguments, 'duration');
        $reason = $this->string($arguments, 'reason');

        if (in_array(strtolower($duration), ['0', 'off', 'none', 'remove'], true)) {
            return ToolResult::ok($this->caseResult($this->moderation->untimeout($context, $target, $reason)));
        }

        $seconds = RuxTime::duration($duration);
        $case = $this->moderation->timeout($context, $target, $seconds, $reason);

        return ToolResult::ok($this->caseResult($case, ['until' => RuxTime::human(now()->addSeconds($seconds)), 'dm_delivered' => (bool) ($case->meta['dm_delivered'] ?? false)]));
    }
}
