<?php

namespace PluggedHost\Status\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusIncidentUpdate extends Model
{
    protected $table = 'status_incident_updates';

    protected $fillable = ['status_incident_id', 'status', 'message', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $update): void {
            $update->created_by ??= auth()->id();
            $update->published_at ??= now();
        });
    }

    /** @return BelongsTo<StatusIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusIncident::class, 'status_incident_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
