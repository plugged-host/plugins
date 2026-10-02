<?php

namespace PluggedHost\Economy\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A credit change made on the panel that noprofit.space has not confirmed yet.
 *
 * @property int $id
 * @property int $user_id
 * @property int $delta
 * @property int $extra_cpu
 * @property int $extra_memory
 * @property int $extra_disk
 * @property ?string $type
 * @property ?string $description
 * @property int $attempts
 * @property ?string $last_error
 * @property Carbon $created_at
 */
class NoprofitCreditOutbox extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'noprofit_credit_outbox';

    protected $fillable = [
        'user_id',
        'delta',
        'extra_cpu',
        'extra_memory',
        'extra_disk',
        'type',
        'description',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'delta' => 'integer',
        'extra_cpu' => 'integer',
        'extra_memory' => 'integer',
        'extra_disk' => 'integer',
        'attempts' => 'integer',
        'created_at' => 'datetime',
    ];
}
