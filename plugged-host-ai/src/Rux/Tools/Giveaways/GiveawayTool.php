<?php

namespace PluggedHost\Ai\Rux\Tools\Giveaways;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Giveaways\GiveawayService;
use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;

abstract class GiveawayTool extends RuxTool
{
    public function __construct(protected readonly GiveawayService $giveaways) {}

    /**
     * The giveaway the model named, or the only one in $statuses when it named none; only
     * giveaways of the order's server.
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $statuses
     */
    protected function giveaway(RuxContext $context, array $arguments, array $statuses): RuxGiveaway
    {
        $id = $this->int($arguments, 'giveaway_id');

        if ($id !== null) {
            return RuxGiveaway::query()->where('guild_id', $context->guildId)->find($id) ?? throw new InvalidArgumentException("There is no giveaway #{$id} on this server. Use list_giveaways.");
        }

        $candidates = RuxGiveaway::query()->where('guild_id', $context->guildId)->whereIn('status', $statuses)->latest('id')->limit(6)->get();

        return match ($candidates->count()) {
            1 => $candidates->first(),
            0 => throw new InvalidArgumentException('There is no ' . implode(' or ', $statuses) . ' giveaway.'),
            default => throw new InvalidArgumentException('Several giveaways could be meant: ' . $candidates->map(fn (RuxGiveaway $g): string => "#{$g->id} {$g->prize} ({$g->status})")->implode(', ') . '. Ask which one, or pass giveaway_id.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function idProperty(): array
    {
        return ['type' => 'integer', 'description' => 'Giveaway number. Leave out when there is only one that fits.'];
    }
}
