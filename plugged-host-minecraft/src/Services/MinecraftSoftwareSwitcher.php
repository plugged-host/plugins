<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use PluggedHost\Servers\Contracts\SoftwareSwitcher;

/**
 * servers' settings page switching a Minecraft server's loader and version.
 */
final class MinecraftSoftwareSwitcher implements SoftwareSwitcher
{
    public function __construct(
        private readonly MinecraftVersionCatalog $catalog,
        private readonly ServerSoftwareSwitcher $switcher,
    ) {}

    public function latest(): string
    {
        return MinecraftVersionCatalog::LATEST;
    }

    public function versions(string $type): array
    {
        return $this->catalog->options($type);
    }

    public function supports(string $type, string $version): bool
    {
        return $this->catalog->supports($type, $version);
    }

    public function current(Server $server): ?string
    {
        return $this->switcher->loader($server);
    }

    public function currentVersion(Server $server): string
    {
        return $this->switcher->version($server);
    }

    public function apply(Server $server, string $type, string $version): void
    {
        $this->switcher->apply($server, $type, $version);
    }
}
