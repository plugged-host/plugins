<?php

use App\Tests\Integration\IntegrationTestCase;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Ai\Contracts\RuxSettingsSource;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxGuild;
use PluggedHost\Ai\Rux\Support\RuxSettings;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Noprofit\Ai\NoprofitRuxSettings;
use PluggedHost\Noprofit\Console\Commands\RuxSettingsUploadCommand;
use PluggedHost\Noprofit\Services\NoprofitClient;

/*
 * rux's settings with plugged-host-noprofit: this panel's own until noprofit.space says it keeps
 * them (Admin > rux), noprofit.space's from then on, fetched at most once a minute, shared through
 * an encrypted copy and kept through outages; and the one-time move of this panel's own there.
 */

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

defined('NRS_A') || define('NRS_A', '120000000000000001');
defined('NRS_B') || define('NRS_B', '120000000000000002');
defined('NRS_C') || define('NRS_C', '120000000000000003');
defined('NRS_URL') || define('NRS_URL', 'https://accounts.test/api/v1/internal/rux/settings');

beforeEach(function () {
    loadPluginForTest('plugged-host-ai');
    loadPluginForTest('plugged-host-noprofit');

    // The panel registers plugin commands at boot, which tests skip.
    app(Kernel::class)->registerCommand(app(RuxSettingsUploadCommand::class));

    config()->set('plugged-host-noprofit.accounts_url', 'https://accounts.test');
    config()->set('plugged-host-noprofit.sync_secret', str_repeat('s', 64));
    config()->set('plugged-host-noprofit.resolve_ip', null);

    // This panel's own settings (the rux tab's).
    config()->set('plugged-host-ai.rux.enabled', true);
    config()->set('plugged-host-ai.rux.bot_token', 'panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    config()->set('plugged-host-ai.rux.guild_id', NRS_A);
    config()->set('plugged-host-ai.rux.giveaway_channel_id', '320000000000000001');
    config()->set('plugged-host-ai.rux.mod_log_channel_id', '320000000000000002');
    config()->set('plugged-host-ai.rux.trusted_domains', ['example.host']);
    config()->set('plugged-host-ai.rux.servers', array_fill_keys(range(2, 10), ['id' => '', 'name' => '', 'about' => '', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => []]));
    config()->set('plugged-host-ai.rux.servers.2', ['id' => NRS_C, 'name' => 'Example.cloud', 'about' => 'a free VPS host', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => ['Example.cloud']]);
    config()->set('plugged-host-ai.rux.credits.granters', ['520000000000000001']);
    config()->set('plugged-host-ai.rux.spam.enabled', true);
    config()->set('plugged-host-ai.rux.warnings.timeout', '24H');

    @unlink(app(RuxSupervisor::class)->runtimeDirectory() . '/rux-settings.enc');
    Cache::flush();
    app()->forgetInstance(RuxSettingsSource::class);
    app()->forgetInstance(NoprofitRuxSettings::class);
});

afterEach(function () {
    @unlink(app(RuxSupervisor::class)->runtimeDirectory() . '/rux-settings.enc');
});

/**
 * noprofit.space's settings as GET …/rux/settings answers them.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nrsRemote(array $overrides = []): array
{
    return array_replace_recursive([
        'bot_token' => 'noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'enabled' => true,
        'guild_id' => NRS_A,
        'giveaway_channel_id' => '330000000000000001',
        'mod_log_channel_id' => '330000000000000002',
        'trusted_domains' => ['example.host', 'example.org'],
        'servers' => [
            ['id' => NRS_B, 'name' => 'example.org', 'about' => 'the non-profit behind it', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '330000000000000003', 'trusted_domains' => ['forms.example.org']],
        ],
        'timezone' => 'Europe/Berlin',
        'locale' => 'en',
        'giveaway' => ['max_credits' => 500, 'max_winners' => 5, 'max_days' => 7],
        'credits' => ['granters' => ['520000000000000009'], 'max_per_grant' => 50],
        'warnings' => ['timeout_at' => 2, 'timeout' => '1h', 'ban_at' => 4, 'ban' => '7d'],
        'rules' => ['cooldown_seconds' => 120],
        'watch' => ['guidelines' => false],
        'spam' => ['enabled' => false, 'flood_messages' => 9, 'flood_seconds' => 12, 'duplicates' => 4, 'duplicate_seconds' => 60, 'mentions' => 8, 'emoji' => 30],
        'messages_per_minute' => 3,
        'agent' => ['max_steps' => 6, 'history_minutes' => 20],
    ], $overrides);
}

/**
 * @param  array<string, mixed>|null  $settings  null: noprofit.space keeps none.
 */
function nrsAnswer(?array $settings): array
{
    return [
        'configured' => $settings !== null,
        'settings' => $settings,
        'saved_at' => $settings !== null ? '2026-10-22T20:00:00+00:00' : null,
        'saved_by' => $settings !== null ? 'admin' : null,
        'admin_url' => 'https://accounts.test/admin/rux',
    ];
}

it('binds noprofit.space\'s source and keeps this panel\'s own settings until noprofit.space has them', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(null))]);

    expect(app(RuxSettingsSource::class))->toBeInstanceOf(NoprofitRuxSettings::class)
        ->and(RuxConfig::token())->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and(RuxConfig::guildIds())->toBe([NRS_A, NRS_C])
        ->and(RuxConfig::get('spam.enabled'))->toBeTrue()
        ->and(RuxConfig::managedBy())->toBeNull()
        ->and(RuxSettings::managed())->toBeNull()
        ->and(RuxConfig::settings())->toBe(config('plugged-host-ai.rux'));

    // The request is signed like rux's other calls.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === NRS_URL
        && $request->hasHeader('X-NP-Signature') && $request->hasHeader('X-NP-Nonce') && $request->hasHeader('X-NP-Timestamp'));
});

