<?php

namespace PluggedHost\Ai\Rux\Tools\Giveaways;

use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

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
        $giveaway = $this->giveaways->cancel($this->giveaway($context, $arguments, [RuxGiveaway::ACTIVE]));

        return ToolResult::ok(['cancelled' => true] + $this->giveaways->summary($giveaway));
    }
}
