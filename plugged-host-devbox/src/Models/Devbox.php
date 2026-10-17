<?php

namespace PluggedHost\Devbox\Models;

use App\Models\Mount;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PluggedHost\Core\Support\SystemServers;

/**
 * One devbox: a person's (or, with user_id null, a game server's shared) Linux box next to a
 * game server. `server` is the hidden server Wings runs for it, `mount` gives it the game
 * server's volume at /server. This table is the source of truth; the server's external_id
 * (SystemServers::PREFIX . 'devbox:<id>') is only the cheap filter other plugins use.
 *
 * @property int $id
 * @property int $game_server_id
 * @property ?int $user_id
 * @property ?int $server_id
 * @property ?int $mount_id
 * @property ?int $node_id
 * @property string $slug
 * @property ?string $token_hash
 * @property ?\Illuminate\Support\Carbon $stopping_at
 * @property ?string $boot_status
 * @property ?string $boot_detail
 * @property ?string $host_key
 * @property ?string $version
 * @property int $connections
 * @property ?array<string, bool> $shared_rights
 * @property ?\Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $booted_at
 * @property ?\Illuminate\Support\Carbon $last_seen_at
 * @property ?\Illuminate\Support\Carbon $last_used_at
 * @property-read Server $game
 * @property-read ?User $user
 * @property-read ?Server $server
 * @property-read ?Mount $mount
 * @property-read ?Node $node
 */
class Devbox extends Model
{
    public const EXTERNAL_PREFIX = SystemServers::PREFIX . 'devbox:';

    protected $table = 'plugged_host_devboxes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'shared_rights' => 'array',
            'stopping_at' => 'datetime',
            'started_at' => 'datetime',
            'booted_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'game_server_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function mount(): BelongsTo
    {
        return $this->belongsTo(Mount::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Started and not on its way down: its token is good and people may sign in. A devbox that
     * is stopping still holds its token (and counts as running for the caps) until Wings says
     * it is offline, but the panel no longer answers for it.
     */
    public function isRunning(): bool
    {
        return $this->token_hash !== null && $this->stopping_at === null;
    }

    public function isStopping(): bool
    {
        return $this->token_hash !== null && $this->stopping_at !== null;
    }

    public function externalId(): string
    {
        return self::EXTERNAL_PREFIX . $this->id;
    }

    /**
     * The only volume this devbox may mount: its game server's, on the node both are on. Null when
     * they are not on the same node (the game server moved; the reconcile job removes the devbox).
     */
    public function volumeSource(): ?string
    {
        $game = $this->game;
        $server = $this->server;

        if ($game === null || $server === null || (int) $game->node_id !== (int) $server->node_id) {
            return null;
        }

        $base = (string) ($server->node?->daemon_base ?? '');
        if ($base === '') {
            return null;
        }

        return SystemServers::clean($base . '/' . $game->uuid);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
