<?php

namespace PluggedHost\Status\Policies;

use App\Models\User;
use PluggedHost\Status\Models\StatusIncident;

class StatusIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewList node');
    }

    public function view(User $user, StatusIncident $incident): bool
    {
        return $user->can('view node');
    }

    public function create(User $user): bool
    {
        return $user->can('create node');
    }

    public function update(User $user, StatusIncident $incident): bool
    {
        return $user->can('update node');
    }

    public function delete(User $user, StatusIncident $incident): bool
    {
        return $user->can('delete node');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete node');
    }
}
