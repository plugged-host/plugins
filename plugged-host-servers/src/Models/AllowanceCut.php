<?php

namespace PluggedHost\Servers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A decrease of an account's allowance its servers have to give back (Services\AllowanceTrim).
 *
 * @property int $id
 * @property int $user_id
 * @property string $reason
 * @property int $cpu
 * @property int $memory
 * @property int $disk
 * @property array{taken: array{cpu: int, memory: int, disk: int}, servers: list<array{id: int, name: string, cpu: int, memory: int, disk: int}>}|null $result
 * @property Carbon|null $settled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 */
class AllowanceCut extends Model
{
    protected $table = 'allowance_cuts';

    protected $fillable = ['user_id', 'reason', 'cpu', 'memory', 'disk'];

    protected $casts = [
        'cpu' => 'integer',
        'memory' => 'integer',
        'disk' => 'integer',
        'result' => 'array',
        'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
