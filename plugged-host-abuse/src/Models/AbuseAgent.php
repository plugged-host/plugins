<?php

namespace PluggedHost\Abuse\Models;

use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The abuse agent on one node: its version and when it last reported (AgentGateway).
 *
 * @property int $node_id
 * @property string|null $version
 * @property string|null $hostname
 * @property string|null $ip
 * @property array<string, mixed>|null $stats
 * @property \Illuminate\Support\Carbon|null $last_report_at
 */
class AbuseAgent extends Model
{
    protected $table = 'plugged_host_abuse_agents';

    protected $primaryKey = 'node_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'last_report_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
