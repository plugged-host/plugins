<?php

namespace PluggedHost\Core\Doctor;

use Closure;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * The health checks behind `php artisan plugged-host:doctor`.
 *
 * Each plugin registers what it needs to work (a secret set, a daemon running, an agent that
 * reported lately) from its own provider, so a fresh install or a reinstall can be walked
 * through until everything says ok. A check only runs while its plugin is enabled.
 *
 *     Doctor::check('plugged-host-storage', 'Storage agent', fn () => Doctor::ok('reported 40s ago'));
 */
final class Doctor
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** @var list<array{plugin: string, label: string, check: Closure}> */
    private static array $checks = [];

    /**
     * @param  Closure(): array{status: string, message: string}  $check
     */
    public static function check(string $plugin, string $label, Closure $check): void
    {
        self::$checks[] = ['plugin' => $plugin, 'label' => $label, 'check' => $check];
    }

    /** @return array{status: string, message: string} */
    public static function ok(string $message = ''): array
    {
        return ['status' => self::OK, 'message' => $message];
    }

    /** @return array{status: string, message: string} */
    public static function warn(string $message): array
    {
        return ['status' => self::WARN, 'message' => $message];
    }

    /** @return array{status: string, message: string} */
    public static function fail(string $message): array
    {
        return ['status' => self::FAIL, 'message' => $message];
    }

    /**
     * Every registered check of an enabled plugin, run. A check that throws fails with the
     * exception's message rather than stopping the others.
     *
     * @return list<array{plugin: string, label: string, status: string, message: string}>
     */
    public static function run(?string $plugin = null): array
    {
        $results = [];

        foreach (self::$checks as $entry) {
            if (($plugin !== null && $entry['plugin'] !== $plugin) || !Plugins::enabled($entry['plugin'])) {
                continue;
            }

            try {
                $result = ($entry['check'])();
            } catch (Throwable $e) {
                $result = self::fail($e::class . ': ' . $e->getMessage());
            }

            $results[] = ['plugin' => $entry['plugin'], 'label' => $entry['label'], ...$result];
        }

        return $results;
    }

    /**
     * How long ago a unix timestamp was, for check messages.
     */
    public static function ago(?int $timestamp): string
    {
        if (!$timestamp) {
            return 'never';
        }

        $seconds = max(0, time() - $timestamp);

        return match (true) {
            $seconds < 120 => $seconds . 's ago',
            $seconds < 7200 => intdiv($seconds, 60) . 'm ago',
            $seconds < 172800 => intdiv($seconds, 3600) . 'h ago',
            default => intdiv($seconds, 86400) . 'd ago',
        };
    }
}
