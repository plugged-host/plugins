<?php

namespace PluggedHost\Minecraft\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Read and write the config files a Minecraft server keeps in its own data directory.
 *
 * Two formats, two very different problems.
 *
 * server.properties is rewritten line by line: only the value of a key that was actually
 * edited changes, so comments, key order, blank lines and keys this panel has never heard of
 * survive untouched. That matters because the file is the one users most often have their own
 * notes in, and because a new Minecraft version adds keys faster than anyone updates a schema.
 *
 * YAML cannot be treated that way — parsing and re-dumping is the only practical round trip,
 * and it drops comments. So edits are applied onto the document that was parsed rather than
 * onto a freshly built one, which at least keeps every key, list and nested section the form
 * never showed. The page copies the original to <file>.plugged-host.bak before its first
 * write and says so in the UI.
 */
class GameConfigFile
{
    /**
     * server.properties keys the panel manages itself.
     *
     * The egg's config parser rewrites the address and port keys from the server's allocation
     * on every boot, so a value typed here would be silently reverted; rcon.password is a
     * secret that has no business being rendered into a form. All of them stay in the file —
     * the line-preserving write never touches a key it was not given.
     *
     * @var array<int, string>
     */
    public const MANAGED = [
        'server-ip',
        'server-port',
        'query.port',
        'rcon.port',
        'rcon.password',
    ];

    /**
     * @var array<int, string>
     */
    public const TOGGLES = [
        'accepts-transfers', 'allow-flight', 'allow-nether', 'broadcast-console-to-ops',
        'broadcast-rcon-to-ops', 'debug', 'enable-command-block', 'enable-jmx-monitoring',
        'enable-query', 'enable-rcon', 'enable-status', 'enforce-secure-profile',
        'enforce-whitelist', 'force-gamemode', 'generate-structures', 'hardcore',
        'hide-online-players', 'log-ips', 'online-mode', 'prevent-proxy-connections', 'pvp',
        'require-resource-pack', 'spawn-animals', 'spawn-monsters', 'spawn-npcs',
        'sync-chunk-writes', 'use-native-transport', 'white-list',
    ];

