<?php

namespace PluggedHost\Rux\Tools\Moderation;

use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class KickMemberTool extends ModerationTool
{
    public function name(): string
    {
        return 'kick_member';
    }

    public function description(): string
    {
        return 'Kick a member out of the server (they can rejoin with an invite). They get a DM with the reason first.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema(['member' => $this->memberProperty(), 'reason' => $this->reasonProperty()], ['member']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));

        $this->guard($context, $target);
        return ToolResult::ok($this->caseResult($this->moderation->kick($context, $target, $this->string($arguments, 'reason'))));
    }
}
