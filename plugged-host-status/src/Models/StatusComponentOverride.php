<?php

namespace PluggedHost\Status\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusComponentOverride extends Model
{
    protected $table = 'status_component_overrides';

    protected $fillable = ['component_key', 'status', 'note', 'created_by', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $override): void {
            $override->created_by ??= auth()->id();
        });
    }

    /**
     * Overrides in effect right now: started (or open-started) and not yet ended.
     *
     * @param  Builder<StatusComponentOverride>  $query
     * @return Builder<StatusComponentOverride>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
