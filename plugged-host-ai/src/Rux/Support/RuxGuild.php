<?php

namespace PluggedHost\Ai\Rux\Support;

/**
 * One Discord server rux works on (Contracts\RuxSettingsSource).
 *
 * name and about say who the server belongs to in rux's prompts ("the <name> Discord server",
 * "<name> is <about>"): by default the panel's name and "a free game server host".
 * trustedDomains are the sites the message checks treat as the host's own on this server (each
 * with its subdomains), besides the panel's, the status page's and the Discord invite.
 * locations: whether the checks are told the server locations of this panel's nodes (only true
 * where the server is this panel's own community).
 */
final class RuxGuild
{
    public const DEFAULT_ABOUT = 'a free game server host';

    /**
     * @param  list<string>  $trustedDomains
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $primary = false,
        public readonly ?string $name = null,
        public readonly ?string $about = null,
        public readonly ?string $giveawayChannelId = null,
        public readonly ?string $modLogChannelId = null,
        public readonly array $trustedDomains = [],
        public readonly bool $locations = false,
    ) {}

    /** The host this server belongs to, as rux's prompts name it. */
    public function hostName(): string
    {
        return $this->name ?? (string) config('app.name');
    }

    /** What that host is, as in "<name> is <about>". */
    public function about(): string
    {
        return $this->about ?? self::DEFAULT_ABOUT;
    }

    /** "plugged.host (Discord server 1502647500295635085)" */
    public function label(): string
    {
        return $this->hostName() . ' (Discord server ' . $this->id . ')';
    }
}
