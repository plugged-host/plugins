<?php

namespace PluggedHost\Minecraft\Services;

use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Notifications\Notification;
use Throwable;

/**
 * One-click Geyser + Floodgate (WP6), composed entirely from ModrinthCatalog (fact 19): Geyser
 * and Floodgate are both Modrinth projects, so this is composition, not new plumbing.
 *
 * Offered on loaders that have a Geyser build (Paper/Purpur → spigot, Fabric → fabric,
 * NeoForge → neoforge). Idempotent: if a Geyser jar is already present the action becomes
 * "update" and never installs twice (WP8 detects it).
 *
 * VERIFY IN PHASE C: that wings maps the Bedrock UDP port for the extra allocation (Pelican
 * allocations carry no protocol column). If wings maps TCP only, the Bedrock listener needs a
 * firewall/port decision on the node — an operator call, not a code change.
 */
class GeyserService
{
    private const GEYSER = 'geyser';
    private const FLOODGATE = 'floodgate';
    private const DEFAULT_BEDROCK_PORT = 19132;

    /** GeyserMC's official build API, the fallback source when Modrinth carries no build. */
    private const GEYSERMC_DOWNLOAD = 'https://download.geysermc.org/v2/projects';

    /**
     * The plugged.host loaders Geyser publishes a build for. Paper-family (purpur) uses the
     * Spigot build, Fabric and NeoForge have their own; plain Forge has none. Kept as a static
     * map so a caller that only has a *chosen* loader — the onboarding wizard, before any
     * reinstall — can gate a step without a Modrinth round-trip. The live check that a build
     * actually resolves for the server's Minecraft version is still available() / status().
     */
    private const SUPPORTED_LOADERS = ['purpur', 'fabric', 'neoforge'];

    public function __construct(
        private readonly ModrinthCatalog $catalog,
        private readonly ServerSoftwareSwitcher $switcher,
    ) {}

    /** Whether a loader (by plugged.host key) has any Geyser build — no network call. */
    public static function loaderSupported(?string $loader): bool
    {
        return in_array(strtolower(trim((string) $loader)), self::SUPPORTED_LOADERS, true);
    }

    /**
     * Whether this server's loader has a Geyser build to offer. A pure loader→platform check
     * with no network call: GeyserMC publishes a Geyser build for every platform we map, so
     * "supported loader" and "has a build" are the same question — which also keeps the sidebar
     * fast, since this runs on every server-panel render to decide the button's visibility.
     */
    public function available(Server $server): bool
    {
        return $this->geyserMcPlatform($this->switcher->loader($server)) !== null;
    }

    /**
     * install | update | unavailable — drives the button label (WP8 feeds "installed").
     */
    public function status(Server $server): string
    {
        if (!$this->available($server)) {
            return 'unavailable';
        }

        return $this->installedJar($server) !== null ? 'update' : 'install';
    }

    /**
     * Install (or update) Geyser + Floodgate. Returns a small result the caller can notify from.
     *
     * @return array{ok: bool, reason?: string, updated?: bool}
     */
    public function install(Server $server, ?int $userId = null): array
    {
        $loader = $this->switcher->loader($server);
        $platform = $this->geyserMcPlatform($loader);

        if ($platform === null) {
            return ['ok' => false, 'reason' => 'unsupported_loader'];
        }

        // Geyser always comes from GeyserMC's own build API rather than Modrinth: it is the
        // canonical source, publishes a build for every platform we support, and sidesteps
        // Modrinth's game-version tagging (which hid the button on "latest" servers).
        $geyser = $this->geyserFile($platform);

        $updating = $this->installedJar($server) !== null;
        $dir = $loader === 'purpur' ? 'plugins' : 'mods';
        $files = app(DaemonFileRepository::class)->setServer($server);

        try {
            $this->ensureDirectory($files, $dir);

            // Remove an old Geyser jar first so an update does not leave two on disk.
            if ($updating) {
                try {
                    $files->deleteFiles('/' . $dir, [$this->installedJar($server)]);
                } catch (Throwable) {
                    // best-effort
                }
            }

            $files->pull($geyser['url'], '/' . $dir, ['filename' => $geyser['filename']]);

            // Floodgate: GeyserMC's downloads for the Spigot family, Modrinth for Fabric/NeoForge
            // (GeyserMC's API 404s on those two — Floodgate ships there as a Modrinth-only mod).
            $floodgate = $this->floodgateFile($loader, $platform, $server);
            if ($floodgate !== null) {
                $files->pull($floodgate['url'], '/' . $dir, ['filename' => $floodgate['filename']]);
            }

            $this->writeConfig($files, $dir, $server, $floodgate !== null);
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'reason' => 'download_failed'];
        }

        try {
            app(DaemonServerRepository::class)->setServer($server)->power('restart');
        } catch (Throwable) {
            // A failed restart is not fatal — the jars are in place for the next boot.
        }

        try {
            Activity::event('server:minecraft.geyser.install')
                ->subject($server)
                ->property('updated', $updating)
                ->property('floodgate', $floodgate !== null)
                ->log();
        } catch (Throwable) {
            //
        }

        $this->tell($server, $userId, $updating);

