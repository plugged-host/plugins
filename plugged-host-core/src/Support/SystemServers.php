<?php

namespace PluggedHost\Core\Support;

use App\Models\Server;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Servers the platform runs for itself rather than for a customer (a devbox next to a game
 * server, see plugged-host-devbox). They are real Pelican servers so Wings runs them, but the
 * subsystems that look after customers' servers (storage mirrors, failover, limbo, load
 * balancing) skip them, the panel's server lists and global search leave them out (see
 * plugged-host-devbox's ServerListsPluginProvider), and abuse findings are pinned on the person
 * they belong to.
 *
 * Marked by an external_id starting with PREFIX, which is cheap to filter in SQL. The plugin
 * that owns them keeps its own table as the source of truth; the marker is only the filter.
 *
 * It also guards the one thing that makes such servers dangerous: a mount of another server's
 * volume. A node lists /var/lib/pelican/volumes under Wings' allowed_mounts so a devbox can see
 * its game server's files, and Wings only checks that a mount's source *starts with* an allowed
 * path. So every mount into a volume directory is dropped from the configuration sent to Wings
 * (GuardedServerConfigurationStructureService) and refused on save, unless the server's owning
 * plugin vouches for that exact source here.
 */
final class SystemServers
{
    public const PREFIX = 'ph-system:';

    /** Volume roots no mount may reach into, besides each node's own daemon_base. */
    public const PROTECTED_ROOTS = ['/var/lib/pelican/volumes', '/var/lib/pelican', '/etc/pelican', '/var/run/docker.sock', '/run/docker.sock'];

    /** @var array<int, Closure(Server): ?string> */
    private static array $volumeSources = [];

    /** @var array<int, Closure(Server): ?array{kind: string, user_id: ?int, server_id: ?int}> */
    private static array $describers = [];

    /** @var array<int, Closure(Server): bool> */
    private static array $holds = [];

    private static int $systemWrites = 0;

    public static function is(?Server $server): bool
    {
        return $server !== null && str_starts_with((string) $server->external_id, self::PREFIX);
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function exclude(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull($query->qualifyColumn('external_id'))
                ->orWhere($query->qualifyColumn('external_id'), 'not like', self::PREFIX . '%');
        });
    }

    /**
     * The complement of exclude(): system servers only.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function only(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('external_id'), 'like', self::PREFIX . '%');
    }

    /**
     * The owning plugin answers "which volume may this server mount": the exact source, or null.
     *
     * @param  Closure(Server): ?string  $resolver
     */
    public static function allowVolumeSourceUsing(Closure $resolver): void
    {
        self::$volumeSources[] = $resolver;
    }

    public static function allowedVolumeSource(Server $server): ?string
    {
        foreach (self::$volumeSources as $resolver) {
            try {
                $source = $resolver($server);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if (is_string($source) && $source !== '') {
                return self::clean($source);
            }
        }

        return null;
    }

    /**
     * Who a system server really belongs to, for abuse alerts and sweeps.
     *
     * @param  Closure(Server): ?array{kind: string, user_id: ?int, server_id: ?int}  $describer
     */
    public static function describeUsing(Closure $describer): void
    {
        self::$describers[] = $describer;
    }

    /**
     * @return array{kind: string, user_id: ?int, server_id: ?int}|null
     */
    public static function describe(Server $server): ?array
    {
        if (!self::is($server)) {
            return null;
        }

        foreach (self::$describers as $describer) {
            try {
                $description = $describer($server);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if (is_array($description)) {
                return $description;
            }
        }

        return null;
    }

    /**
     * The owning plugin says "a system server is working on this (customer) server right now":
     * load balancing leaves it where it is instead of moving it from under someone.
     *
     * @param  Closure(Server): bool  $hold
     */
    public static function holdUsing(Closure $hold): void
    {
        self::$holds[] = $hold;
    }

    public static function held(Server $server): bool
    {
        foreach (self::$holds as $hold) {
            try {
                if ($hold($server)) {
                    return true;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return false;
    }

    /**
     * The id of the person a server really belongs to: its owner, or for a system server the
     * person it was made for (a devbox's user, or for a shared one the game server's owner).
     */
    public static function personId(Server $server): ?int
    {
        $described = self::describe($server);
        if ($described !== null) {
            return $described['user_id'] ?? null;
        }

        return $server->owner_id !== null ? (int) $server->owner_id : null;
    }

    /**
     * Runs $callback with mounts into volume directories allowed to be saved: only the owning
     * plugin's provisioning code does this, for a source allowedVolumeSource() will vouch for.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asSystem(Closure $callback): mixed
    {
        self::$systemWrites++;

        try {
            return $callback();
        } finally {
            self::$systemWrites--;
        }
    }

    public static function writingAsSystem(): bool
    {
        return self::$systemWrites > 0;
    }

    /**
     * Whether a mount source reaches into a protected path, judged the way Wings judges it:
     * filepath.Clean, then a plain string prefix (so `/var/lib/pelican/volumes-x` counts too).
     *
     * @param  string[]  $extraRoots
     */
    public static function isProtectedSource(string $source, array $extraRoots = []): bool
    {
        $clean = self::clean($source);

        foreach ([...self::PROTECTED_ROOTS, ...$extraRoots] as $root) {
            $root = rtrim(self::clean((string) $root), '/');

            if ($root !== '' && str_starts_with($clean, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lexical clean like Go's filepath.Clean for absolute paths: collapses `//`, drops `.`,
     * resolves `..` without touching the disk. Relative paths are made absolute (Docker refuses
     * them anyway; treating them as rooted keeps the prefix check honest).
     */
    public static function clean(string $path): string
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

        return '/' . implode('/', $parts);
    }
}
