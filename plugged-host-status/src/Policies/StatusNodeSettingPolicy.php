<?php

namespace PluggedHost\Status\Policies;

use App\Models\User;
use PluggedHost\Status\Models\StatusNodeSetting;

class StatusNodeSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewList node');
    }

    public function view(User $user, StatusNodeSetting $setting): bool
    {
        return $user->can('view node') && $setting->node !== null && $user->canTarget($setting->node);
    }

    public function create(User $user): bool
    {
        return $user->can('create node');
    }

    public function update(User $user, StatusNodeSetting $setting): bool
    {
        return $user->can('update node') && $setting->node !== null && $user->canTarget($setting->node);
    }

    public function delete(User $user, StatusNodeSetting $setting): bool
    {
        return $user->can('delete node') && $setting->node !== null && $user->canTarget($setting->node);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete node');
    }
}
