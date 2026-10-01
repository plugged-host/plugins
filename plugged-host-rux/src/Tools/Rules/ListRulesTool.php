<?php

namespace PluggedHost\Rux\Tools\Rules;

use PluggedHost\Rux\Models\RuxRule;
use PluggedHost\Rux\Rules\RuleService;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\RuxTool;
use PluggedHost\Rux\Tools\ToolResult;
use PluggedHost\Rux\Watch\WatchService;

final class ListRulesTool extends RuxTool
{
    public function __construct(private readonly WatchService $watch) {}

    public function name(): string
    {
        return 'list_rules';
    }

    public function description(): string
    {
        return 'The standing rules rux enforces, the watched channels, and the automatic warning steps.';
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
        $ladder = (array) config('plugged-host-rux.warnings', []);

        return ToolResult::ok([
            'rules' => RuxRule::query()->where('active', true)->orderBy('id')->get()->map(fn (RuxRule $r): array => RuleService::summary($r))->all(),
            'watched_channels' => array_map(static fn (string $id): string => "<#{$id}>", $this->watch->watchedChannelIds(fresh: true)),
            'watched_channel_guidelines' => config('plugged-host-rux.watch.guidelines', true) ? array_values(WatchService::GUIDELINES) : 'off',
            'warning_steps' => [
                'timeout' => (int) ($ladder['timeout_at'] ?? 0) > 0 ? "from {$ladder['timeout_at']} warnings: timeout {$ladder['timeout']}" : 'off',
                'ban' => (int) ($ladder['ban_at'] ?? 0) > 0 ? "from {$ladder['ban_at']} warnings: ban {$ladder['ban']}" : 'off',
            ],
            'watch_paused' => \Illuminate\Support\Facades\Cache::has('plugged-host-rux:watch-paused') ? 'yes, the AI refused rux; it tries again within 10 minutes' : null,
        ]);
    }
}
