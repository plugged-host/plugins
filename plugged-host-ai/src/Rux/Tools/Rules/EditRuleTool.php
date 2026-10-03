<?php

namespace PluggedHost\Ai\Rux\Tools\Rules;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Discord\Resolver;
use PluggedHost\Ai\Rux\Models\RuxRule;
use PluggedHost\Ai\Rux\Moderation\ModerationService;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class EditRuleTool extends RuxTool
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ModerationService $moderation,
    ) {}

    public function name(): string
    {
        return 'edit_rule';
    }

    public function description(): string
    {
        return 'Change a standing rule (list_rules shows the numbers): what breaks it, keywords, action, duration, message deletion, the reason members are told, or its channels. Only the fields given change.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'rule_id' => ['type' => 'integer', 'description' => 'The rule\'s number.'],
            'when' => ['type' => 'string', 'description' => 'New description of what breaks the rule.'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The full new keyword list (replaces the old one; list_rules shows it), as for add_rule.'],
            'action' => ['type' => 'string', 'enum' => RuxRule::ACTIONS, 'description' => 'New action: warn, timeout, delete, kick or ban.'],
            'duration' => ['type' => 'string', 'description' => 'For timeout or ban: how long, like "1h". "permanent" for a ban for good.'],
            'delete_message' => ['type' => 'boolean', 'description' => 'Also delete the message, or not.'],
            'reason' => ['type' => 'string', 'description' => 'What the member is told.'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only in these channels (#name or id); an empty list means the whole server.'],
        ], ['rule_id']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $rule = RuxRule::query()->where('active', true)->find((int) $this->required($arguments, 'rule_id'))
            ?? throw new InvalidArgumentException('There is no active rule with that number. Use list_rules.');

        $changes = [];
        $dropped = [];

        if (($when = $this->string($arguments, 'when')) !== null) {
            $changes['description'] = mb_substr($when, 0, 300);
        }

        if (array_key_exists('keywords', $arguments)) {
            ['keywords' => $changes['keywords'], 'dropped' => $dropped] = AddRuleTool::keywords((array) $arguments['keywords']);
        }

        $action = $rule->action;
        if (($newAction = $this->string($arguments, 'action')) !== null) {
            $action = strtolower($newAction);

            if (!in_array($action, RuxRule::ACTIONS, true)) {
                throw new InvalidArgumentException('action must be one of ' . implode(', ', RuxRule::ACTIONS) . '.');
            }

            $changes['action'] = $action;
        }

        $duration = $this->string($arguments, 'duration');
        if ($duration !== null || isset($changes['action'])) {
            $seconds = match (true) {
                $duration === null => $rule->duration_seconds,
                in_array(strtolower($duration), ['0', 'permanent', 'forever', 'none', 'off'], true) => null,
                default => RuxTime::duration($duration),
            };

            if ($action === 'timeout') {
                $seconds = max(60, min(ModerationService::MAX_TIMEOUT_SECONDS, $seconds ?? 3600));
            }

            $changes['duration_seconds'] = in_array($action, ['timeout', 'ban'], true) ? $seconds : null;
        }

        if (array_key_exists('delete_message', $arguments) || $action === 'delete') {
            $changes['delete_message'] = $action === 'delete' || $this->bool($arguments, 'delete_message');
        }

        if (($reason = $this->string($arguments, 'reason')) !== null) {
            $changes['reason'] = mb_substr($reason, 0, 300);
        }

        if (array_key_exists('channels', $arguments)) {
            $channels = [];
            foreach ((array) $arguments['channels'] as $channel) {
                $channels[] = (string) $this->resolver->channel($context->guildId, (string) $channel, [0, 5, 15])['id'];
            }

            $changes['channel_ids'] = $channels ?: null;
        }

        $changed = array_keys(array_filter($changes, static fn ($value, string $field): bool => $value !== $rule->getAttribute($field), ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            throw new InvalidArgumentException('Nothing to change: say what the rule should do differently.');
        }

        $rule->update($changes);
        $this->moderation->note($context, 'rule_edit', $rule->description, ['rule' => $rule->id, 'changed' => $changed]);

        return ToolResult::ok(['changed' => $changed] + RuleService::summary($rule->refresh()) + ($dropped !== [] ? ['keywords_dropped_as_too_common' => $dropped] : []));
    }
}
