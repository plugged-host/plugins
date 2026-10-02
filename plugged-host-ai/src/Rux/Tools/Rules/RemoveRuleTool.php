<?php

namespace PluggedHost\Ai\Rux\Tools\Rules;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Moderation\ModerationService;
use PluggedHost\Ai\Rux\Models\RuxRule;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class RemoveRuleTool extends RuxTool
{
    public function __construct(private readonly ModerationService $moderation) {}

    public function name(): string
    {
        return 'remove_rule';
    }

    public function description(): string
    {
        return 'Stop enforcing a standing rule (list_rules shows the numbers).';
    }

    public function parameters(): array
    {
        return $this->schema(['rule_id' => ['type' => 'integer', 'description' => 'The rule\'s number.']], ['rule_id']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $rule = RuxRule::query()->where('active', true)->find((int) $this->required($arguments, 'rule_id'))
            ?? throw new InvalidArgumentException('There is no active rule with that number. Use list_rules.');

        $rule->update(['active' => false]);
        $this->moderation->note($context, 'rule_remove', $rule->description, ['rule' => $rule->id]);

        return ToolResult::ok(['removed' => true, 'rule' => $rule->id, 'was' => $rule->description]);
    }
}
