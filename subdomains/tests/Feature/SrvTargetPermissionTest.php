<?php

use App\Enums\RolePermissionModels;
use App\Models\Node;
use App\Models\Role;
use App\Tests\Integration\IntegrationTestCase;
use Boy132\Subdomains\Filament\Admin\Resources\SrvTargets\Pages\ManageSrvTargets;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('subdomains');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setCurrentPanel(null));

it('does not let an admin who can only view nodes change a node\'s SRV target', function () {
    $node = Node::factory()->create(['srv_target' => null]);
    $role = Role::factory()->create(['name' => 'Node Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::findOrCreate(RolePermissionModels::Node->viewAny(), 'web'));
    [$admin] = generateTestAccount([]);
    $admin->syncRoles($role);

    $this->actingAs($admin);

    Livewire::test(ManageSrvTargets::class)
        ->call('updateTableColumnState', 'srv_target', (string) $node->getKey(), 'attacker.example');

    expect($node->refresh()->srv_target)->toBeNull();
});

it('lets a root admin change a node\'s SRV target', function () {
    $node = Node::factory()->create(['srv_target' => null]);
    [$admin] = generateTestAccount([]);
    $admin->syncRoles(Role::getRootAdmin());

    $this->actingAs($admin);

    Livewire::test(ManageSrvTargets::class)
        ->call('updateTableColumnState', 'srv_target', (string) $node->getKey(), 'mc.example.com');

    expect($node->refresh()->srv_target)->toBe('mc.example.com');
});
