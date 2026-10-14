<?php

namespace PluggedHost\FiveM\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The frameworks the installer offers: txAdmin's own recipe index (the list txAdmin's setup
 * shows under "Popular recipes"), each recipe fetched and parsed so the page can show its version
 * and author and say whether it needs a database.
 *
 * Only recipes from that index ever run. The page names a recipe by its index URL and the job
 * looks it up here again, so nothing a browser sends becomes a recipe URL.
 */
class RecipeCatalog
{
    private const CACHE = 'plugged-host-fivem:recipes:';

    /**
     * The recipes for a game ('fivem' or 'redm'), in the index's order.
     *
     * @return array<int, array{id: string, url: string, name: string, description: string, author: ?string, version: ?string, logo: ?string, needs_database: bool, tasks: int}>
     */
    public function forGame(string $game): array
    {
        $recipes = [];

        foreach ($this->index() as $entry) {
            if (!in_array($game, (array) ($entry['tags'] ?? []), true)) {
                continue;
            }

            $recipe = $this->recipe((string) $entry['url']);

            if ($recipe === null) {
                continue;
            }

            $recipes[] = [
                'id' => self::id((string) $entry['url']),
                'url' => (string) $entry['url'],
                'name' => (string) ($recipe['name'] ?? $entry['name'] ?? 'Recipe'),
                'description' => (string) ($entry['description'] ?? $recipe['description'] ?? ''),
                'author' => is_string($recipe['author'] ?? null) ? $recipe['author'] : null,
                'version' => is_scalar($recipe['version'] ?? null) ? (string) $recipe['version'] : null,
                'logo' => self::logo((string) $entry['url']),
                'needs_database' => self::needsDatabase($recipe),
                'tasks' => count((array) ($recipe['tasks'] ?? [])),
            ];
        }

        return $recipes;
    }

    /**
     * One recipe from the index by its id, with its parsed body.
     *
     * @return array{entry: array<string, mixed>, recipe: array<string, mixed>}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->index() as $entry) {
            if (self::id((string) ($entry['url'] ?? '')) === $id) {
                $recipe = $this->recipe((string) $entry['url']);

                return $recipe === null ? null : ['entry' => $entry, 'recipe' => $recipe];
            }
        }

        return null;
    }

    public static function id(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $recipe
     */
    public static function needsDatabase(array $recipe): bool
    {
        foreach ((array) ($recipe['tasks'] ?? []) as $task) {
            if (in_array($task['action'] ?? null, ['connect_database', 'query_database'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The GitHub owner's avatar (the framework's org logo for every official recipe).
     */
    private static function logo(string $url): ?string
    {
        if (preg_match('#^https://(?:raw\.githubusercontent\.com|github\.com)/([A-Za-z0-9-]+)/#', $url, $match) !== 1) {
            return null;
        }

        return 'https://github.com/' . $match[1] . '.png?size=96';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function index(): array
    {
        $index = $this->remember('index', function (): ?array {
            $json = Http::timeout(15)->connectTimeout(5)->get((string) config('plugged-host-fivem.recipe_index'))->throw()->json();

            return is_array($json) && array_is_list($json) ? $json : null;
        });

        return array_values(array_filter($index, fn ($entry) => is_array($entry)
            && is_string($entry['url'] ?? null)
            && str_starts_with($entry['url'], 'https://')
            && (int) ($entry['engine'] ?? 0) === 3));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recipe(string $url): ?array
    {
        $recipe = $this->remember('recipe:' . self::id($url), function () use ($url): ?array {
            $yaml = Http::timeout(15)->connectTimeout(5)->get($url)->throw()->body();
            $recipe = Yaml::parse($yaml);

            return is_array($recipe) && is_array($recipe['tasks'] ?? null) ? $recipe : null;
        });

        return $recipe === [] ? null : $recipe;
    }

    /**
     * Cached for cache_ttl, with the last good copy kept for a day for when GitHub is slow.
     *
     * @param  callable(): ?array<mixed>  $fetch
     * @return array<mixed>
     */
    private function remember(string $key, callable $fetch): array
    {
        $fresh = Cache::get(self::CACHE . $key);

        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $value = $fetch();
        } catch (Throwable) {
            $value = null;
        }

        if ($value === null) {
            $stale = Cache::get(self::CACHE . $key . ':last');

            return is_array($stale) ? $stale : [];
        }

        Cache::put(self::CACHE . $key, $value, (int) config('plugged-host-fivem.cache_ttl', 600));
        Cache::put(self::CACHE . $key . ':last', $value, now()->addDay());

        return $value;
    }
}
