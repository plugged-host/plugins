<?php

namespace PluggedHost\Rux\Tools\Moderation;

use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class UnbanUserTool extends ModerationTool
{
    public function name(): string
    {
        return 'unban_user';
    }

    public function description(): string
    {
        return 'Lift a ban. Needs the user id or <@mention> (banned people are not members, so names do not work).';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema(['user' => ['type' => 'string', 'description' => 'User id or <@mention>.'], 'reason' => $this->reasonProperty()], ['user']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->user($this->required($arguments, 'user'));

        return ToolResult::ok($this->caseResult($this->moderation->unban($context, $target, $this->string($arguments, 'reason'))));
    }
}
