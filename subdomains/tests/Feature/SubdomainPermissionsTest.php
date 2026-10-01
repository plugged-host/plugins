<?php

use App\Enums\SubuserPermission;
use App\Tests\Integration\IntegrationTestCase;
use Boy132\Subdomains\Filament\Server\Resources\Subdomains\Pages\ListSubdomains;
use Boy132\Subdomains\Filament\Server\Resources\Subdomains\SubdomainResource;
use Boy132\Subdomains\Models\CloudflareDomain;
use Boy132\Subdomains\Models\Subdomain;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('subdomains');
    config()->set('subdomains.require_discord', false);
    config()->set('subdomains.require_discord_guild', false);
    Filament::setCurrentPanel('server');
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);
});

afterEach(fn () => Filament::setCurrentPanel(null));

function subdomainServerFor(array $permissions): array
{
    [$user, $server] = generateTestAccount($permissions);
    config()->set('subdomains.supported_eggs', [$server->egg_id]);
    $server->allocation->forceFill(['ip' => '203.0.113.10'])->save();

    $domain = CloudflareDomain::withoutEvents(fn () => CloudflareDomain::query()->create(['name' => 'example.com', 'cloudflare_id' => 'zone1']));
    $subdomain = Subdomain::withoutEvents(fn () => Subdomain::query()->create([
        'name' => 'play', 'record_type' => 'A', 'cloudflare_id' => json_encode(['a' => 'rec1']),
        'domain_id' => $domain->id, 'server_id' => $server->id,
    ]));

    return [$user, $server->fresh(), $subdomain];
}

it('is closed to a subuser without network permissions', function () {
    [$user, $server] = subdomainServerFor([SubuserPermission::WebsocketConnect->value]);
    $this->actingAs($user);
    Filament::setTenant($server);

    expect(SubdomainResource::canAccess())->toBeFalse();
});

it('does not let a subuser who can only view delete a subdomain', function () {
    [$user, $server, $subdomain] = subdomainServerFor([SubuserPermission::AllocationRead->value]);
    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ListSubdomains::class)->assertTableActionHidden(DeleteAction::class, $subdomain);

    expect(Subdomain::query()->whereKey($subdomain->id)->exists())->toBeTrue();
});

it('lets the owner delete a subdomain', function () {
    [$user, $server, $subdomain] = subdomainServerFor([]);
    $this->actingAs($user);
    Filament::setTenant($server);

    Livewire::test(ListSubdomains::class)->callTableAction(DeleteAction::class, $subdomain);

    expect(Subdomain::query()->whereKey($subdomain->id)->exists())->toBeFalse();
});
