<?php

namespace PluggedHost\Minecraft\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Support\UserAgent;
use Throwable;

/**
 * The Minecraft versions each server software can actually be installed on.
 *
 * Free-text version entry produced install failures that only surfaced minutes later in
 * the install log: Purpur has no builds before 1.14.1, Fabric none before 1.14, and the
 * Forge/NeoForge promotion feeds only carry the versions they built for. Offering a list
 * per loader means an impossible combination cannot be requested in the first place.
 *
 * The lists come from the same upstream APIs the egg's install script downloads from, so
 * what is offered here and what the script can fetch cannot drift apart.
 *
 * Every lookup is cached — these feeds change a few times a month, and a form render must
 * not depend on four third-party APIs being up. On failure the caller gets an empty list
 * and keeps whatever version the server already has (see the Version tab on ServerSettings).
 */
class MinecraftVersionCatalog
{
    /**
     * Sentinel offered as the first choice for every loader. The install script resolves
     * it at install time, which is what a user who just wants "current" actually means.
     */
    public const LATEST = 'latest';

    /**
     * Feeds change a few times a month; an hour of staleness costs a user nothing and
     * keeps the panel responsive when an upstream API is slow.
     */
    protected const TTL = 3600;

    protected const TIMEOUT = 8;

    /**
     * Versions for a loader, newest first, excluding "latest".
     *
     * @return array<int, string>
     */
    public function versions(string $loader): array
    {
        $loader = strtolower(trim($loader));

        if (!in_array($loader, ['purpur', 'fabric', 'forge', 'neoforge'], true)) {
            return [];
        }

        $cached = Cache::remember(
            "plugged-host-core:mc-versions:$loader",
            self::TTL,
            function () use ($loader) {
                try {
                    return $this->fetch($loader);
                } catch (Throwable $exception) {
                    report($exception);

                    // Cache::remember would store null and re-fetch on every render, so
                    // return an empty array and let the short TTL retry instead of
                    // hammering a failing API once per keystroke.
                    return [];
                }
            }
        );

        return is_array($cached) ? $cached : [];
    }

    /**
     * Versions as a Filament select option map, "latest" first.
     *
     * @return array<string, string>
     */
    public function options(string $loader): array
    {
        $options = [self::LATEST => trans('plugged-host-minecraft::strings.version_latest')];

        foreach ($this->versions($loader) as $version) {
            $options[$version] = $version;
        }

        return $options;
    }

    /**
     * Whether a version can be installed for a loader.
     *
     * An unknown version is accepted when the list could not be fetched at all — the
     * install script validates again and falls back to latest, so an upstream outage
     * must not block a user from reinstalling what they already run.
     */
    public function supports(string $loader, string $version): bool
    {
        if ($version === self::LATEST) {
            return true;
        }

        $versions = $this->versions($loader);

        return $versions === [] || in_array($version, $versions, true);
    }

    /**
     * @return array<int, string>
     */
    protected function fetch(string $loader): array
    {
        $versions = match ($loader) {
            'purpur' => $this->fetchPurpur(),
            'fabric' => $this->fetchFabric(),
            'forge' => $this->fetchForge(),
            'neoforge' => $this->fetchNeoForge(),
        };

        return $this->normalise($versions);
    }

    /**
     * Purpur's own range starts at 1.14.1. The install script silently falls back to
     * Paper below that (see needs_paper in the egg's install script), so the offered
     * range is the union — every entry really is installable by the tile the storefront
     * markets as "Vanilla / Paper / Spigot / Purpur".
     *
     * @return array<int, string>
     */
    protected function fetchPurpur(): array
    {
        $purpur = (array) $this->json('https://api.purpurmc.org/v2/purpur')['versions'] ?? [];

        return array_merge($purpur, $this->fetchPaper());
    }

    /**
     * @return array<int, string>
     */
    protected function fetchPaper(): array
    {
        $versions = $this->json('https://fill.papermc.io/v3/projects/paper')['versions'] ?? [];

        // v3 groups versions by minor family; older shapes returned a flat list.
        if (!is_array($versions)) {
            return [];
        }

        $flat = [];

        array_walk_recursive($versions, function ($value) use (&$flat) {
            if (is_string($value)) {
                $flat[] = $value;
            }
        });

        return $flat;
    }

    /**
     * @return array<int, string>
     */
    protected function fetchFabric(): array
    {
        $games = (array) $this->json('https://meta.fabricmc.net/v2/versions/game');

        return array_values(array_map(
            fn (array $entry) => (string) ($entry['version'] ?? ''),
            array_filter($games, fn ($entry) => is_array($entry) && ($entry['stable'] ?? false))
        ));
    }

