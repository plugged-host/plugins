<?php

namespace PluggedHost\Servers\Services;

use App\Models\Egg;
use Illuminate\Support\Collection;

/**
 * The set of games (eggs) a user may provision, plus the per-game "type" variants
 * (Purpur / Fabric / Forge / NeoForge for Minecraft Java).
 *
 * Variants are declared in config('plugged-host-servers.user-creatable-servers.catalog')
 * keyed by egg *uuid* rather than id, so the mapping survives re-importing an egg on
 * another panel where auto-increment ids differ.
 */
class GameCatalog
{
    /**
     * An egg carrying this tag is never offered in the creation flow.
     *
     * This is the escape hatch for eggs that must keep existing — because servers are
     * still running on them — but should no longer be deployable. Tagging is preferable
     * to the allowed_eggs setting for that: allowed_eggs is an allowlist, so excluding
     * one egg there means enumerating every other egg and then remembering to extend
     * that list every time a new egg is added.
     *
     * Filtering here (rather than only in games()) also covers find(), so a hand-typed
     * /create-server/{egg} URL cannot reach a hidden egg either. Existing servers are
     * unaffected — nothing on the server panel goes through this catalog's egg list.
     */
    public const HIDDEN_TAG = 'hidden';

    /**
     * The eggs shown in the creation grid, honouring the allowed_eggs setting.
     *
     * @return Collection<int, array{egg: Egg, id: int, name: string, description: ?string, icon: ?string, has_types: bool}>
     */
    public function games(): Collection
    {
        return $this->eggs()
            ->map(fn (Egg $egg) => [
                'egg' => $egg,
                'id' => $egg->id,
                'name' => $egg->name,
                'description' => $egg->description,
                'icon' => $egg->icon,
                'has_types' => $this->typesFor($egg) !== null,
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * Resolve a game from the route parameter, or null when it is not offered.
     * Accepts an egg id or uuid; validating against eggs() is what stops a
     * hand-crafted URL from reaching an egg outside allowed_eggs.
     */
    public function find(int|string|null $key): ?Egg
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->eggs()->first(function (Egg $egg) use ($key) {
            if (is_numeric($key)) {
                return $egg->id === (int) $key;
            }

            return strcasecmp((string) $egg->uuid, (string) $key) === 0;
        });
    }

    /**
     * The type variants for an egg, or null when the egg has none — which is how
     * the configure page knows to skip the type step entirely.
     *
     * @return array{variable: string, types: array<int, array{value: string, label: string, description: ?string, startup: ?string, image: ?string}>}|null
     */
    public function typesFor(Egg $egg): ?array
    {
        $catalog = (array) config('plugged-host-servers.user-creatable-servers.catalog', []);

        $entry = null;
        foreach ($catalog as $uuid => $candidate) {
            if (strcasecmp((string) $uuid, (string) $egg->uuid) === 0) {
                $entry = $candidate;
                break;
            }
        }

        if (!is_array($entry) || empty($entry['types']) || !is_array($entry['types'])) {
            return null;
        }

        $types = [];
        foreach ($entry['types'] as $value => $type) {
            if (!is_array($type)) {
                continue;
            }

            $types[] = [
                'value' => (string) $value,
                'label' => $this->translate($type['label'] ?? null) ?? (string) $value,
                'description' => $this->translate($type['description'] ?? null),
                'startup' => isset($type['startup']) ? (string) $type['startup'] : null,
                'image' => isset($type['image']) ? (string) $type['image'] : null,
            ];
        }

        if (!$types) {
            return null;
        }

        return [
            'variable' => (string) ($entry['variable'] ?? ''),
            'types' => $types,
        ];
    }

    /**
     * Look up a single type variant by its value (e.g. "forge").
     *
     * @return array{value: string, label: string, description: ?string, startup: ?string, image: ?string}|null
     */
    public function type(Egg $egg, ?string $value): ?array
    {
        $catalog = $this->typesFor($egg);

        if (!$catalog || $value === null) {
            return null;
        }

        foreach ($catalog['types'] as $type) {
            if ($type['value'] === $value) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Resolve a type's startup command and docker image to the concrete values the
     * egg carries, so ServerCreationService is handed real strings rather than keys.
     *
     * @return array{startup: ?string, image: ?string}
     */
    public function resolveRuntime(Egg $egg, ?string $typeValue): array
    {
        $type = $this->type($egg, $typeValue);

        if (!$type) {
            return ['startup' => null, 'image' => null];
        }

        $startupCommands = (array) ($egg->startup_commands ?? []);
        $dockerImages = (array) ($egg->docker_images ?? []);

        return [
            'startup' => $type['startup'] !== null ? ($startupCommands[$type['startup']] ?? null) : null,
            'image' => $type['image'] !== null ? ($dockerImages[$type['image']] ?? null) : null,
        ];
    }

    /** @return Collection<int, Egg> */
    private function eggs(): Collection
    {
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('plugged-host-servers.user-creatable-servers.allowed_eggs'))
        )));

        $query = Egg::query();

        if ($allowed) {
            $query->whereIn('id', $allowed);
        }

        // Hidden eggs are filtered in PHP rather than with a JSON where clause: tags is
        // an array cast over a JSON column, and there are only a handful of eggs, so
        // this avoids depending on MariaDB's JSON path behaviour for a missing value.
        return $query->get()->reject(fn (Egg $egg) => $this->isHidden($egg))->values();
    }

    /**
     * Whether an egg is tagged out of the creation flow. Compared case-insensitively so
     * a tag typed as "Hidden" in the admin UI still counts.
     */
    private function isHidden(Egg $egg): bool
    {
        foreach ((array) ($egg->tags ?? []) as $tag) {
            if (is_string($tag) && strcasecmp(trim($tag), self::HIDDEN_TAG) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Config values hold translation keys; fall back to the literal string so a
     * plain label in config still renders.
     */
    private function translate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $translated = trans($value);

        return is_string($translated) ? $translated : $value;
    }
}
