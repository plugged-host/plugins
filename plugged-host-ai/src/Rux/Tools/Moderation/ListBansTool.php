<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use PluggedHost\Ai\Rux\Models\RuxCase;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxText;
use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class ListBansTool extends ModerationTool
{
    private const SHOWN = 40;

    public function name(): string
    {
        return 'list_bans';
    }

    public function description(): string
    {
        return 'Who is banned from the server, with the reason and, for rux\'s bans, the case and when the ban ends. Finds a banned person by name or id (they are not members, so lookup_member cannot).';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'search' => ['type' => 'string', 'description' => 'Only bans whose username, display name or id contains this (optional).'],
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $bans = $this->discord->bans($context->guildId);
        $search = $this->string($arguments, 'search');

        if ($search !== null) {
            $needle = mb_strtolower(trim($search, " <@!>"));
            $bans = array_values(array_filter($bans, static fn (array $ban): bool => str_contains(mb_strtolower(implode(' ', [
                $ban['user']['id'] ?? '',
                $ban['user']['username'] ?? '',
                $ban['user']['global_name'] ?? '',
            ])), $needle)));
        }

        $open = RuxCase::query()
            ->where('guild_id', $context->guildId)
            ->where('action', 'ban')
            ->whereNull('resolved_at')
            ->whereIn('target_id', array_map(static fn (array $ban): string => (string) $ban['user']['id'], $bans))
            ->latest('id')
            ->get()
            ->unique('target_id')
            ->keyBy('target_id');

        // rux's own bans first, newest first; Discord lists the rest by user id.
        usort($bans, static fn (array $a, array $b): int => ($open[$b['user']['id']]->id ?? 0) <=> ($open[$a['user']['id']]->id ?? 0));

        $lines = array_map(static function (array $ban) use ($open): string {
            $user = (array) $ban['user'];
            $case = $open[$user['id']] ?? null;

            return "<@{$user['id']}> (" . RuxText::untrusted((string) ($user['global_name'] ?? $user['username'] ?? '?'), 40) . ')'
                . ': ' . ($ban['reason'] !== null && $ban['reason'] !== '' ? RuxText::untrusted((string) $ban['reason'], 150) : 'no reason given')
                . ($case !== null ? " (rux case #{$case->id}, " . ($case->expires_at !== null ? 'ends ' . RuxTime::human($case->expires_at) : 'permanent') . ')' : '');
        }, array_slice($bans, 0, self::SHOWN));

        return ToolResult::ok(array_filter([
            'banned' => count($bans),
            'bans' => $lines,
            'note' => count($bans) > self::SHOWN ? 'Only the first ' . self::SHOWN . ' are shown: search by name or id.' : null,
        ], static fn ($value): bool => $value !== null));
    }
}
