<?php

namespace PluggedHost\Minecraft\Filament\Server\Pages;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Minecraft\Services\MinecraftVersionCatalog;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;

/**
 * Shared base for the pages that only make sense on a Minecraft: Java server.
 *
 * "Only makes sense" is decided by the catalog config, not by hard-coding an egg uuid:
 * a page appears when the tenant's egg declares software variants that Modrinth knows
 * about (see ModrinthCatalog::LOADERS). Import the egg on another panel, or add a second
 * Minecraft egg, and these pages follow automatically.
 *
 * Abstract, so Filament's page discovery skips it (Panel/Concerns/HasComponents.php:535).
 *
 * The conflict check is inlined rather than taken from App\Traits\Filament\
 * BlockAccessInConflict: a canAccess() declared in a class body silently overrides the
 * trait's, and every subclass here needs its own canAccess() to add a permission check.
 * Doing it in one place makes that impossible to get wrong — and it has to tolerate a null
 * tenant, which the trait does not.
 */
abstract class MinecraftPage extends Page
{
    public static function canAccess(): bool
    {
        $server = static::minecraftServer();

        if (!$server || $server->isInConflictState()) {
            return false;
        }

        return parent::canAccess();
    }

    /**
     * The tenant, but only when it is a Minecraft server this plugin can act on.
     */
    protected static function minecraftServer(): ?Server
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        if (!$server || !$server->exists) {
            return null;
        }

        return static::softwareFor($server) !== null ? $server : null;
    }

    /**
     * The loader the server runs ("purpur", "fabric", …), or null when the egg declares no
     * variants or declares ones Modrinth has no categories for.
     */
    protected static function softwareFor(Server $server): ?string
    {
        if (app(GameCatalog::class)->typesFor($server->egg) === null) {
            return null;
        }

        $software = app(ServerSoftwareSwitcher::class)->loader($server);

        return app(ModrinthCatalog::class)->loaders($software) === [] ? null : $software;
    }

    public function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public function software(): ?string
    {
        return static::softwareFor($this->getServer());
    }

    /**
     * The Minecraft version to filter content by.
     *
     * A server tracking "latest" has no pinned version, so resolve it to the newest version
     * the loader publishes — which is what "latest" will install on the next reinstall.
     * Null means "could not tell", and every caller treats that as "do not filter" rather
     * than as an empty result.
     */
    public function gameVersion(): ?string
    {
        $version = app(ServerSoftwareSwitcher::class)->version($this->getServer());

        if ($version !== MinecraftVersionCatalog::LATEST) {
            return $version;
        }

        $software = $this->software();

        if ($software === null) {
            return null;
        }

        return app(MinecraftVersionCatalog::class)->versions($software)[0] ?? null;
    }

    protected function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->getServer());
    }
}
