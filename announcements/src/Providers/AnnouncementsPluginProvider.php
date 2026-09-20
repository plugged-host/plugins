<?php

namespace Boy132\Announcements\Providers;

use App\Enums\RolePermissionPrefixes;
use App\Models\Role;
use Illuminate\Support\ServiceProvider;

class AnnouncementsPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $permissions = [];

        foreach (RolePermissionPrefixes::cases() as $prefix) {
            $permissions[] = $prefix->value;
        }

        $permissions[] = 'sendMails';
        Role::registerCustomPermissions(['announcement' => $permissions]);

        Role::registerCustomModelIcon('announcement', 'tabler-speakerphone');
    }

    public function boot(): void {}
}
