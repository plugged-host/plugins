<?php

namespace PluggedHost\FiveM\Support;

use App\Models\Egg;
use App\Models\Server;

/**
 * Which servers this plugin is for, and the image they run on.
 *
 * A FiveM server is one whose egg carries the `cfx` tag: the same rule servers' provisioning
 * (two allocations) and subdomains' SRV records go by, so an imported or second Cfx egg is picked
 * up without naming an egg id anywhere. RedM shares the egg and the artifact; the pages that only
 * make sense for FiveM (Enhanced) ask TxAdminData::isRedM() on top of this.
 */
final class FiveM
{
    /** Egg variables the image reads (fivem-runtime). */
    public const VERSION = 'FIVEM_VERSION';

    public const EDITION = 'FIVEM_EDITION';

    public const LEGACY = 'legacy';

    public const ENHANCED = 'enhanced';

    /** Channels FIVEM_VERSION can follow; anything else is a build number. */
    public const CHANNELS = ['recommended', 'latest'];

    public static function isCfxEgg(?Egg $egg): bool
    {
        $tags = $egg?->tags;

        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }

        if (!is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if ((is_string($tag) || is_numeric($tag)) && strtolower(trim((string) $tag)) === 'cfx') {
                return true;
            }
        }

        return false;
    }

    public static function isServer(?Server $server): bool
    {
        return $server !== null && $server->exists && self::isCfxEgg($server->egg);
    }

    /** The image as configured, `~` (Wings: do not pull) and all. */
    public static function image(): string
    {
        return (string) config('plugged-host-fivem.image', 'ghcr.io/plugged-host/fivem:2.2');
    }

    /**
     * Whether a server runs the plugged.host image, so its start keeps the artifact on what
     * FIVEM_VERSION / FIVEM_EDITION say. Compared without the `~` and the tag: a server on an
     * older 2.x tag of the same image still has fivem-runtime.
     */
    public static function onImage(Server $server): bool
    {
        $repository = fn (string $image): string => preg_replace('/:[^\/:]+$/', '', ltrim($image, '~')) ?? '';

        return $repository((string) $server->image) === $repository(self::image());
    }
}
