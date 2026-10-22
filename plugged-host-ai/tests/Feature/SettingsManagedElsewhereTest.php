<?php

use App\Models\User;
use App\Tests\Integration\IntegrationTestCase;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Contracts\NeoSettingsStore;
use PluggedHost\Ai\Contracts\RuxSettingsSource;
use PluggedHost\Ai\PluggedHostAiPlugin;
use PluggedHost\Ai\Rux\Support\EnvRuxSettingsSource;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSettings;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;

/*
 * The plugin settings when an account system keeps some of them (noprofit.space with
 * plugged-host-noprofit): rux's tab only shows rux's settings, with a link to where they are
 * edited, and saving writes none of them here; neo's tab shows the connection fields the store
 * reports as managed read-only and never sends them, while what neo offers stays editable.
 * Standalone (nothing managed) both tabs edit everything as before.
 */

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

defined('SME_A') || define('SME_A', '130000000000000001');
defined('SME_B') || define('SME_B', '130000000000000002');

beforeEach(function () {
    loadPluginForTest('plugged-host-ai');
    Cache::flush();

    config()->set('plugged-host-ai.rux.enabled', true);
    config()->set('plugged-host-ai.rux.guild_id', SME_A);
    config()->set('plugged-host-ai.rux.servers', array_fill_keys(range(2, 10), ['id' => '', 'name' => '', 'about' => '', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => []]));

    // The account directory rux's status line asks about.
    $portal = Mockery::mock(AccountPortal::class);
    $portal->shouldReceive('name')->andReturn('accounts.example');
    $portal->shouldReceive('discordLinkUrl')->andReturn('https://accounts.example/discord');
    app()->instance(AccountPortal::class, $portal);
    foreach ([DiscordAccounts::class, DiscordCreditPayouts::class] as $contract) {
        $mock = Mockery::mock($contract);
        $mock->shouldReceive('configured')->andReturnTrue();
        app()->instance($contract, $mock);
    }
});

/**
 * A source that keeps rux's settings elsewhere.
 *
 * @param  array<string, mixed>  $settings  Laid over this panel's config.
 */
function smeManagedSource(array $settings): RuxSettingsSource
{
    return new class($settings) implements RuxSettingsSource
    {
        /** @param array<string, mixed> $settings */
        public function __construct(private readonly array $settings) {}

        public function guilds(): array
        {
            return EnvRuxSettingsSource::guildsFrom($this->settings());
        }

        public function settings(): array
        {
            return array_replace_recursive((array) config('plugged-host-ai.rux', []), $this->settings);
        }

        public function managedBy(): ?array
        {
            return ['by' => 'accounts.example', 'url' => 'https://accounts.example/admin/rux', 'fetched_at' => now()->getTimestamp()];
        }
    };
}

/**
 * Every form field in these components, however deep.
 *
 * @param  array<int, mixed>  $components
 * @return array<string, Field> By state path name.
 */
function smeFields(array $components): array
{
    $fields = [];

    foreach ($components as $component) {
        if ($component instanceof Field) {
            $fields[$component->getName()] = $component;

            continue;
        }

        if ($component instanceof Component) {
            $children = (fn (): array => is_array($this->childComponents['default'] ?? null) ? $this->childComponents['default'] : [])->call($component);
            $fields += smeFields($children);
        }
    }

    return $fields;
}

function smeDisabled(Field $field): bool
{
    return (bool) (fn () => $this->isDisabled)->call($field);
}

/**
 * The plugin, with what it would write to the .env caught instead.
 */
function smePlugin(): PluggedHostAiPlugin
{
    return new class extends PluggedHostAiPlugin
    {
        /** @var array<string, string> */
        public array $written = [];

        public function writeToEnvironment(array $values = []): void
        {
            $this->written = $values;
        }
    };
}

/**
 * A neo settings store answering like noprofit.space's GET /neo/config.
 *
 * @param  array<string, mixed>|null  $managed
 */
