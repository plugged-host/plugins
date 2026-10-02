<?php

use App\Tests\Integration\IntegrationTestCase;
use Boy132\Subdomains\Models\CloudflareDomain;
use Boy132\Subdomains\Models\Subdomain;
use Illuminate\Support\Facades\Http;
use PluggedHost\Economy\Services\TokenService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-economy');
    loadPluginForTest('subdomains');
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);
});

it('refunds a subdomain deposit once when the same subdomain is deleted twice at once', function () {
    [$user, $server] = generateTestAccount();
    $tokens = app(TokenService::class);
    expect($tokens->isEnabled())->toBeTrue();

    $domain = CloudflareDomain::withoutEvents(fn () => CloudflareDomain::query()->create(['name' => 'example.com', 'cloudflare_id' => 'zone1']));
    $created = Subdomain::withoutEvents(fn () => Subdomain::query()->create([
        'name' => 'play', 'record_type' => 'A', 'token_cost' => 8, 'cloudflare_id' => json_encode(['a' => 'rec1']),
        'domain_id' => $domain->id, 'server_id' => $server->id,
    ]));
    $before = $tokens->balance($user);

    // Two requests that both loaded the subdomain before either deleted it.
    $first = Subdomain::query()->findOrFail($created->id);
    $second = Subdomain::query()->findOrFail($created->id);
    $first->delete();
    $second->delete();

    expect($tokens->balance($user->fresh()))->toBe($before + 8);
});
