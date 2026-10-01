<?php

namespace PluggedHost\Rux\Tools\Moderation;

use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class ClearWarningsTool extends ModerationTool
{
    public function name(): string
    {
        return 'clear_warnings';
    }

    public function description(): string
    {
        return 'Take back a member\'s warnings (the latest N, or all), so they stop counting towards the automatic timeout and ban.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty(),
            'count' => ['type' => 'integer', 'description' => 'How many of the latest warnings. Leave out for all.'],
            'reason' => $this->reasonProperty(),
        ], ['member']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $this->guard($context, $target);
        $cleared = $this->moderation->clearWarnings($context, $target, $this->int($arguments, 'count'), $this->string($arguments, 'reason'));

        return ToolResult::ok(['member' => "<@{$target['id']}>", 'cleared' => $cleared, 'warnings_left' => $this->moderation->warningCount($target['id'])]);
    }
}