        return ['ok' => true, 'updated' => $updating];
    }

    // --- internals ----------------------------------------------------------

    /**
     * The Minecraft version to filter Geyser/Floodgate builds by. A server tracking "latest" has
     * no pinned version, and Modrinth has no game version literally called "latest" — filtering by
     * it matched zero builds and left the button hidden on every default server. Null there (no
     * filter) yields the newest build, which is exactly what a "latest" server runs; a concrete
     * pin is passed through so an older server still gets a compatible build.
     */
    private function buildVersion(Server $server): ?string
    {
        $version = $this->switcher->version($server);

        return $version === MinecraftVersionCatalog::LATEST ? null : $version;
    }

    /**
     * The Geyser jar for a GeyserMC platform, always from GeyserMC's own build API.
     *
     * @return array{url: string, filename: string}
     */
    private function geyserFile(string $platform): array
    {
        return [
            'url' => self::GEYSERMC_DOWNLOAD . '/geyser/versions/latest/builds/latest/downloads/' . $platform,
            'filename' => 'Geyser-' . ucfirst($platform) . '.jar',
        ];
    }

    /**
     * The Floodgate jar for this loader.
     *
     * GeyserMC's build API serves a Floodgate download only for its server platforms — "spigot"
     * covers the whole Paper/Purpur family, which is the case that was silently broken before
     * (Modrinth has no Spigot Floodgate at all). Fabric and NeoForge are the exception: GeyserMC's
     * API 404s on them, and Floodgate ships there as a Modrinth-only mod, so those fall back to
     * Modrinth.
     *
     * @return array{url: string, filename: string}|null
     */
    private function floodgateFile(?string $loader, string $platform, Server $server): ?array
    {
        if ($platform === 'spigot') {
            return [
                'url' => self::GEYSERMC_DOWNLOAD . '/floodgate/versions/latest/builds/latest/downloads/spigot',
                'filename' => 'floodgate-spigot.jar',
            ];
        }

        return $this->primaryFileFor(self::FLOODGATE, $this->catalog->loaders($loader), $this->buildVersion($server));
    }

    /**
     * GeyserMC's own download-API platform slug for a plugged.host loader, or null for a loader
     * that has no GeyserMC build. Paper/Purpur/Spigot/Folia all take the "spigot" jar.
     */
    private function geyserMcPlatform(?string $loader): ?string
    {
        return match (strtolower(trim((string) $loader))) {
            'purpur' => 'spigot',
            'fabric' => 'fabric',
            'neoforge' => 'neoforge',
            default => null,
        };
    }

    /** @return array{url: string, filename: string}|null */
    private function primaryFileFor(string $project, array $loaders, ?string $mcVersion): ?array
    {
        try {
            $versions = $this->catalog->versions($project, $loaders, $mcVersion);
            $version = $versions[0] ?? null;

            if (!is_array($version)) {
                return null;
            }

            $file = $this->catalog->primaryFile($version);

            if (!is_array($file) || empty($file['url'])) {
                return null;
            }

            return [
                'url' => (string) $file['url'],
                'filename' => (string) ($file['filename'] ?? basename((string) $file['url'])),
            ];
        } catch (Throwable) {
            return null;
        }
    }


    /** The filename of an already-installed Geyser jar, or null. */
    private function installedJar(Server $server): ?string
    {
        $dir = $this->switcher->loader($server) === 'purpur' ? 'plugins' : 'mods';

        try {
            $listing = app(DaemonFileRepository::class)->setServer($server)->getDirectory('/' . $dir);
        } catch (Throwable) {
            return null;
        }

        foreach (is_array($listing) ? $listing : [] as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
            if ($name !== '' && stripos($name, 'geyser') !== false && str_ends_with(strtolower($name), '.jar')) {
                return $name;
            }
        }

        return null;
    }

    private function ensureDirectory(DaemonFileRepository $files, string $dir): void
    {
        try {
            $files->createDirectory($dir, '/');
        } catch (Throwable) {
            // Already exists — the normal case.
        }
    }

    /**
     * A minimal-but-valid Geyser config pointing the Bedrock listener at a dedicated UDP port and
     * telling it to authenticate through Floodgate. Geyser fills the rest with its own defaults.
     */
    private function writeConfig(DaemonFileRepository $files, string $dir, Server $server, bool $floodgate): void
    {
        $bedrockPort = $this->bedrockPort($server);
        $javaPort = (int) ($server->allocation?->port ?? 25565);
        $authType = $floodgate ? 'floodgate' : 'online';

        $yaml = <<<YAML
        # Managed by plugged.host (WP6). Geyser fills unset keys with its own defaults on start.
        bedrock:
          port: {$bedrockPort}
        remote:
          address: auto
          port: {$javaPort}
          auth-type: {$authType}
        YAML;

        $configPath = $dir === 'plugins' ? 'plugins/Geyser-Spigot/config.yml' : 'config/Geyser-Fabric/config.yml';
        $this->ensureDirectory($files, dirname($configPath));

        try {
            $files->putContent('/' . $configPath, $yaml);
        } catch (Throwable) {
            // Non-fatal: Geyser generates a default config on first start if this write fails.
        }
    }

    /**
     * Reserve a free allocation on the server's node for the Bedrock UDP listener, or fall back
     * to Geyser's default port. Same one-IP preference as the transfer/failover code.
     */
    private function bedrockPort(Server $server): int
    {
        try {
            $free = Allocation::query()
                ->where('node_id', $server->node_id)
                ->whereNull('server_id')
                ->orderBy('port')
                ->first();

            if ($free !== null) {
                $free->update(['server_id' => $server->getKey()]);

                return (int) $free->port;
            }
        } catch (Throwable) {
            //
        }

        return self::DEFAULT_BEDROCK_PORT;
    }

    private function tell(Server $server, ?int $userId, bool $updating): void
    {
        if ($userId === null) {
            return;
        }

        $user = \App\Models\User::query()->find($userId);

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title($updating ? 'Geyser updated' : 'Geyser installed')
            ->body($updating
                ? 'Geyser was updated. Restart completing now.'
                : 'Geyser and Floodgate were installed. Bedrock players can connect once the server restarts.')
            ->success()
            ->sendToDatabase($user);
    }
}
