<?php

namespace PluggedHost\Ai\Rux\Discord;

use PluggedHost\Ai\Discord\DiscordRest;
use PluggedHost\Ai\Rux\Support\RuxConfig;

/**
 * Discord's REST API with rux's own token.
 */
class RuxDiscord extends DiscordRest
{
    public static function fromConfig(): self
    {
        return new self(RuxConfig::token(), 'rux');
    }
}
