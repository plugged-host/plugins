<?php

namespace PluggedHost\Ads\Models;

use App\Models\Server;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resolved pass through the start gate.
 *
 * This table exists to answer the question the whole mechanic rests on and that
 * nobody can answer from first principles: how often is the gate actually hit, how
 * many of those waits are sat through rather than abandoned, and how long does the
 * container really take to come up. The dwell is a guess until there is a week of
 * these rows to look at.
 *
 * @property int $id
 * @property int $user_id
 * @property int $server_id
 * @property string $outcome
 * @property int $dwell_seconds
 * @property ?array $metadata
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Server $server
 */
class StartGateEvent extends Model
{
    public $timestamps = false;

    /**
     * Historical. A token balance used to buy a silent skip past the gate; that was
     * removed on 2026-08-31 when tokens became the start-queue currency. Nothing
     * writes this any more, and it stays declared because rows already carry it and
     * a reader that does not know the value would render it as nothing at all.
     */
    public const OUTCOME_CREDIT = 'credit';

    /** Waited the gate out. */
    public const OUTCOME_ADS = 'ads';

    /** Holds an exempt role, never saw the gate. */
    public const OUTCOME_EXEMPT = 'exempt';

    /** Something the gate depends on was unreachable, so it let the request past. */
    public const OUTCOME_FAILOPEN = 'failopen';

    protected $table = 'ads_start_gate_events';

    protected $fillable = [
        'user_id',
        'server_id',
        'outcome',
        'dwell_seconds',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'dwell_seconds' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
