<?php

namespace PluggedHost\Ai\Rux\Tools\Rules;

use PluggedHost\Ai\Rux\Models\RuxRule;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Ai\Rux\Tools\ToolResult;
use PluggedHost\Ai\Rux\Watch\WatchService;

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
        $ladder = (array) config('plugged-host-ai.rux.warnings', []);

        return ToolResult::ok([
            'rules' => RuxRule::query()->where('active', true)->orderBy('id')->get()->map(fn (RuxRule $r): array => RuleService::summary($r))->all(),
            'watched_channels' => array_map(static fn (string $id): string => "<#{$id}>", $this->watch->watchedChannelIds(fresh: true)),
            'watched_channel_guidelines' => config('plugged-host-ai.rux.watch.guidelines', true) ? array_values(WatchService::GUIDELINES) : 'off',
            'automatic_spam' => config('plugged-host-ai.rux.spam.enabled', true)
                ? 'on, in every channel, without AI: ' . self::spamLimits()
                : 'off',
            'warning_steps' => [
                'timeout' => (int) ($ladder['timeout_at'] ?? 0) > 0 ? "from {$ladder['timeout_at']} warnings: timeout {$ladder['timeout']}" : 'off',
                'ban' => (int) ($ladder['ban_at'] ?? 0) > 0 ? "from {$ladder['ban_at']} warnings: ban {$ladder['ban']}" : 'off',
            ],
            'watch_paused' => \Illuminate\Support\Facades\Cache::has('plugged-host-ai:rux:watch-paused') ? 'yes, the AI refused rux; it tries again within 10 minutes' : null,
        ]);
    }

    private static function spamLimits(): string
    {
        $spam = (array) config('plugged-host-ai.rux.spam', []);

        return sprintf(
            '%d messages within %ds, the same message %d times within %ds, %d or more mentions, or %d or more emoji in one message: deleted, and the member warned at most once per %d minutes',
            (int) ($spam['flood_messages'] ?? 7),
            (int) ($spam['flood_seconds'] ?? 10),
            (int) ($spam['duplicates'] ?? 3),
            (int) ($spam['duplicate_seconds'] ?? 120),
            (int) ($spam['mentions'] ?? 6),
            (int) ($spam['emoji'] ?? 25),
            max(1, intdiv(max(30, (int) config('plugged-host-ai.rux.rules.cooldown_seconds', 300)), 60)),
        );
    }
}
