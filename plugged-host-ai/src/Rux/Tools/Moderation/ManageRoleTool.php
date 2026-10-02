<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class ManageRoleTool extends ModerationTool
{
    public function name(): string
    {
        return 'manage_role';
    }

    public function description(): string
    {
        return 'Give a member a role or take it away.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty(),
            'role' => ['type' => 'string', 'description' => 'The role: name, <@&mention> or id.'],
            'add' => ['type' => 'boolean', 'description' => 'true gives the role, false takes it away.'],
            'reason' => $this->reasonProperty(),
        ], ['member', 'role', 'add']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $role = $this->resolver->role($context->guildId, $this->required($arguments, 'role'));
        $case = $this->moderation->role($context, $target, $role, $this->bool($arguments, 'add', true), $this->string($arguments, 'reason'));

        return ToolResult::ok($this->caseResult($case, ['role' => (string) $role['name']]));
    }
}
