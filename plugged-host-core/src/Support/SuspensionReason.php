<?php

namespace PluggedHost\Core\Support;

use App\Enums\ServerState;
use App\Facades\Activity;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Why a server is suspended, for its owner's suspended page.
 *
 * Kept in the activity log (`server:suspension.suspend` / `.unsuspend`, the server as subject),
 * so it needs no table of its own and admins see it in the server's activity too. Whatever
 * suspends a server through upstream's SuspensionService (the abuse scan, the admin toggles)
 * is caught by SuspensionNoticePluginProvider's Server observer; callers that know why wrap the
 * call in with(). Limbo parks servers with saveQuietly(), so hibernation never shows up here.
 */
final class SuspensionReason
{
    public const SOURCE_ABUSE_SCAN = 'abuse_scan';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SYSTEM = 'system';

    /** @var array{source?: string, reasons?: array<int, string>, note?: string|null}|null */
    private static ?array $pending = null;

    /**
     * Run $callback with $context as the reason for any suspension it causes.
     *
     * @template T
     *
     * @param  array{source: string, reasons?: array<int, string>, note?: string|null}  $context
     * @param  callable(): T  $callback
     * @return T
     */
    public static function with(array $context, callable $callback): mixed
    {
        $previous = self::$pending;
        self::$pending = $context;

        try {
            return $callback();
        } finally {
            self::$pending = $previous;
        }
    }

    /** Record a status change the observer saw. */
    public static function statusChanged(Server $server, mixed $from, mixed $to): void
    {
        $wasSuspended = self::isSuspendedValue($from);
        $isSuspended = self::isSuspendedValue($to);

        if ($wasSuspended === $isSuspended) {
            return;
        }

        try {
            if ($isSuspended) {
                $context = self::$pending ?? ['source' => auth()->check() ? self::SOURCE_ADMIN : self::SOURCE_SYSTEM];

                Activity::event('server:suspension.suspend')
                    ->subject($server)
                    ->property([
                        'source' => (string) ($context['source'] ?? self::SOURCE_SYSTEM),
                        'reasons' => array_values(array_map(strval(...), (array) ($context['reasons'] ?? []))),
                        'note' => isset($context['note']) && trim((string) $context['note']) !== '' ? trim((string) $context['note']) : null,
                    ])
                    ->log();
            } else {
                Activity::event('server:suspension.unsuspend')->subject($server)->log();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The latest recorded suspension of $server, or null when none was recorded (suspended
     * before this existed, or never).
     *
     * @return array{source: string, reasons: array<int, string>, note: string|null, at: CarbonImmutable}|null
     */
    public static function latest(Server $server): ?array
    {
        try {
            $row = DB::table('activity_logs')
                ->join('activity_log_subjects', 'activity_logs.id', '=', 'activity_log_subjects.activity_log_id')
                ->where('activity_log_subjects.subject_type', $server->getMorphClass())
                ->where('activity_log_subjects.subject_id', $server->getKey())
                ->where('activity_logs.event', 'server:suspension.suspend')
                ->orderByDesc('activity_logs.id')
                ->first(['activity_logs.properties', 'activity_logs.timestamp']);
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $properties = json_decode((string) $row->properties, true);
        $properties = is_array($properties) ? $properties : [];

        return [
            'source' => (string) ($properties['source'] ?? self::SOURCE_SYSTEM),
            'reasons' => array_values(array_map(strval(...), (array) ($properties['reasons'] ?? []))),
            'note' => is_string($properties['note'] ?? null) ? $properties['note'] : null,
            'at' => CarbonImmutable::parse($row->timestamp),
        ];
    }

    /** Whether $server was unsuspended after $since (an admin lifting an abuse suspension, say). */
    public static function unsuspendedSince(Server $server, CarbonImmutable $since): bool
    {
        try {
            return DB::table('activity_logs')
                ->join('activity_log_subjects', 'activity_logs.id', '=', 'activity_log_subjects.activity_log_id')
                ->where('activity_log_subjects.subject_type', $server->getMorphClass())
                ->where('activity_log_subjects.subject_id', $server->getKey())
                ->where('activity_logs.event', 'server:suspension.unsuspend')
                ->where('activity_logs.timestamp', '>', $since)
                ->exists();
        } catch (Throwable) {
            // Unknown counts as yes: the one caller decides whether to delete a server.
            return true;
        }
    }

    private static function isSuspendedValue(mixed $status): bool
    {
        $value = $status instanceof ServerState ? $status->value : $status;

        return $value === ServerState::Suspended->value;
    }
}
