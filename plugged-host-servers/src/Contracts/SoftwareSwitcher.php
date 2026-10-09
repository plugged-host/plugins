<?php

namespace PluggedHost\Servers\Contracts;

use App\Models\Server;

/**
 * Changing what a server runs, for the "Server software" section of the settings page: the
 * type (servers' GameCatalog lists the types per egg) and its version. A game plugin binds it
 * (plugged-host-minecraft: loaders and Minecraft versions); without one the section is not shown.
 */
interface SoftwareSwitcher
{
    /** The version a fresh choice of type starts at. */
    public function latest(): string;

    /**
     * Versions offered for this type, value => label.
     *
     * @return array<string, string>
     */
    public function versions(string $type): array;

    public function supports(string $type, string $version): bool;

    /** The type the server runs now, or null when it cannot be told. */
    public function current(Server $server): ?string;

    public function currentVersion(Server $server): string;

    /** Switch and reinstall. */
    public function apply(Server $server, string $type, string $version): void;
}
