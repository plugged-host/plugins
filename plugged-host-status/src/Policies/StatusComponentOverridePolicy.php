<?php

namespace PluggedHost\Status\Policies;

use App\Models\User;
use PluggedHost\Status\Models\StatusComponentOverride;

class StatusComponentOverridePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewList node');
    }

    public function view(User $user, StatusComponentOverride $override): bool
    {
        return $user->can('view node');
    }

    public function create(User $user): bool
    {
        return $user->can('update node');
    }

    public function update(User $user, StatusComponentOverride $override): bool
    {
        return $user->can('update node');
    }

    public function delete(User $user, StatusComponentOverride $override): bool
    {
        return $user->can('update node');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('update node');
    }
}
