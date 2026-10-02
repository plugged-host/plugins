<?php

namespace PluggedHost\Ai\NeoBot;

use PluggedHost\Ai\Discord\DiscordRest;

/**
 * Discord's REST API with neo's own token.
 */
class NeoBotDiscord extends DiscordRest
{
    public static function fromConfig(): self
    {
        return new self(NeoBotConfig::token(), 'neo');
    }
}
