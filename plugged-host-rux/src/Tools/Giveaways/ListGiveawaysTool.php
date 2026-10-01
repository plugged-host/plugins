<?php

namespace PluggedHost\Rux\Tools\Giveaways;

use PluggedHost\Rux\Models\RuxGiveaway;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class ListGiveawaysTool extends GiveawayTool
{
    public function name(): string
    {
        return 'list_giveaways';
    }

    public function description(): string
    {
        return 'Running giveaways and the most recent ended or cancelled ones, with entries, winners and payout.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'giveaway_id' => ['type' => 'integer', 'description' => 'Show only this giveaway.'],
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        if (($id = $this->int($arguments, 'giveaway_id')) !== null) {
            return ToolResult::ok($this->giveaways->summary($this->giveaway($arguments, [])));
        }

        $running = RuxGiveaway::query()->whereIn('status', [RuxGiveaway::ACTIVE, RuxGiveaway::DRAWING])->orderBy('ends_at')->limit(10)->get();
        $recent = RuxGiveaway::query()->whereIn('status', [RuxGiveaway::ENDED, RuxGiveaway::CANCELLED])->latest('id')->limit(5)->get();

        return ToolResult::ok([
            'running' => $running->map(fn (RuxGiveaway $g): array => $this->giveaways->summary($g))->all(),
            'recent' => $recent->map(fn (RuxGiveaway $g): array => $this->giveaways->summary($g))->all(),
        ]);
    }
}
