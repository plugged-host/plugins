<?php

namespace PluggedHost\Core\Models;

use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One abuse finding (see Services\Abuse\Finding), kept per server + rule.
 *
 * open: found, nothing done yet (a review finding, or a critical one in report mode).
 * actioned: the server was suspended for it.
 * dismissed: an admin marked it a false positive; it is still counted but never acted on again.
 * resolved: dealt with; it opens again if the checks see it again.
 *
 * @property int $id
 * @property string $fingerprint
 * @property int|null $server_id
 * @property int|null $node_id
 * @property string $rule
 * @property string $category
 * @property string $severity
 * @property string $source
 * @property string $status
 * @property string $summary
 * @property array<string, mixed>|null $evidence
 * @property int $hits
 * @property \Illuminate\Support\Carbon|null $first_seen_at
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property string|null $action
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property int|null $resolved_by
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class AbuseFinding extends Model
{
    public const OPEN = 'open';

    public const ACTIONED = 'actioned';

    public const DISMISSED = 'dismissed';

    public const RESOLVED = 'resolved';

    protected $table = 'plugged_host_abuse_findings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'actioned_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public static function fingerprintFor(?int $serverId, ?int $nodeId, string $rule): string
    {
        return hash('sha256', $serverId !== null ? "server:{$serverId}:{$rule}" : "node:{$nodeId}:{$rule}");
    }

    /** False until the migration has run, so the scheduled checks stay quiet on an install without it. */
    public static function ready(): bool
    {
        try {
            return Cache::remember('ph-abuse:tables-ready', 300, fn () => Schema::hasTable('plugged_host_abuse_findings')
                && Schema::hasTable('plugged_host_abuse_agents'));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
