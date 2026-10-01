<?php

namespace PluggedHost\Rux\Tools\Rules;

use InvalidArgumentException;
use PluggedHost\Rux\Discord\Resolver;
use PluggedHost\Rux\Moderation\ModerationService;
use PluggedHost\Rux\Models\RuxWatch;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\RuxTool;
use PluggedHost\Rux\Tools\ToolResult;
use PluggedHost\Rux\Watch\WatchService;

final class WatchChannelTool extends RuxTool
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly WatchService $watch,
        private readonly ModerationService $moderation,
    ) {}

    public function name(): string
    {
        return 'watch_channel';
    }

    public function description(): string
    {
        return 'Start or stop checking EVERY member message in a channel with AI (against the standing rules and the always-on guidelines: scams, slurs, harassment, sexual content, spam, advertising). Busy channels use more of the AI limits.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'channel' => ['type' => 'string', 'description' => 'Channel (#name, <#id> or id).'],
            'watch' => ['type' => 'boolean', 'description' => 'true starts watching it, false stops.'],
        ], ['channel', 'watch']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $channel = $this->resolver->channel($context->guildId, $this->required($arguments, 'channel'));
        $id = (string) $channel['id'];
        $watch = $this->bool($arguments, 'watch', true);

        if ($watch) {
            RuxWatch::query()->firstOrCreate(['channel' => $id], ['added_by_discord_id' => $context->actorDiscordId]);
        } else {
            $ids = $this->watch->watchedChannelIds(fresh: true);

            if (!in_array($id, $ids, true)) {
                throw new InvalidArgumentException("<#{$id}> is not watched.");
            }

            // It may be watched by id or by a name that resolves to it.
            RuxWatch::query()->get()->each(function (RuxWatch $row) use ($id, $channel): void {
                $plain = static fn (string $n): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(ltrim($n, '#')));

                if ($row->channel === $id || $plain($row->channel) === $plain((string) $channel['name'])) {
                    $row->delete();
                }
            });
        }

        $this->moderation->note($context, $watch ? 'watch_add' : 'watch_remove', null, ['channel' => $id]);

        return ToolResult::ok([
            'channel' => "<#{$id}>",
            'watched' => $watch,
            'all_watched' => array_map(static fn (string $c): string => "<#{$c}>", $this->watch->watchedChannelIds(fresh: true)),
            'checks_paid_by_account' => RuxConfig::aiAccountId()['account_id'] ?? $context->actorAccountId,
        ]);
    }
}