it('runs rux on noprofit.space\'s settings once it keeps them', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(nrsRemote()))]);

    $guilds = RuxConfig::guilds();

    expect(RuxConfig::token())->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        ->and(RuxConfig::guildIds())->toBe([NRS_A, NRS_B])
        ->and($guilds[0]->giveawayChannelId)->toBe('330000000000000001')
        ->and($guilds[0]->trustedDomains)->toBe(['example.host', 'example.org'])
        ->and($guilds[1])->toBeInstanceOf(RuxGuild::class)
        ->and($guilds[1]->hostName())->toBe('example.org')
        ->and($guilds[1]->about())->toBe('the non-profit behind it')
        ->and($guilds[1]->modLogChannelId)->toBe('330000000000000003')
        ->and($guilds[1]->trustedDomains)->toBe(['example.host', 'example.org', 'forms.example.org'])
        ->and(RuxConfig::timezone())->toBe('Europe/Berlin')
        ->and(RuxConfig::get('spam.enabled'))->toBeFalse()
        ->and(RuxConfig::get('spam.flood_messages'))->toBe(9)
        ->and(RuxConfig::get('watch.guidelines'))->toBeFalse()
        ->and(RuxConfig::get('credits.granters'))->toBe(['520000000000000009'])
        ->and(RuxConfig::get('giveaway.max_credits'))->toBe(500)
        ->and(RuxConfig::get('agent.max_steps'))->toBe(6)
        // What noprofit.space does not keep stays this panel's.
        ->and(RuxConfig::get('giveaway.max_exclude_recent'))->toBe(10)
        ->and(RuxConfig::get('gateway_intents'))->toBe(config('plugged-host-ai.rux.gateway_intents'))
        ->and(RuxConfig::get('agent.busy_wait_seconds'))->toBe(150)
        // The server list is noprofit.space's whole: this panel's slot 2 is gone, the rest empty.
        ->and(RuxConfig::get('servers.2.id'))->toBe(NRS_B)
        ->and(RuxConfig::get('servers.3.id'))->toBe('')
        ->and(RuxConfig::get('servers.10.id'))->toBe('');

    expect(RuxConfig::managedBy())->toMatchArray([
        'by' => 'accounts.test',
        'url' => 'https://accounts.test/admin/rux',
        'saved_at' => '2026-10-22T20:00:00+00:00',
    ])
        ->and(RuxSettings::managed())->not->toBeNull();

    // This panel's own config is left as it is.
    expect(config('plugged-host-ai.rux.bot_token'))->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

it('only links to noprofit.space\'s own address', function () {
    Http::fake([NRS_URL => Http::response(['admin_url' => 'https://evil.test/admin/rux'] + nrsAnswer(nrsRemote()))]);

    expect(RuxConfig::managedBy()['url'])->toBe('https://accounts.test/admin/rux');
});