    /**
     * @var array<int, string>
     */
    public const NUMBERS = [
        'entity-broadcast-range-percentage', 'function-permission-level',
        'max-chained-neighbor-updates', 'max-players', 'max-tick-time', 'max-world-size',
        'network-compression-threshold', 'op-permission-level', 'pause-when-empty-seconds',
        'player-idle-timeout', 'rate-limit', 'simulation-distance', 'spawn-protection',
        'view-distance',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    public const CHOICES = [
        'gamemode' => ['survival', 'creative', 'adventure', 'spectator'],
        'difficulty' => ['peaceful', 'easy', 'normal', 'hard'],
        'level-type' => [
            'minecraft:normal', 'minecraft:flat', 'minecraft:large_biomes',
            'minecraft:amplified', 'minecraft:single_biome_surface',
        ],
        'region-file-compression' => ['deflate', 'lz4', 'none'],
    ];

    /**
     * A .properties file as an ordered key => value map, managed keys excluded.
     *
     * @return array<string, string>
     */
    public function properties(string $raw): array
    {
        $values = [];

        foreach ($this->lines($raw) as $line) {
            $key = $this->propertyKey($line);

            if ($key === null || in_array($key, self::MANAGED, true)) {
                continue;
            }

            $values[$key] = substr($line, strpos($line, '=') + 1);
        }

        return $values;
    }

    /**
     * Rewrite the values of the given keys, and only those.
     *
     * Everything else in the file is copied through byte for byte: comments, ordering, blank
     * lines, duplicate keys, and the managed keys the form never offered. A key that is not
     * already in the file is appended, which is how a setting the user's Minecraft version
     * does not write by default still gets set.
     *
     * @param  array<string, mixed>  $values
     */
    public function writeProperties(string $raw, array $values): string
    {
        $lines = $this->lines($raw);
        $seen = [];

        foreach ($lines as $index => $line) {
            $key = $this->propertyKey($line);

            if ($key === null || !array_key_exists($key, $values) || in_array($key, self::MANAGED, true)) {
                continue;
            }

            $lines[$index] = $key . '=' . $this->propertyValue($values[$key]);
            $seen[$key] = true;
        }

        // Appending after a trailing blank line would leave a gap in the middle of the file.
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        foreach ($values as $key => $value) {
            if (!isset($seen[$key]) && !in_array($key, self::MANAGED, true)) {
                $lines[] = $key . '=' . $this->propertyValue($value);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<int, string>
     */
    protected function lines(string $raw): array
    {
        return preg_split("/\r\n|\n|\r/", $raw) ?: [];
    }

    /**
     * The key a line sets, or null when it is a comment, blank, or not a key=value pair.
     */
    protected function propertyKey(string $line): ?string
    {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '!')) {
            return null;
        }

        $position = strpos($line, '=');

        if ($position === false || $position === 0) {
            return null;
        }

        $key = trim(substr($line, 0, $position));

        return $key === '' ? null : $key;
    }

    /**
     * A form value as Minecraft writes it: unquoted, on one line, booleans spelled out.
     *
     * Values are passed through verbatim otherwise. A .properties file is Latin-1 with
     * \\uXXXX escapes, so a value the user did not touch keeps whatever escaping Minecraft
     * wrote, and one they did type is stored as they typed it.
     */
    protected function propertyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return str_replace(["\r", "\n"], '', (string) $value);
    }

    /**
     * How to render a server.properties key: 'toggle', 'number', 'choice' or 'text'.
     */
    public function propertyType(string $key): string
    {
        return match (true) {
            in_array($key, self::TOGGLES, true) => 'toggle',
            in_array($key, self::NUMBERS, true) => 'number',
            isset(self::CHOICES[$key]) => 'choice',
            default => 'text',
        };
    }

    /**
     * @return array<string, string>
     */
    public function propertyChoices(string $key): array
    {
        $choices = self::CHOICES[$key] ?? [];

        return array_combine($choices, $choices);
    }

    /**
     * A YAML document as a nested array, or an empty array when it is empty or unparseable.
     *
     * @return array<string, mixed>
     */
    public function yaml(string $raw): array
    {
        try {
            $parsed = Yaml::parse($raw);
        } catch (Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * The scalar leaves of a document, dot-keyed, in document order.
     *
     * Lists are left out on purpose: a form cannot express "a list of maps" without inventing
     * a schema per file, and leaving them out means they survive the round trip untouched. A
     * key that itself contains a dot is skipped for the same reason — the dot path could not
     * be resolved back to it. Both are still reachable through the raw editor.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, bool|float|int|string|null>
     */
    public function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (str_contains($key, '.')) {
                continue;
            }

            $path = $prefix === '' ? $key : "$prefix.$key";

            if (is_array($value)) {
                if (!array_is_list($value)) {
                    $flat += $this->flatten($value, $path);
                }

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * The parsed document with the given dot-keyed edits applied, dumped back to YAML.
     *
     * The edits land on the document that was parsed, not on a fresh array built from the
     * form, so every key the form never showed — lists, dotted keys, whole nested sections —
     * is still there afterwards. A path the document does not already have is ignored: the
     * form only ever offers paths it read out of this file, so an unknown one means the
     * state was tampered with, and inventing the section would be worse than dropping it.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $edits
     */
    public function writeYaml(array $original, array $edits): string
    {
        $data = $original;

        foreach ($edits as $path => $value) {
            $path = (string) $path;

            if (!Arr::has($data, $path)) {
                continue;
            }

            Arr::set($data, $path, $this->cast(Arr::get($data, $path), $value));
        }

        // Inline 20: deep enough that every real section stays block style. Indent 2 is
        // what Minecraft's own configs use.
        return Yaml::dump($data, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * A form value coerced back to the type the document already held at that key.
     *
     * YAML is type-sensitive in a way a text input is not — `max-players: "20"` and
     * `max-players: 20` are different values to the server — and a Toggle hands back a real
     * bool while a TextInput hands back a string. So the leaf that was parsed decides the
     * type. Only where it was null, and the file itself expressed no preference, does the
     * shape of what the user typed get to decide.
     */
    protected function cast(mixed $original, mixed $value): mixed
    {
        if (is_bool($original)) {
            return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_int($original) || is_float($original)) {
            // The form validates these as numeric; a value that still is not can only come
            // from tampered state, and keeping the old number beats writing a broken file.
            if (!is_numeric($value)) {
                return $original;
            }

            return is_int($original) ? (int) $value : (float) $value;
        }

        if (is_string($original)) {
            return is_scalar($value) ? (string) $value : $original;
        }

        return $this->guess($value);
    }

    /**
     * What YAML would have made of a bare value: empty is null, numbers are numbers,
     * true/false are bools, everything else is a string.
     */
    protected function guess(mixed $value): mixed
    {
        if (!is_string($value)) {
            return is_scalar($value) ? $value : null;
        }

        $trimmed = strtolower(trim($value));

        return match (true) {
            $trimmed === '' => null,
            $trimmed === 'true', $trimmed === 'false' => $trimmed === 'true',
            preg_match('/^-?\d+$/', $trimmed) === 1 => (int) $trimmed,
            is_numeric($trimmed) => (float) $trimmed,
            default => $value,
        };
    }

    /**
     * How to render a YAML leaf: 'toggle', 'number' or 'text'.
     */
    public function yamlType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'toggle',
            is_int($value), is_float($value) => 'number',
            default => 'text',
        };
    }

    /**
     * A key as a label: dashes and underscores opened out, words capitalised.
     */
    public function label(string $key): string
    {
        return Str::headline(str_replace(['-', '_'], ' ', $key));
    }

    /**
     * A dot path as a label, minus its first segment — the page groups by that one, so
     * repeating it in every label would only add noise.
     */
    public function yamlLabel(string $path): string
    {
        $parts = explode('.', $path);

        if (count($parts) > 1) {
            array_shift($parts);
        }

        return implode(' › ', array_map(fn (string $part) => $this->label($part), $parts));
    }
}
