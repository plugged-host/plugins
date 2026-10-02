<?php

namespace PluggedHost\Ai\Models;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property ?int $server_id
 * @property ?string $title
 * @property string $status
 * @property int $step_count
 * @property int $tokens_used
 * @property ?string $last_provider
 * @property ?string $last_error
 * @property ?\Illuminate\Support\Carbon $last_activity_at
 * @property ?\Illuminate\Support\Carbon $archived_at
 */
class NeoConversation extends Model
{
    public const IDLE = 'idle';

    public const RUNNING = 'running';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const LIMITED = 'limited';

    public const UNLINKED = 'unlinked';

    public const ERROR = 'error';

    protected $table = 'neo_conversations';

    protected $fillable = [
        'user_id',
        'server_id',
        'title',
        'status',
        'step_count',
        'tokens_used',
        'last_provider',
        'last_error',
        'last_activity_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'step_count' => 'integer',
            'tokens_used' => 'integer',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(NeoMessage::class, 'conversation_id')->orderBy('seq');
    }

    public function nextSeq(): int
    {
        return (int) $this->messages()->max('seq') + 1;
    }
}
