<?php

namespace PluggedHost\Status\Models;

use Illuminate\Database\Eloquent\Model;

class StatusDailyUptime extends Model
{
    protected $table = 'status_daily_uptime';

    protected $fillable = ['component_type', 'component_key', 'date', 'total_checks', 'operational_checks', 'worst_status'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
