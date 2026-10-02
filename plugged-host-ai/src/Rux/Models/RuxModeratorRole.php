<?php

namespace PluggedHost\Ai\Rux\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Discord role whose members may give rux moderation orders.
 *
 * @property int $id
 * @property string $guild_id
 * @property string $role_id
 * @property ?string $role_name
 * @property string $granted_by_discord_id
 * @property int $granted_by_account_id
 * @property string $permissions_added
 */
class RuxModeratorRole extends Model
{
    protected $table = 'rux_moderator_roles';

    protected $fillable = [
        'guild_id',
        'role_id',
        'role_name',
        'granted_by_discord_id',
        'granted_by_account_id',
        'permissions_added',
    ];

    protected $casts = [
        'granted_by_account_id' => 'integer',
    ];
}
