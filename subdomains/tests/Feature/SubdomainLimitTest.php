<?php

use App\Tests\Integration\IntegrationTestCase;
use Boy132\Subdomains\Models\CloudflareDomain;
use Boy132\Subdomains\Models\Subdomain;
use Boy132\Subdomains\Services\SubdomainService;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('subdomains');
    config()->set('subdomains.require_discord', false);
    config()->set('subdomains.require_discord_guild', false);
    config()->set('subdomains.token_costs', [1 => 0, 2 => 0, 3 => 0, 'default' => 0]);
    Http::fake(fn (Illuminate\Http\Client\Request $request) => Http::response([
        'success' => true,
        'result' => $request->method() === 'GET' ? [] : ['id' => 'new-record'],
    ]));
});

it('enforces the subdomain limit when creating, not only in the UI', function () {
    [, $server] = generateTestAccount();
    config()->set('subdomains.supported_eggs', [$server->egg_id]);
    $server->forceFill(['subdomain_limit' => 1])->save();

    $domain = CloudflareDomain::withoutEvents(fn () => CloudflareDomain::query()->create(['name' => 'example.com', 'cloudflare_id' => 'zone1']));
    Subdomain::withoutEvents(fn () => Subdomain::query()->create(['name' => 'first', 'record_type' => 'A', 'domain_id' => $domain->id, 'server_id' => $server->id]));

    expect(fn () => app(SubdomainService::class)->handle(['name' => 'second', 'domain_id' => $domain->id, 'server_id' => $server->id]))
        ->toThrow(Exception::class)
        ->and(Subdomain::query()->where('server_id', $server->id)->count())->toBe(1);
});
