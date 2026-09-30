<?php

namespace PluggedHost\Minecraft\Services;

use Symfony\Component\Yaml\Yaml;
use Throwable;
use ZipArchive;

/**
 * What a plugin or mod jar says about itself: the descriptor every loader requires inside the
 * jar (plugin.yml / paper-plugin.yml, fabric.mod.json / quilt.mod.json, META-INF/mods.toml /
 * neoforge.mods.toml). Unlike a platform lookup this works for every jar, including ones that
 * were never published anywhere, and it names the plugin's real dependencies.
 *
 * Only those few small entries are read (each capped), so a hostile archive cannot make this
 * inflate anything large.
 */
class JarMetadataReader
{
    public const EMPTY = ['kind' => null, 'id' => null, 'name' => null, 'version' => null, 'authors' => [], 'description' => null, 'website' => null, 'depends' => []];

    private const ENTRY_LIMIT = 256 * 1024;

    /**
     * @return array{kind: ?string, id: ?string, name: ?string, version: ?string, authors: list<string>, description: ?string, website: ?string, depends: list<string>}
     */
    public function read(string $jar): array
    {
        $path = tempnam(sys_get_temp_dir(), 'phjar');

        if ($path === false) {
            return self::EMPTY;
        }

        try {
            file_put_contents($path, $jar);

            return $this->readFile($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{kind: ?string, id: ?string, name: ?string, version: ?string, authors: list<string>, description: ?string, website: ?string, depends: list<string>}
     */
    public function readFile(string $path): array
    {
        $empty = self::EMPTY;

        try {
            $zip = new ZipArchive();

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return $empty;
            }

            try {
                $manifestVersion = $this->manifestVersion($this->entry($zip, 'META-INF/MANIFEST.MF'));

                foreach (['paper-plugin.yml', 'plugin.yml'] as $file) {
                    if (($raw = $this->entry($zip, $file)) !== null && ($meta = $this->bukkit($raw)) !== null) {
                        return $meta + $empty;
                    }
                }

                foreach (['fabric.mod.json', 'quilt.mod.json'] as $file) {
                    if (($raw = $this->entry($zip, $file)) !== null && ($meta = $this->fabric($raw)) !== null) {
                        return $meta + $empty;
                    }
                }

                foreach (['META-INF/neoforge.mods.toml', 'META-INF/mods.toml'] as $file) {
                    if (($raw = $this->entry($zip, $file)) !== null && ($meta = $this->forge($raw, $manifestVersion)) !== null) {
                        return $meta + $empty;
                    }
                }

                return $empty;
            } finally {
                $zip->close();
            }
        } catch (Throwable) {
            return $empty;
        }
    }

    private function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false || (int) $stat['size'] > self::ENTRY_LIMIT) {
            return null;
        }

        $raw = $zip->getFromName($name, self::ENTRY_LIMIT);

        return is_string($raw) ? $raw : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bukkit(string $raw): ?array
    {
        try {
            $data = Yaml::parse($raw);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !is_string($data['name'] ?? null)) {
            return null;
        }

        $authors = array_merge((array) ($data['authors'] ?? []), isset($data['author']) ? [(string) $data['author']] : []);
        $depends = array_map('strval', (array) ($data['depend'] ?? []));

        // paper-plugin.yml: dependencies.server.<Name>.required
        foreach ((array) ($data['dependencies']['server'] ?? []) as $name => $dependency) {
            if (is_array($dependency) && ($dependency['required'] ?? true) === true) {
                $depends[] = (string) $name;
            }
        }

        return [
            'kind' => 'plugin',
            'id' => $data['name'],
            'name' => $data['name'],
            'version' => $this->version($data['version'] ?? null),
            'authors' => $this->strings($authors),
            'description' => is_string($data['description'] ?? null) ? $data['description'] : null,
            'website' => is_string($data['website'] ?? null) ? $data['website'] : null,
            'depends' => $this->strings($depends),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fabric(string $raw): ?array
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        // quilt.mod.json nests everything under quilt_loader.
        $loader = is_array($data['quilt_loader'] ?? null) ? $data['quilt_loader'] : $data;
        $info = is_array($loader['metadata'] ?? null) ? $loader['metadata'] : $data;
        $id = $loader['id'] ?? null;

        if (!is_string($id)) {
            return null;
        }

        $authors = array_map(static fn ($author): string => is_array($author) ? (string) ($author['name'] ?? '') : (string) $author, (array) ($info['authors'] ?? $info['contributors'] ?? []));
        $depends = array_keys(array_filter((array) ($loader['depends'] ?? []), static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY));

        return [
            'kind' => 'mod',
            'id' => $id,
            'name' => is_string($info['name'] ?? null) ? $info['name'] : $id,
            'version' => $this->version($loader['version'] ?? null),
            'authors' => $this->strings($authors),
            'description' => is_string($info['description'] ?? null) ? $info['description'] : null,
            'website' => is_string($info['contact']['homepage'] ?? null) ? $info['contact']['homepage'] : null,
            'depends' => $this->strings(array_diff($depends, ['minecraft', 'java', 'fabricloader', 'fabric-loader', 'quilt_loader'])),
        ];
    }

    /**
     * mods.toml is TOML; only the first [[mods]] table and the required dependencies are
     * needed, which a line scanner handles without a TOML library.
     *
     * @return array<string, mixed>|null
     */
    private function forge(string $raw, ?string $manifestVersion): ?array
    {
        if (!preg_match('/^\s*\[\[mods\]\](.*?)(?=^\s*\[|\z)/ms', $raw, $block)) {
            return null;
        }

        $value = static function (string $key) use ($block): ?string {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(?:"""(.*?)"""|\'\'\'(.*?)\'\'\'|"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\')/ms', $block[1], $match) !== 1) {
                return null;
            }

            foreach ([1, 2, 3, 4] as $group) {
                if (($match[$group] ?? '') !== '') {
                    return trim(stripcslashes($match[$group]));
                }
            }

            return null;
        };

        $id = $value('modId');

        if ($id === null) {
            return null;
        }

        $version = $value('version');
        if ($version === null || str_contains($version, '${')) {
            $version = $manifestVersion;
        }

        $depends = [];
        if (preg_match_all('/^\s*\[\[dependencies\.[^\]]+\]\](.*?)(?=^\s*\[|\z)/ms', $raw, $blocks)) {
            foreach ($blocks[1] as $dependency) {
                $modId = preg_match('/^\s*modId\s*=\s*"([^"]+)"/m', $dependency, $m) === 1 ? $m[1] : null;
                $required = preg_match('/^\s*mandatory\s*=\s*true/m', $dependency) === 1 || preg_match('/^\s*type\s*=\s*"required"/m', $dependency) === 1;

                if ($modId !== null && $required) {
                    $depends[] = $modId;
                }
            }
        }

        return [
            'kind' => 'mod',
            'id' => $id,
            'name' => $value('displayName') ?? $id,
            'version' => $this->version($version),
            'authors' => $this->strings(explode(',', (string) $value('authors'))),
            'description' => $value('description'),
            'website' => $value('displayURL'),
            'depends' => $this->strings(array_diff($depends, ['minecraft', 'forge', 'neoforge', 'java'])),
        ];
    }

    private function manifestVersion(?string $manifest): ?string
    {
        return $manifest !== null && preg_match('/^Implementation-Version:\s*(\S+)/mi', $manifest, $match) === 1 ? $match[1] : null;
    }

    private function version(mixed $version): ?string
    {
        $version = is_scalar($version) ? trim((string) $version) : '';

        return $version === '' || str_contains($version, '${') ? null : mb_substr($version, 0, 60);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = mb_substr($value, 0, 80);
            }
        }

        return array_slice($out, 0, 20);
    }
}
