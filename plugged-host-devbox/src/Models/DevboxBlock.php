<?php

namespace PluggedHost\Devbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person who may not use devboxes: the abuse checks raised a critical finding (finding_id) on
 * one of their devboxes (server_id). Active while lifted_at is null; Services\DevboxAbuse lifts it
 * when an admin dismisses or resolves that finding, and puts it back when the finding is reopened.
 *
 * The finding itself goes when its server is deleted (the abuse plugin's foreign key cascades,
 * e.g. when abuse-purge deletes a suspended devbox): the block stays. To lift one by hand:
 * `DevboxBlock::query()->where('user_id', <id>)->update(['lifted_at' => now()])`.
 *
 * @property int $id
 * @property int $user_id
 * @property int $finding_id
 * @property ?int $server_id
 * @property ?string $rule
 * @property ?\Illuminate\Support\Carbon $lifted_at
 */
class DevboxBlock extends Model
{
    protected $table = 'plugged_host_devbox_blocks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lifted_at' => 'datetime',
        ];
    }
}
