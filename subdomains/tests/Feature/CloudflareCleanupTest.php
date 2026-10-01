<?php

use App\Tests\Integration\IntegrationTestCase;
use Boy132\Subdomains\Models\CloudflareDomain;
use Boy132\Subdomains\Models\Subdomain;
use Boy132\Subdomains\Services\SubdomainService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('subdomains');

    config()->set('subdomains.require_discord', false);
    config()->set('subdomains.require_discord_guild', false);
});

/**
 * Fakes the Cloudflare API: a name lookup returns $records, every other call succeeds.
 *
 * @param  array<int, array<string, mixed>>  $records
 */
function fakeCloudflareZone(array $records): void
{
    Http::fake(function (Request $request) use ($records) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'dns_records')) {
            return Http::response(['success' => true, 'result' => $records]);
        }

        return Http::response(['success' => true, 'result' => ['id' => 'new-record']]);
    });
}

function deletedRecordIds(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->method() === 'DELETE')
        ->map(fn (array $pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)))
        ->values()
        ->all();
}

it('does not delete an existing record when creating a subdomain with its name fails', function () {
    [, $server] = generateTestAccount();
    config()->set('subdomains.supported_eggs', [$server->egg_id]);

    $domain = CloudflareDomain::withoutEvents(fn () => CloudflareDomain::query()->create(['name' => 'example.com', 'cloudflare_id' => 'zone1']));

    fakeCloudflareZone([
        ['id' => 'operator-record', 'name' => 'blog.example.com', 'type' => 'A', 'comment' => null],
    ]);

    expect(fn () => app(SubdomainService::class)->handle([
        'name' => 'blog',
        'domain_id' => $domain->id,
        'server_id' => $server->id,
    ]))->toThrow(Exception::class);

    expect(Subdomain::query()->count())->toBe(0)
        ->and(deletedRecordIds())->not->toContain('operator-record');
});

it('still removes its own records, including strays, when a subdomain is deleted', function () {
    [, $server] = generateTestAccount();

    $domain = CloudflareDomain::withoutEvents(fn () => CloudflareDomain::query()->create(['name' => 'example.com', 'cloudflare_id' => 'zone1']));

    $subdomain = Subdomain::withoutEvents(fn () => Subdomain::query()->create([
        'name' => 'play',
        'record_type' => 'A',
        'cloudflare_id' => json_encode(['a' => 'own-a']),
        'domain_id' => $domain->id,
        'server_id' => $server->id,
    ]));

    fakeCloudflareZone([
        ['id' => 'own-a', 'name' => 'play.example.com', 'type' => 'A', 'comment' => "Created by plugged.host Subdomains (Server #{$server->id})"],
        ['id' => 'own-stray', 'name' => 'play.example.com', 'type' => 'AAAA', 'comment' => "Created by plugged.host Subdomains (Server #{$server->id})"],
        ['id' => 'other-server', 'name' => 'play.example.com', 'type' => 'TXT', 'comment' => 'Created by plugged.host Subdomains (Server #' . ($server->id + 1000) . ')'],
        ['id' => 'operator-record', 'name' => 'play.example.com', 'type' => 'TXT', 'comment' => null],
    ]);

    $subdomain->delete();

    expect(deletedRecordIds())
        ->toContain('own-a')
        ->toContain('own-stray')
        ->not->toContain('other-server')
        ->not->toContain('operator-record');
});
