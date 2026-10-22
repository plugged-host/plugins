<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use PluggedHost\Ai\Rux\Models\RuxCase;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class ListCasesTool extends ModerationTool
{
    public function name(): string
    {
        return 'list_cases';
    }

    public function description(): string
    {
        return 'rux\'s moderation log, newest first: warnings, timeouts, kicks, bans, deleted messages, rule changes, by rux itself or on someone\'s order. Automatic cases show what the member said. Filter by member, action or time.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty('Only cases about this person, in the server or not (optional).'),
            'action' => ['type' => 'string', 'description' => 'Only this action: warn, timeout, untimeout, kick, ban, unban, delete, purge, nickname, clear_warnings, lock, slowmode, role_add, rule_add, rule_edit… (optional).'],
            'automatic' => ['type' => 'boolean', 'description' => 'true: only what rux did by itself (rules, watched channels, warning steps); false: only ordered actions.'],
            'since' => ['type' => 'string', 'description' => 'How far back, like "24h" or "7d" (optional).'],
            'count' => ['type' => 'integer', 'description' => 'How many, 1 to 40. Default 15.'],
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $query = RuxCase::query()->where('guild_id', $context->guildId);

        if (($member = $this->string($arguments, 'member')) !== null) {
            $query->where('target_id', $this->memberOrUser($context, $member)['id']);
        }

        if (($action = $this->string($arguments, 'action')) !== null) {
            $query->where('action', strtolower($action));
        }

        if (array_key_exists('automatic', $arguments)) {
            $query->where('automatic', $this->bool($arguments, 'automatic'));
        }

        if (($since = $this->string($arguments, 'since')) !== null) {
            $query->where('created_at', '>=', now()->subSeconds(RuxTime::duration($since)));
        }

        $total = (clone $query)->count();
        $cases = $query->latest('id')->limit(max(1, min(40, $this->int($arguments, 'count') ?? 15)))->get();

        return ToolResult::ok([
            'matching' => $total,
            'cases' => $cases->map(fn (RuxCase $case): string => $case->line(withMember: true))->all(),
        ]);
    }
}