function smeStore(?array $managed, bool $offered): NeoSettingsStore
{
    return new class($managed, $offered) implements NeoSettingsStore
    {
        /** @var array<string, mixed>|null */
        public ?array $saved = null;

        /** @param array<string, mixed>|null $managed */
        public function __construct(private readonly ?array $managed, private readonly bool $offered) {}

        public function label(): string
        {
            return 'accounts.example';
        }

        public function config(): array
        {
            $provider = [
                'slot' => 1, 'enabled' => true, 'label' => 'Model one', 'driver' => 'openai', 'endpoint' => 'https://api.example.com/v1',
                'api_key_set' => true, 'api_key_hint' => 'abcd', 'model' => 'm-1', 'max_tokens' => 4096, 'timeout' => 30, 'daily_token_cap' => 0,
                'extra_headers' => [], 'extra_body' => [], 'vision' => false, 'description' => 'Fast', 'access' => 'everyone', 'limited' => false,
                'weekly_tokens' => 0, 'multiplier' => 1, 'thinking' => true, 'usable' => true, 'cooldown_until' => null, 'last_error' => null, 'tokens_today' => 0,
            ] + ($this->offered ? ['offered' => true] : []);

            return [
                'env_writable' => true,
                'gateway_enabled' => true,
                'limits' => ['mode' => 'models', 'short_window_hours' => 5, 'short_tokens' => 1000000, 'long_window_days' => 7, 'long_tokens' => 5000000, 'discord_unlinked_percent' => 50, 'plusplus_percent' => 150],
                'default_model' => 0,
                'failover' => ['default_cooldown' => 60, 'deadline' => 150, 'max_concurrent' => 6],
                'providers' => [$provider],
            ] + ($this->managed !== null ? ['managed' => $this->managed] : []);
        }

        public function saveConfig(array $settings, User $actor): array
        {
            $this->saved = $settings;

            return $this->config();
        }

        public function testProvider(int $slot, array $overrides, User $actor): array
        {
            return ['ok' => true];
        }
    };
}

/**
 * @return list<Component>
 */
function smeNeoForm(PluggedHostAiPlugin $plugin): array
{
    return (fn (): array => $this->neoForm())->call($plugin);
}

it('keeps the rux tab editable while this panel\'s own settings apply', function () {
    expect(app(RuxSettingsSource::class))->toBeInstanceOf(EnvRuxSettingsSource::class)
        ->and(RuxSettings::managed())->toBeNull();

    $form = RuxSettings::form();
    $fields = smeFields($form);

    expect(collect($form)->contains(fn ($component) => $component instanceof Callout && str_starts_with((string) $component->getHeading(), 'Set up on')))->toBeFalse()
        ->and($fields)->toHaveKey('bot_token')
        ->and(collect($fields)->contains(fn (Field $field) => smeDisabled($field)))->toBeFalse();

    // Saving writes rux's settings to this panel.
    $plugin = smePlugin();
    $plugin->saveSettings(['enabled' => true, 'rux' => RuxSettings::data()]);
    expect($plugin->written)->toHaveKey('RUX_ENABLED')->toHaveKey('RUX_GUILD_ID', SME_A);
});

it('shows the rux tab read-only with a link, and saves none of it, while the settings are kept elsewhere', function () {
    app()->instance(RuxSettingsSource::class, smeManagedSource([
        'enabled' => false,
        'timezone' => 'Europe/Berlin',
        'spam' => ['enabled' => false],
        'servers' => [2 => ['id' => SME_B, 'name' => 'example.org', 'about' => 'a non-profit', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => ['example.org']]],
    ]));

    $data = RuxSettings::data();
    expect($data['enabled'])->toBeFalse()
        ->and($data['timezone'])->toBe('Europe/Berlin')
        ->and($data['spam'])->toBeFalse()
        ->and($data['server_2'])->toMatchArray(['id' => SME_B, 'name' => 'example.org', 'trusted_domains' => 'example.org']);

    $form = RuxSettings::form();
    $callout = collect($form)->first(fn ($component) => $component instanceof Callout && $component->getHeading() === 'Set up on accounts.example');
    expect($callout)->not->toBeNull();

    $link = collect((fn (): array => $this->footerActions)->call($callout))->first(fn ($action) => $action instanceof Action);
    expect($link?->getUrl())->toBe('https://accounts.example/admin/rux');

    $fields = smeFields($form);
    expect($fields)->toHaveKeys(['enabled', 'bot_token', 'guild_id', 'timezone', 'spam', 'trusted_domains', 'id', 'name']);
    foreach ($fields as $field) {
        expect(smeDisabled($field))->toBeTrue()
            ->and($field->isValidatedWhenNotDehydrated())->toBeFalse();
    }

    // Only the servers it has, no spare slots.
    $servers = collect($form)->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'More Discord servers');
    expect(array_map(fn (Section $section) => $section->getHeading(), (fn (): array => $this->childComponents['default'])->call($servers)))->toBe(['Server 2']);

    // Saving the plugin settings writes neo's part only: rux's stays as this panel had it.
    $plugin = smePlugin();
    $plugin->saveSettings(['enabled' => true, 'rux' => ['enabled' => false]]);
    expect($plugin->written)->toHaveKey('NEO_ENABLED', 'true')
        ->and(collect($plugin->written)->keys()->filter(fn (string $key) => str_starts_with($key, 'RUX_'))->all())->toBe([]);

    // The blockers point there too.
    expect(implode(' ', RuxConfig::blockers()))->toContain('Admin > rux on accounts.example');
});

