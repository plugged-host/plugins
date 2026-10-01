<?php

namespace PluggedHost\Rux\Tools\Giveaways;

use PluggedHost\Rux\Models\RuxGiveaway;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class CancelGiveawayTool extends GiveawayTool
{
    public function name(): string
    {
        return 'cancel_giveaway';
    }

    public function description(): string
    {
        return 'Cancel a running giveaway: nobody wins, nothing is paid.';
    }

    public function parameters(): array
    {
        return $this->schema(['giveaway_id' => $this->idProperty()]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $giveaway = $this->giveaways->cancel($this->giveaway($arguments, [RuxGiveaway::ACTIVE]));

        return ToolResult::ok(['cancelled' => true] + $this->giveaways->summary($giveaway));
    }
}
