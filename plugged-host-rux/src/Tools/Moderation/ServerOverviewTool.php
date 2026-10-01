<?php

namespace PluggedHost\Rux\Tools\Moderation;

use PluggedHost\Rux\Models\RuxModeratorRole;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Watch\WatchService;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class ServerOverviewTool extends ModerationTool
{
    public function name(): string
    {
        return 'server_overview';
    }

    public function description(): string
    {
        return 'The server\'s channels (by category) and roles with their ids, rux\'s watched channels, moderator roles, and default giveaway and mod log channels.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $channels = collect($this->discord->channels($context->guildId, fresh: true));
        $categories = $channels->where('type', 4)->keyBy('id');
        $kinds = [0 => 'text', 2 => 'voice', 5 => 'announcement', 13 => 'stage', 15 => 'forum'];

        $listed = $channels
            ->filter(fn (array $c): bool => isset($kinds[(int) $c['type']]))
            ->sortBy(fn (array $c): string => sprintf('%05d-%05d', (int) ($categories[$c['parent_id'] ?? '']['position'] ?? -1) + 1, (int) ($c['position'] ?? 0)))
            ->groupBy(fn (array $c): string => (string) ($categories[$c['parent_id'] ?? '']['name'] ?? 'no category'))
            ->map(fn ($group) => $group->map(fn (array $c): string => '#' . $c['name'] . ' ' . $kinds[(int) $c['type']] . ' ' . $c['id'])->values()->all())
            ->all();

        $roles = collect($this->discord->roles($context->guildId, fresh: true))
            ->reject(fn (array $r): bool => (string) $r['id'] === $context->guildId)
            ->sortByDesc('position')
            ->map(fn (array $r): string => $r['name'] . ' ' . $r['id'] . (($r['managed'] ?? false) ? ' (managed)' : ''))
            ->values()
            ->all();

        $giveawayChannel = trim((string) config('plugged-host-rux.giveaway_channel_id', ''));
        $modLog = trim((string) config('plugged-host-rux.mod_log_channel_id', ''));

        return ToolResult::ok([
            'watched_channels' => array_map(static fn (string $id): string => "<#{$id}>", app(WatchService::class)->watchedChannelIds(fresh: true)),
            'rux_moderator_roles' => RuxModeratorRole::query()->get()->map(fn (RuxModeratorRole $r): string => "<@&{$r->role_id}> ({$r->role_name})")->all(),
            'channels' => $listed,
            'roles_highest_first' => $roles,
            'default_giveaway_channel' => $giveawayChannel !== '' ? "<#{$giveawayChannel}>" : 'none set',
            'mod_log_channel' => $modLog !== '' ? "<#{$modLog}>" : 'none set',
        ]);
    }
}
