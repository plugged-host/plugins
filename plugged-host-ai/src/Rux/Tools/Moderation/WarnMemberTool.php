<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class WarnMemberTool extends ModerationTool
{
    public function name(): string
    {
        return 'warn_member';
    }

    public function description(): string
    {
        return 'Warn a member: they get a DM with the reason and it counts towards automatic steps (by default 3 warnings = 24h timeout, 5 = 24h ban).';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema(['member' => $this->memberProperty(), 'reason' => $this->reasonProperty()], ['member', 'reason']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $this->guard($context, $target);
        $result = $this->moderation->warn($context, $target, $this->string($arguments, 'reason'));
        $step = $result['escalation'];

        return ToolResult::ok($this->caseResult($result['case'], array_filter([
            'warnings_total' => $result['warnings'],
            'dm_delivered' => (bool) ($result['case']->meta['dm_delivered'] ?? false),
            'automatic_step' => $step !== null ? $step->action . ($step->duration_seconds ? ' for ' . RuxTime::humanDuration($step->duration_seconds) : '') : null,
            'automatic_step_failed' => $result['case']->meta['step_failed'] ?? null,
        ], static fn ($value): bool => $value !== null)));
    }
}
