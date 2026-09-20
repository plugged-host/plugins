<?php

namespace PluggedHost\Status\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatusIncident extends Model
{
    protected $table = 'status_incidents';

    protected $fillable = ['title', 'impact', 'status', 'affected_components', 'created_by', 'started_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'affected_components' => 'array',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            $incident->created_by ??= auth()->id();
            $incident->started_at ??= now();
        });

        static::saving(function (self $incident): void {
            if ($incident->status === 'resolved') {
                $incident->resolved_at ??= now();
            } else {
                $incident->resolved_at = null;
            }
        });
    }

    /** @return HasMany<StatusIncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(StatusIncidentUpdate::class)->orderByDesc('published_at');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
