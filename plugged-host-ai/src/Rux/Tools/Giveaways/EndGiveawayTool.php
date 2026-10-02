<?php

namespace PluggedHost\Ai\Rux\Tools\Giveaways;

use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class EndGiveawayTool extends GiveawayTool
{
    public function name(): string
    {
        return 'end_giveaway';
    }

    public function description(): string
    {
        return 'End a running giveaway now: draws and pays the winners right away.';
    }

    public function parameters(): array
    {
        return $this->schema(['giveaway_id' => $this->idProperty()]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $giveaway = $this->giveaways->endNow($this->giveaway($arguments, [RuxGiveaway::ACTIVE]));

        if ($giveaway->status !== RuxGiveaway::ENDED) {
            return ToolResult::error("Giveaway #{$giveaway->id} could not be drawn yet (noprofit.space did not answer); it will be drawn automatically within a minute or two.");
        }

        return ToolResult::ok(['ended' => true] + $this->giveaways->summary($giveaway));
    }
}
