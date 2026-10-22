<?php

namespace PluggedHost\Ai\Rux\Tools\Giveaways;

use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class EditGiveawayTool extends GiveawayTool
{
    public function name(): string
    {
        return 'edit_giveaway';
    }

    public function description(): string
    {
        return 'Change a running giveaway: end time, credits per winner, number of winners or headline. Its message updates.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'giveaway_id' => $this->idProperty(),
            'ends_at' => ['type' => 'string', 'description' => 'New end: "YYYY-MM-DD HH:MM" in rux\'s time zone, or a duration from now.'],
            'credits' => ['type' => 'integer', 'description' => 'New credits per winner.'],
            'winners' => ['type' => 'integer', 'description' => 'New number of winners.'],
            'title' => ['type' => 'string', 'description' => 'New headline.'],
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $giveaway = $this->giveaways->update($this->giveaway($context, $arguments, [RuxGiveaway::ACTIVE]), [
            'ends_at' => $this->string($arguments, 'ends_at'),
            'credits' => $this->int($arguments, 'credits'),
            'winners' => $this->int($arguments, 'winners'),
            'title' => $this->string($arguments, 'title'),
        ]);

        return ToolResult::ok(['updated' => true] + $this->giveaways->summary($giveaway));
    }
}
