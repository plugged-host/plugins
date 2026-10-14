<?php

namespace PluggedHost\FiveM\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * txAdmin's view of a server: where its server data (and so server.cfg) lives, and who its
 * master admin is.
 *
 * The egg runs FXServer in txAdmin manager mode, so there is no server.cfg at the root: txAdmin
 * deploys a recipe into txData/<Name>_<hash>.base/ (or is pointed at any folder) and records it as
 * server.dataPath in txData/default/config.json. That file has had three shapes: version 1 kept
 * fxRunner.serverDataPath / fxRunner.cfgPath, versions 2 and 3 keep server.dataPath / cfgPath.
 * Paths in it are container paths (/home/container/...); the file API wants them relative to the
 * server root.
 */
class TxAdminData
{
    public const CONFIG = 'txData/default/config.json';

    public const ADMINS = 'txData/admins.json';

    private const ROOT = '/home/container';

    /**
     * @return array<string, mixed>|null null when txAdmin has not written its config yet
     *
     * @throws ConnectionException
     */
    public function config(Server $server): ?array
    {
        $raw = $this->read($server, self::CONFIG);
        $config = $raw === null ? null : json_decode($raw, true);

        return is_array($config) ? $config : null;
    }

    /**
     * The server data folder, relative to the server root ("txData/ESXLegacy_1A2B3C.base"), or
     * null while txAdmin is not set up.
     *
     * @param  array<string, mixed>|null  $config
     */
    public function dataPath(?array $config): ?string
    {
        $path = $config['server']['dataPath'] ?? $config['fxRunner']['serverDataPath'] ?? null;

        return is_string($path) && $path !== '' ? self::relative($path) : null;
    }

    /**
     * server.cfg's path relative to the server root, or null while txAdmin is not set up.
     *
     * @param  array<string, mixed>|null  $config
     */
    public function cfgPath(?array $config): ?string
    {
        $data = $this->dataPath($config);

        if ($data === null) {
            return null;
        }

        $cfg = $config['server']['cfgPath'] ?? $config['fxRunner']['cfgPath'] ?? 'server.cfg';
        $cfg = is_string($cfg) && $cfg !== '' ? $cfg : 'server.cfg';

        // Absolute means a container path; anything else is relative to the data folder.
        $path = str_starts_with($cfg, '/') ? self::relative($cfg) : self::normalize($data . '/' . $cfg);

        return $path === '' ? null : $path;
    }

    /**
     * Point txAdmin at a new server data folder, keeping every other setting it has.
     *
     * @param  array<string, mixed>|null  $config  as read by config(), null for a fresh txData
     */
    public function pointAt(Server $server, ?array $config, string $dataPath, ?string $onesync): void
    {
        $config ??= ['version' => 2];
        $config['version'] ??= 2;

        if (isset($config['fxRunner']) && !isset($config['server'])) {
            // Version 1: txAdmin migrates it on boot, so write where it reads.
            $config['fxRunner']['serverDataPath'] = self::ROOT . '/' . trim($dataPath, '/') . '/';
            $config['fxRunner']['cfgPath'] = 'server.cfg';
        } else {
            $config['server'] = is_array($config['server'] ?? null) ? $config['server'] : [];
            $config['server']['dataPath'] = self::ROOT . '/' . trim($dataPath, '/') . '/';
            unset($config['server']['cfgPath']);

            if ($onesync !== null) {
                $config['server']['onesync'] = $onesync;
            }
        }

        app(DaemonFileRepository::class)->setServer($server)
            ->putContent(self::CONFIG, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * add_principal lines for txAdmin's master admin, the way txAdmin's own deployer writes
     * {{addPrincipalsMaster}}: one per linked identity (FiveM account, Discord).
     *
     * @return array<int, string>
     */
    public function masterPrincipals(Server $server): array
    {
        try {
            $admins = json_decode((string) $this->read($server, self::ADMINS), true);
        } catch (Throwable) {
            return [];
        }

        $lines = [];

        foreach (is_array($admins) ? $admins : [] as $admin) {
            if (!is_array($admin) || ($admin['master'] ?? false) !== true) {
                continue;
            }

            $name = preg_replace('/[^\w.-]/', '', (string) ($admin['name'] ?? '')) ?? '';

            foreach (['citizenfx', 'discord'] as $provider) {
                $identifier = $admin['providers'][$provider]['identifier'] ?? null;

                if (is_string($identifier) && preg_match('/^[a-z]+:[\w-]+$/', $identifier) === 1) {
                    $lines[] = "add_principal identifier.$identifier group.admin #$name";
                }
            }
        }

        return $lines;
    }

    /**
     * Whether txAdmin has an admin account yet. Until it has, txAdmin does not start the server
     * by itself: the owner first registers with the PIN it prints in the console.
     */
    public function hasAdmins(Server $server): bool
    {
        try {
            $admins = json_decode((string) $this->read($server, self::ADMINS), true);
        } catch (Throwable) {
            return true;
        }

        return is_array($admins) && $admins !== [];
    }

    /**
     * A container path as a path relative to the server root.
     */
    public static function relative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_starts_with($path, self::ROOT)) {
            $path = substr($path, strlen(self::ROOT));
        }

        return self::normalize($path);
    }

    /**
     * Collapse "." and "..", without ever leaving the root.
     */
    public static function normalize(string $path): string
    {
        $parts = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * @throws ConnectionException
     */
    private function read(Server $server, string $path): ?string
    {
        try {
            return app(DaemonFileRepository::class)->setServer($server)->getContent($path, 1048576);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }
    }
}
