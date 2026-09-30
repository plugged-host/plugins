<?php

namespace PluggedHost\Neo\Support;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Neo\Services\Mclogs;

/**
 * The console logs a user uploaded to mclo.gs that they may hand to neo with "Ask neo".
 *
 * Remembered per user for a couple of hours, together with the server they came from, so the
 * chat only starts "fix my server's log" for an upload this user really made on that server.
 */
final class MclogsHandoff
{
    private const TTL_HOURS = 2;

    /**
     * @param  array{id: string, url: string, errors: int}  $upload
     */
    public static function remember(User $user, Server $server, array $upload, int $problems): void
    {
        Cache::put(self::key($user, $upload['id']), [
            'id' => $upload['id'],
            'url' => $upload['url'],
            'server_id' => $server->id,
            'errors' => $upload['errors'],
            'problems' => $problems,
        ], now()->addHours(self::TTL_HOURS));
    }

    /**
     * @return array{id: string, url: string, server_id: int, errors: int, problems: int}|null
     */
    public static function find(User $user, string $log): ?array
    {
        $id = Mclogs::idFrom($log);
        $upload = $id === null ? null : Cache::get(self::key($user, $id));

        return is_array($upload) ? $upload : null;
    }

    private static function key(User $user, string $id): string
    {
        return "plugged-host-neo:mclogs:{$user->id}:{$id}";
    }
}
