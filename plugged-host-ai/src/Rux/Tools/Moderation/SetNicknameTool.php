<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class SetNicknameTool extends ModerationTool
{
    public function name(): string
    {
        return 'set_nickname';
    }

    public function description(): string
    {
        return 'Change a member\'s nickname on the server (e.g. an offensive or impersonating name), or remove it so their own name shows.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty(),
            'nickname' => ['type' => 'string', 'description' => 'The new nickname, up to 32 characters. Empty or "reset" removes it.'],
            'reason' => $this->reasonProperty(),
        ], ['member', 'nickname']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $this->guard($context, $target);

        $nickname = $this->string($arguments, 'nickname');
        if ($nickname !== null && in_array(strtolower($nickname), ['reset', 'remove', 'none', 'clear', 'off'], true)) {
            $nickname = null;
        }

        if ($nickname !== null && mb_strlen($nickname) > 32) {
            throw new InvalidArgumentException('A nickname is at most 32 characters.');
        }

        $case = $this->moderation->nickname($context, $target, $nickname, $this->string($arguments, 'reason'));

        return ToolResult::ok($this->caseResult($case, ['nickname' => $nickname ?? 'removed, their own name shows']));
    }
}
