<?php

namespace PluggedHost\FiveM\Filament\Server\Pages;

use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use PluggedHost\FiveM\Support\FiveM;

/**
 * Shared base for the pages that only make sense on a FiveM (cfx) server.
 *
 * Abstract, so Filament's page discovery skips it. The conflict check is inlined for the same
 * reason MinecraftPage inlines it: every subclass adds its own permission check in canAccess(),
 * and a trait's canAccess() would be silently overridden by that.
 */
abstract class FiveMPage extends Page
{
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server || !FiveM::isServer($server) || $server->isInConflictState()) {
            return false;
        }

        return parent::canAccess();
    }

    public function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected static function can(\App\Enums\SubuserPermission ...$permissions): bool
    {
        $server = Filament::getTenant();

        foreach ($permissions as $permission) {
            if (!user()?->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }
}
