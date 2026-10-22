<?php

namespace PluggedHost\Ai\Rux\Tools\Giveaways;

use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class RerollGiveawayTool extends GiveawayTool
{
    public function name(): string
    {
        return 'reroll_giveaway';
    }

    public function description(): string
    {
        return 'Draw extra winners for an ended giveaway among the entries that have not won. They are paid too; earlier winners keep their credits.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'giveaway_id' => $this->idProperty(),
            'count' => ['type' => 'integer', 'description' => 'How many new winners. Default 1.'],
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $giveaway = $this->giveaway($context, $arguments, [RuxGiveaway::ENDED]);
        $winners = $this->giveaways->reroll($giveaway, $this->int($arguments, 'count') ?? 1);

        return ToolResult::ok(['new_winners' => array_map(static fn (string $id): string => "<@{$id}>", $winners)] + $this->giveaways->summary($giveaway->refresh()));
    }
}
