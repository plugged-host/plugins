<?php

namespace PluggedHost\Ai\Rux\Tools\Giveaways;

use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class CreateGiveawayTool extends GiveawayTool
{
    public function name(): string
    {
        return 'create_giveaway';
    }

    public function description(): string
    {
        return 'Start a credit giveaway: posts it with an Enter button. Entering always requires a Discord account linked to noprofit.space (automatic). At the end rux draws the winners and pays them automatically.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'credits' => ['type' => 'integer', 'description' => 'Credits each winner gets.'],
            'ends_at' => ['type' => 'string', 'description' => 'When it ends: "YYYY-MM-DD HH:MM" in rux\'s time zone, or a duration from now like "2h" or "3d".'],
            'winners' => ['type' => 'integer', 'description' => 'How many winners. Default 1.'],
            'channel' => ['type' => 'string', 'description' => 'Channel to post in (#name, <#id> or id). Leave out to use the default giveaway channel.'],
            'exclude_recent_winners' => ['type' => 'integer', 'description' => 'Keep out everyone who won one of the last N giveaways. 0 = nobody kept out.'],
            'title' => ['type' => 'string', 'description' => 'Optional headline, e.g. "Halloween giveaway". Default: "<credits> credits".'],
        ], ['credits', 'ends_at']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $giveaway = $this->giveaways->create($context, [
            'credits' => (int) $this->required($arguments, 'credits'),
            'ends_at' => $this->required($arguments, 'ends_at'),
            'winners' => $this->int($arguments, 'winners') ?? 1,
            'channel' => $this->string($arguments, 'channel'),
            'exclude_recent_winners' => $this->int($arguments, 'exclude_recent_winners') ?? 0,
            'title' => $this->string($arguments, 'title'),
        ]);

        return ToolResult::ok(['created' => true] + $this->giveaways->summary($giveaway));
    }
}