it('fetches at most once a minute, however often rux reads its settings', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(nrsRemote()))]);

    for ($i = 0; $i < 50; $i++) {
        RuxConfig::guilds();
        RuxConfig::get('spam.enabled');
    }

    Http::assertSentCount(1);

    $this->travel(30)->seconds();
    RuxConfig::guilds();
    Http::assertSentCount(1);

    $this->travel(31)->seconds();
    RuxConfig::guilds();
    Http::assertSentCount(2);
});

it('shares a fetch with every process through an encrypted copy', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(nrsRemote()))]);

    expect(RuxConfig::token())->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');

    $path = app(RuxSupervisor::class)->runtimeDirectory() . '/rux-settings.enc';
    expect(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->not->toContain('noprofit.token')
        ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('600');

    // Another process: no fetch of its own while the copy is fresh.
    $other = nrsOtherProcess();
    expect(nrsTokenOf($other))->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    Http::assertSentCount(1);
});

/** The source as another process on this box has it: nothing in memory yet. */
function nrsOtherProcess(): NoprofitRuxSettings
{
    return new NoprofitRuxSettings(app(NoprofitClient::class), app(RuxSupervisor::class));
}

function nrsTokenOf(NoprofitRuxSettings $source): string
{
    return (string) ($source->settings()['bot_token'] ?? '');
}

it('keeps the last settings while noprofit.space is down, without asking it for every read', function () {
    $down = false;
    Http::fake(function () use (&$down) {
        return $down ? Http::response('', 503) : Http::response(nrsAnswer(nrsRemote()));
    });

    expect(RuxConfig::guildIds())->toBe([NRS_A, NRS_B]);

    $down = true;
    $this->travel(2)->minutes();

    for ($i = 0; $i < 20; $i++) {
        expect(RuxConfig::guildIds())->toBe([NRS_A, NRS_B]);
    }

    // One failed try, then a rest of RETRY_AFTER seconds, also for other processes.
    Http::assertSentCount(2);
    expect(nrsOtherProcess()->settings()['bot_token'])->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    Http::assertSentCount(2);
    expect(RuxConfig::managedBy())->not->toBeNull();

    $this->travel(NoprofitRuxSettings::RETRY_AFTER + 1)->seconds();
    RuxConfig::guilds();
    Http::assertSentCount(3);

    // Back up: the next fetch is taken again.
    $down = false;
    $this->travel(NoprofitRuxSettings::RETRY_AFTER + 1)->seconds();
    RuxConfig::guilds();
    Http::assertSentCount(4);
});

it('runs on this panel\'s own settings when noprofit.space is down and there is no copy', function (Closure $answer) {
    Http::fake([NRS_URL => $answer]);

    expect(RuxConfig::token())->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and(RuxConfig::managedBy())->toBeNull();
})->with([
    'an error' => fn () => fn () => Http::response(['error' => 'oops'], 500),
    'no connection' => fn () => fn () => throw new ConnectionException('down'),
    'not JSON' => fn () => fn () => Http::response('<html>', 200),
]);

it('does not take a configured answer without the settings', function () {
    Http::fake([NRS_URL => Http::sequence()
        ->push(nrsAnswer(nrsRemote()))
        ->push(['configured' => true, 'settings' => null])
        ->push(['configured' => true, 'settings' => ['enabled' => 'yes']]),
    ]);

    expect(RuxConfig::token())->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');

    $this->travel(2)->minutes();
    expect(RuxConfig::token())->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');

    $this->travel(2)->minutes();
    expect(RuxConfig::token())->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    Http::assertSentCount(3);
});

it('switches back to this panel\'s own settings when noprofit.space stops keeping them', function () {
    Http::fake([NRS_URL => Http::sequence()->push(nrsAnswer(nrsRemote()))->push(nrsAnswer(null))]);

    expect(RuxConfig::token())->toBe('noprofit.token-bbbbbbbbbbbbbbbbbbbbbbbbbbbb');

    $this->travel(2)->minutes();
    expect(RuxConfig::token())->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and(RuxConfig::managedBy())->toBeNull();
});

it('asks nobody and keeps this panel\'s own without noprofit.space\'s secret', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(nrsRemote()))]);
    RuxConfig::guilds();

    config()->set('plugged-host-noprofit.sync_secret', '');
    app()->forgetInstance(RuxSettingsSource::class);
    app()->forgetInstance(NoprofitRuxSettings::class);

    expect(RuxConfig::token())->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and(RuxConfig::managedBy())->toBeNull();
    Http::assertSentCount(1);
});