it('edits every neo provider field while the store manages none of them', function () {
    $store = smeStore(null, false);
    app()->instance(NeoSettingsStore::class, $store);

    $plugin = new PluggedHostAiPlugin();
    $fields = smeFields(smeNeoForm($plugin));

    expect($fields)->toHaveKeys(['remote.providers.1.endpoint', 'remote.providers.1.api_key', 'remote.failover.deadline'])
        ->not->toHaveKey('remote.providers.1.offered')
        ->and(collect($fields)->contains(fn (Field $field) => smeDisabled($field)))->toBeFalse();

    $data = $plugin->getSettingsFormData()['remote'];
    $actor = User::factory()->create();
    $this->actingAs($actor);

    expect((fn (array $remote, ?array $managed) => self::saveRemote($remote, $managed))->call($plugin, $data, null))->toBeNull()
        ->and($store->saved['providers']['1'])->toHaveKeys(['enabled', 'driver', 'endpoint', 'model', 'max_tokens', 'access', 'description'])
        ->not->toHaveKey('offered')
        ->and($store->saved)->toHaveKey('failover');
});

it('shows the managed connection fields read-only with a link and never sends them', function () {
    $managed = [
        'by' => 'accounts.example',
        'url' => 'https://accounts.example/admin/ai',
        'providers' => ['enabled', 'label', 'driver', 'endpoint', 'api_key', 'clear_api_key', 'model', 'max_tokens', 'timeout', 'daily_token_cap', 'vision', 'thinking', 'extra_headers', 'extra_body'],
        'failover' => true,
    ];
    $store = smeStore($managed, true);
    app()->instance(NeoSettingsStore::class, $store);

    $plugin = new PluggedHostAiPlugin();
    $form = smeNeoForm($plugin);
    $fields = smeFields($form);

    foreach (['enabled', 'label', 'driver', 'endpoint', 'api_key', 'model', 'max_tokens', 'timeout', 'daily_token_cap', 'vision', 'thinking', 'extra_headers', 'extra_body'] as $name) {
        expect(smeDisabled($fields["remote.providers.1.{$name}"]))->toBeTrue("{$name} should be read-only");
    }

    foreach (['offered', 'description', 'access', 'limited', 'weekly_tokens', 'multiplier'] as $name) {
        expect(smeDisabled($fields["remote.providers.1.{$name}"]))->toBeFalse("{$name} should stay editable");
    }

    expect(smeDisabled($fields['remote.failover.deadline']))->toBeTrue()
        ->and(smeDisabled($fields['remote.limits.mode']))->toBeFalse()
        ->and(smeDisabled($fields['remote.default_model']))->toBeFalse()
        ->and(smeDisabled($fields['remote.limits.discord_unlinked_percent']))->toBeFalse();

    // The link to where they are set.
    $status = collect($form)->first(fn ($component) => $component instanceof Section && $component->getHeading() === trans('plugged-host-ai::strings.settings.section_status'));
    $actions = collect((fn (): array => $this->childComponents['default'])->call($status))->first(fn ($component) => $component instanceof Actions);
    $link = collect((fn (): array => $this->childComponents['default'] ?? [])->call($actions))->first(fn ($action) => $action instanceof Action);
    expect($link?->getUrl())->toBe('https://accounts.example/admin/ai');

    // What the form would hand in: the disabled fields are not part of it.
    $data = $plugin->getSettingsFormData()['remote'];
    $data['providers']['1'] = array_intersect_key($data['providers']['1'], array_flip(['offered', 'description', 'access', 'limited', 'weekly_tokens', 'multiplier']));
    $data['providers']['1']['offered'] = false;
    unset($data['failover']);

    $this->actingAs(User::factory()->create());

    expect((fn (array $remote, ?array $managed) => self::saveRemote($remote, self::managed(self::remoteConfig())))->call($plugin, $data, null))->toBeNull()
        ->and(array_keys($store->saved['providers']['1']))->toEqualCanonicalizing(['description', 'access', 'limited', 'weekly_tokens', 'multiplier', 'offered'])
        ->and($store->saved['providers']['1']['offered'])->toBeFalse()
        ->and($store->saved)->not->toHaveKey('failover')
        ->and($store->saved['limits']['mode'])->toBe('models');
});
