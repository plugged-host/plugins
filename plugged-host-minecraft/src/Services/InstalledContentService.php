<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use InvalidArgumentException;

/**
 * Changing what is installed in plugins/ or mods/: delete, disable/enable and update a jar.
 *
 * Shared by the content page and plugged-host-ai's tools. File names always come from the
 * live folder listing, never from the caller's list, so a name can only ever point at a jar
 * that is really there. Callers own permissions, notifications and the activity log.
 */
class InstalledContentService
{
    public function __construct(
        protected ModDetectionService $detection,
        protected ContentInstallService $installer,
        protected ModrinthCatalog $modrinth,
    ) {}

    /**
     * Delete a jar, and optionally the plugin's data folder (plugins/<Name>/: its configs and
     * data). Returns what was deleted, relative to the content folder.
     *
     * @return list<string>
     */
    public function delete(Server $server, string $file, bool $withData = false): array
    {
        $row = $this->row($server, $file);
        $targets = [$row['file']];

        if ($withData && is_string($row['data_folder'] ?? null) && $row['data_folder'] !== '') {
            $targets[] = $row['data_folder'];
        }

        $this->files($server)->deleteFiles('/' . $this->detection->directory($server), $targets);

        return $targets;
    }

    /**
     * Enable or disable a jar by renaming it to/from "<name>.jar.disabled". Returns the new name.
     */
    public function toggle(Server $server, string $file, bool $enabled): string
    {
        $row = $this->row($server, $file);
        $base = (string) preg_replace('/\.disabled$/i', '', $row['file']);
        $target = $enabled ? $base : $base . '.disabled';

        if ($target !== $row['file']) {
            $this->files($server)->renameFiles('/' . $this->detection->directory($server), [['from' => $row['file'], 'to' => $target]]);
        }

        return $target;
    }

    /**
     * Replace a Modrinth-identified jar with the newer build found for it: the new file is
     * handed to wings, then the old one is removed. Returns the new file name.
     */
    public function update(Server $server, string $file): string
    {
        $row = $this->row($server, $file);
        $versionId = $row['update']['id'] ?? null;
        $version = is_string($versionId) ? $this->modrinth->version($versionId) : null;
        $download = $version !== null ? $this->modrinth->primaryFile($version) : null;

        if ($download === null || $download['filename'] === '' || str_contains($download['filename'], '/') || str_contains($download['filename'], '..')) {
            throw new InvalidArgumentException('no_update');
        }

        $directory = $this->detection->directory($server);
        // A disabled jar stays disabled after the update.
        $target = $row['disabled'] ? $download['filename'] . '.disabled' : $download['filename'];

        if ($this->installer->pull($server, $download['url'], $target, $directory) !== null) {
            throw new InvalidArgumentException('pull_failed');
        }

        if ($target !== $row['file']) {
            $this->files($server)->deleteFiles('/' . $directory, [$row['file']]);
        }

        return $target;
    }

    /**
     * The listed row for this exact file name.
     *
     * @return array<string, mixed>
     */
    public function row(Server $server, string $file): array
    {
        $row = collect($this->detection->list($server))->firstWhere('file', $file);

        if (!is_array($row)) {
            throw new InvalidArgumentException('not_installed');
        }

        return $row;
    }

    protected function files(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }
}