    /**
     * Forge keys its promotions as "<mc-version>-<latest|recommended>", so the Minecraft
     * versions are the distinct prefixes.
     *
     * @return array<int, string>
     */
    protected function fetchForge(): array
    {
        $promos = (array) ($this->json('https://files.minecraftforge.net/maven/net/minecraftforge/forge/promotions_slim.json')['promos'] ?? []);

        return array_values(array_map(
            fn (string $key) => explode('-', $key)[0],
            array_keys($promos)
        ));
    }

    /**
     * NeoForge publishes only a Maven metadata document, and its artifact versions encode
     * the Minecraft version without the leading "1." — 21.6.13 is for 1.21.6. That scheme
     * broke when Minecraft renumbered to 26.x: 26.2.0.67 is for 26.2, not 1.26.2.
     *
     * Rather than guess, each artifact is resolved against Mojang's own release manifest,
     * trying the most specific candidate first so 26.1.2.95 maps to 26.1.2 and not 26.1.
     *
     * @return array<int, string>
     */
    protected function fetchNeoForge(): array
    {
        $xml = Http::timeout(self::TIMEOUT)
            ->connectTimeout(4)
            ->withHeaders(['User-Agent' => UserAgent::get()])
            ->get('https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml')
            ->throw()
            ->body();

        preg_match_all('#<version>([^<]+)</version>#', $xml, $matches);

        $releases = $this->mojangReleases();
        $versions = [];

        foreach ($matches[1] ?? [] as $artifact) {
            // Strip the "-beta" / "-rc" suffix before splitting.
            $parts = explode('.', explode('-', trim($artifact))[0]);

            // Both leading components must be numeric. This rules out April Fools
            // artifacts such as "0.25w14craftmine.3-beta", whose numeric first component
            // would otherwise fall through to the "1.0" candidate and offer a version
            // NeoForge never built for.
            if (!isset($parts[0], $parts[1]) || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                continue;
            }

            $candidates = [];

            if (isset($parts[2]) && ctype_digit($parts[2])) {
                $candidates[] = "$parts[0].$parts[1].$parts[2]";
            }

            $candidates[] = "$parts[0].$parts[1]";
            $candidates[] = "1.$parts[0].$parts[1]";
            $candidates[] = "1.$parts[0]";

            foreach ($candidates as $candidate) {
                // With no manifest to check against, fall back to the historical scheme
                // rather than dropping the loader's list entirely.
                if ($releases === [] ? str_starts_with($candidate, '1.') : in_array($candidate, $releases, true)) {
                    $versions[] = $candidate;
                    break;
                }
            }
        }

        // NeoForge's 1.20.1 build lives in a separate repository under a different
        // artifact name; the install script special-cases it, so offer it too.
        $versions[] = '1.20.1';

        return $versions;
    }

    /**
     * Mojang's canonical list of Minecraft *release* versions. Cached separately from the
     * per-loader lists because several loaders resolve against it.
     *
     * @return array<int, string>
     */
    protected function mojangReleases(): array
    {
        $cached = Cache::remember('plugged-host-core:mc-versions:mojang', self::TTL, function () {
            try {
                $versions = (array) ($this->json('https://launchermeta.mojang.com/mc/game/version_manifest_v2.json')['versions'] ?? []);

                return array_values(array_map(
                    fn (array $entry) => (string) ($entry['id'] ?? ''),
                    array_filter($versions, fn ($entry) => is_array($entry) && ($entry['type'] ?? null) === 'release')
                ));
            } catch (Throwable $exception) {
                report($exception);

                return [];
            }
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * Keep only stable release numbers, de-duplicate, and sort newest first.
     *
     * @param  array<int, mixed>  $versions
     * @return array<int, string>
     */
    protected function normalise(array $versions): array
    {
        $clean = [];

        foreach ($versions as $version) {
            if (!is_string($version)) {
                continue;
            }

            $version = trim($version);

            // Drops release candidates ("26.2-rc-2"), pre-releases ("1.21.11-pre1") and
            // weekly snapshots ("24w14a") — none of which belong in a storefront list.
            if (preg_match('/^\d+(\.\d+){0,2}$/', $version) !== 1) {
                continue;
            }

            $clean[$version] = true;
        }

        $clean = array_keys($clean);

        usort($clean, fn (string $a, string $b) => $this->compare($b, $a));

        return array_values($clean);
    }

    /**
     * Compare two Minecraft versions.
     *
     * Minecraft renumbered straight from 1.21.x to 26.x, so a plain numeric comparison
     * puts 26.2 *below* 1.21.11. Anything still on the old "1.x" scheme is therefore
     * older than anything on the new one, by definition.
     */
    protected function compare(string $a, string $b): int
    {
        $legacy = fn (string $v) => str_starts_with($v, '1.') || $v === '1' ? 0 : 1;

        if ($legacy($a) !== $legacy($b)) {
            return $legacy($a) <=> $legacy($b);
        }

        return version_compare($a, $b);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function json(string $url): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->connectTimeout(4)
            ->withHeaders(['User-Agent' => UserAgent::get()])
            ->get($url)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }
}