it('gives noprofit.space this panel\'s settings in its shape, and takes them back unchanged', function () {
    $panel = (array) config('plugged-host-ai.rux');
    $sent = NoprofitRuxSettings::forNoprofit($panel);

    expect($sent['servers'])->toBe([['id' => NRS_C, 'name' => 'Example.cloud', 'about' => 'a free VPS host', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => ['example.cloud']]])
        ->and($sent['bot_token'])->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($sent['warnings']['timeout'])->toBe('24h')
        ->and($sent['credits']['granters'])->toBe(['520000000000000001'])
        ->and(NoprofitRuxSettings::forNoprofit(NoprofitRuxSettings::overlay($panel, $sent)))->toBe($sent);
});

it('moves this panel\'s settings to noprofit.space once and compares every field', function () {
    $kept = null;
    Http::fake(function (Request $request) use (&$kept) {
        if ($request->method() === 'PUT') {
            $kept = $request->data()['settings'];

            return Http::response(['configured' => true, 'changed' => ['enabled', 'servers'], 'bot_token_set' => true, 'admin_url' => 'https://accounts.test/admin/rux']);
        }

        // noprofit.space answers with what it took (its token decrypted back).
        return Http::response(nrsAnswer($kept));
    });

    expect(Artisan::call('plugged-host:rux-settings-upload'))->toBe(0)
        ->and(Artisan::output())->toContain('Every setting is the same on noprofit.space.')
        ->not->toContain('panel.token-');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['replace'] === false
        && $request['actor']['id'] === 'cli'
        && $request['settings']['bot_token'] === 'panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        && $request['settings']['servers'][0]['id'] === NRS_C);

    // From now on rux runs on noprofit.space's copy: the same values.
    expect(RuxConfig::managedBy())->not->toBeNull()
        ->and(RuxConfig::token())->toBe('panel.token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->and(RuxConfig::guildIds())->toBe([NRS_A, NRS_C]);
});

it('sends nothing once noprofit.space keeps its own, and reports what differs', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(nrsRemote()))]);

    expect(Artisan::call('plugged-host:rux-settings-upload'))->toBe(0)
        ->and(Artisan::output())->toContain('nothing sent')->toContain('NO');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

    expect(Artisan::call('plugged-host:rux-settings-upload', ['--verify' => true]))->toBe(1);
});

it('sends nothing on a dry run', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(null))]);

    expect(Artisan::call('plugged-host:rux-settings-upload', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Would send')->toContain('set (sha1 ')->not->toContain('panel.token-');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('shows rux\'s tab read-only with a link while noprofit.space keeps the settings', function () {
    Http::fake([NRS_URL => Http::response(nrsAnswer(nrsRemote()))]);

    $data = RuxSettings::data();
    expect($data['spam'])->toBeFalse()
        ->and($data['timezone'])->toBe('Europe/Berlin')
        ->and($data['server_2']['id'])->toBe(NRS_B)
        ->and($data['bot_token'])->toBe('');

    $form = RuxSettings::form();
    $callout = collect($form)->first(fn ($component) => $component instanceof Callout && $component->getHeading() === 'Set up on accounts.test');
    expect($callout)->not->toBeNull();

    $fields = nrsFields($form);
    expect($fields)->not->toBeEmpty();
    foreach ($fields as $field) {
        expect((fn () => $this->isDisabled)->call($field))->toBeTrue();
    }

    // Only the servers noprofit.space has: no spare slots to fill in.
    $servers = collect($form)->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'More Discord servers');
    expect(array_map(fn ($section) => $section->getHeading(), (fn (): array => $this->childComponents['default'])->call($servers)))->toBe(['Server 2']);
});

/**
 * Every form field in these components, however deep.
 *
 * @param  array<int, mixed>  $components
 * @return list<Field>
 */
function nrsFields(array $components): array
{
    $fields = [];

    foreach ($components as $component) {
        if ($component instanceof Field) {
            $fields[] = $component;

            continue;
        }

        if ($component instanceof Component) {
            $children = (fn (): array => is_array($this->childComponents['default'] ?? null) ? $this->childComponents['default'] : [])->call($component);
            $fields = [...$fields, ...nrsFields($children)];
        }
    }

    return $fields;
}
