<?php

namespace PluggedHost\Ai;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\User;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Chat\ProviderChain;
use PluggedHost\Ai\Contracts\NeoSettingsStore;
use PluggedHost\Ai\NeoBot\NeoBotSettings;
use PluggedHost\Ai\Rux\Support\RuxSettings;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use stdClass;
use Throwable;

/**
 * plugged.host AI's Filament registration (no pages of its own: neo's chat is wired by
 * NeoPluginProvider, the two Discord bots by NeoBotServiceProvider and RuxServiceProvider) and
 * its settings, one tab each for neo, neo on Discord (NeoBotSettings) and rux (RuxSettings).
 *
 * neo's tab edits two things:
 *   - panel-side values (master switch, loop budget, privacy switches) go to this panel's .env
 *     through EnvironmentWriterTrait, like every plugged.host plugin;
 *   - provider slots and usage limits go to the bound NeoSettingsStore: this panel's .env too
 *     (EnvNeoSettingsStore), or noprofit.space over its signed API with plugged-host-noprofit
 *     (whose settings hold the gateway secret), so they never have to be hand-edited.
 *
 * Secrets follow the "masked, save-if-changed" convention (see PluggedHostMinecraftPlugin):
 * never loaded into the form, written only when a new value is typed. Upstream swallows any
 * exception from these methods (App\Models\Plugin), so every outcome is reported through a
 * Notification instead.
 */
class PluggedHostAiPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    private const REMOTE_CACHE = 'plugged-host-ai:remote-config';

    public function getId(): string
    {
        return 'plugged-host-ai';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        $data = [
            'enabled' => (bool) config('plugged-host-ai.enabled', false),
            'max_steps' => (int) config('plugged-host-ai.max_steps', 10),
            'context_tokens' => (int) config('plugged-host-ai.context_tokens', 16000),
            'redact_secrets' => (bool) config('plugged-host-ai.redact_secrets', true),
            'always_confirm_destructive' => (bool) config('plugged-host-ai.always_confirm_destructive', true),
            'retention_days' => (int) config('plugged-host-ai.retention_days', 30),
            'support_url' => (string) config('plugged-host-ai.support_url', ''),
        ];

        $data['neo_bot'] = NeoBotSettings::data();
        $data['rux'] = RuxSettings::data();

        $remote = self::remoteConfig();
        if (!$remote['ok']) {
            return $data;
        }

        $config = $remote['data'];
        $providers = [];
        foreach ((array) ($config['providers'] ?? []) as $provider) {
            $providers[(string) $provider['slot']] = [
                'enabled' => (bool) $provider['enabled'],
                'label' => (string) $provider['label'],
                'driver' => (string) $provider['driver'],
                'endpoint' => (string) $provider['endpoint'],
                'api_key' => '',
                'clear_api_key' => false,
                'model' => (string) $provider['model'],
                'max_tokens' => (int) $provider['max_tokens'],
                'timeout' => (int) $provider['timeout'],
                'daily_token_cap' => (int) $provider['daily_token_cap'],
                // The slot's flag, or until it is saved what its driver suggests (ProviderChain).
                'vision' => ProviderChain::seesImages((array) $provider),
                'premium' => (bool) ($provider['premium'] ?? false),
                'extra_headers' => self::prettyJson($provider['extra_headers'] ?? []),
                'extra_body' => self::prettyJson($provider['extra_body'] ?? []),
            ];
        }

        $data['remote'] = [
            'gateway_enabled' => (bool) ($config['gateway_enabled'] ?? true),
            'limits' => (array) ($config['limits'] ?? []),
            'failover' => (array) ($config['failover'] ?? []),
            'providers' => $providers,
        ];

        return $data;
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        $t = fn (string $key): string => trans('plugged-host-ai::strings.settings.' . $key);

        return [
            Tabs::make('plugged-host-ai')->tabs([
                Tab::make($t('tab_neo'))->schema($this->neoForm()),
                Tab::make($t('tab_neo_bot'))->statePath('neo_bot')->schema(NeoBotSettings::form()),
                Tab::make($t('tab_rux'))->statePath('rux')->schema(RuxSettings::form()),
            ]),
        ];
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    private function neoForm(): array
    {
        $t = fn (string $key, array $replace = []): string => trans('plugged-host-ai::strings.settings.' . $key, $replace);
        $remote = self::remoteConfig();
        $where = ['where' => app(NeoSettingsStore::class)->label()];

        $form = [
            Section::make($t('section_panel'))
                ->description($t('section_panel_hint'))
                ->schema([
                    Toggle::make('enabled')->label($t('enabled'))->helperText($t('enabled_hint')),
                    Grid::make(2)->schema([
                        TextInput::make('max_steps')->label($t('max_steps'))->helperText($t('max_steps_hint'))->numeric()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('context_tokens')->label($t('context_tokens'))->helperText($t('context_tokens_hint'))->numeric()->minValue(4000)->maxValue(200000)->required(),
                        TextInput::make('retention_days')->label($t('retention_days'))->numeric()->minValue(1)->maxValue(365)->required(),
                        TextInput::make('support_url')->label($t('support_url'))->url()->maxLength(255),
                    ]),
                    Toggle::make('redact_secrets')->label($t('redact_secrets'))->helperText($t('redact_secrets_hint')),
                    Toggle::make('always_confirm_destructive')->label($t('always_confirm_destructive'))->helperText($t('always_confirm_destructive_hint')),
                ]),
            Section::make($t('section_status'))->schema([
                Text::make(match (true) {
                    !$remote['ok'] => $t('unreachable', $where + ['reason' => $remote['error']]),
                    !($remote['data']['env_writable'] ?? false) => $t('env_not_writable', $where),
                    default => $t('connected', $where),
                }),
            ]),
        ];

        if (!$remote['ok']) {
            return $form;
        }

        foreach ((array) ($remote['data']['providers'] ?? []) as $provider) {
            $form[] = self::providerSection((int) $provider['slot'], $provider, $t);
        }

        $form[] = Section::make($t('section_limits'))
            ->description($t('section_limits_hint'))
            ->schema([
                Toggle::make('remote.gateway_enabled')->label($t('gateway_enabled')),
                Grid::make(2)->schema([
                    TextInput::make('remote.limits.short_window_hours')->label($t('short_window_hours'))->numeric()->minValue(1)->maxValue(168)->required(),
                    TextInput::make('remote.limits.short_tokens')->label($t('short_tokens'))->numeric()->minValue(1000)->required(),
                    TextInput::make('remote.limits.long_window_days')->label($t('long_window_days'))->numeric()->minValue(1)->maxValue(31)->required(),
                    TextInput::make('remote.limits.long_tokens')->label($t('long_tokens'))->numeric()->minValue(1000)->required(),
                    TextInput::make('remote.limits.discord_unlinked_percent')->label($t('discord_unlinked_percent'))->helperText($t('discord_unlinked_percent_hint'))->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('remote.limits.premium_multiplier')->label($t('premium_multiplier'))->helperText($t('premium_multiplier_hint'))->numeric()->minValue(1)->maxValue(10)->default(2)->required(),
                    TextInput::make('remote.failover.default_cooldown')->label($t('default_cooldown'))->numeric()->minValue(1)->maxValue(3600)->required(),
                    TextInput::make('remote.failover.deadline')->label($t('deadline'))->numeric()->minValue(10)->maxValue(95)->required(),
                    TextInput::make('remote.failover.max_concurrent')->label($t('max_concurrent'))->numeric()->minValue(1)->maxValue(50)->required(),
                ]),
            ]);

        return $form;
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $local = [
            'NEO_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
            'NEO_MAX_STEPS' => (string) max(1, min(30, (int) ($data['max_steps'] ?? 10))),
            'NEO_CONTEXT_TOKENS' => (string) max(4000, min(200000, (int) ($data['context_tokens'] ?? 16000))),
            'NEO_REDACT_SECRETS' => !empty($data['redact_secrets']) ? 'true' : 'false',
            'NEO_ALWAYS_CONFIRM_DESTRUCTIVE' => !empty($data['always_confirm_destructive']) ? 'true' : 'false',
            'NEO_RETENTION_DAYS' => (string) max(1, min(365, (int) ($data['retention_days'] ?? 30))),
        ];

        $supportUrl = trim((string) ($data['support_url'] ?? ''));
        if ($supportUrl === '' || filter_var($supportUrl, FILTER_VALIDATE_URL) !== false) {
            $local['NEO_SUPPORT_URL'] = $supportUrl;
        }

        // The two Discord bots' tabs, only when the form sent them (an absent tab must not
        // switch a bot off).
        foreach (['neo_bot' => NeoBotSettings::class, 'rux' => RuxSettings::class] as $tab => $settings) {
            if (is_array($data[$tab] ?? null)) {
                $local += $settings::env($data[$tab]);
            }
        }

        $this->writeToEnvironment($local);

        if (!is_array($data['remote'] ?? null) || !self::remoteConfig()['ok']) {
            Notification::make()->title(trans('plugged-host-ai::strings.settings.saved'))->success()->send();

            return;
        }

        $error = self::saveRemote($data['remote']);
        Cache::forget(self::REMOTE_CACHE);

        $error === null
            ? Notification::make()->title(trans('plugged-host-ai::strings.settings.saved'))->success()->send()
            : Notification::make()->title(trans('plugged-host-ai::strings.settings.remote_failed', ['error' => $error]))->danger()->persistent()->send();
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private static function providerSection(int $slot, array $provider, \Closure $t): Section
    {
        $prefix = "remote.providers.{$slot}.";
        $cooldown = !empty($provider['cooldown_until'])
            ? $t('cooling_down', ['time' => (string) $provider['cooldown_until'], 'error' => (string) ($provider['last_error']['reason'] ?? '?')])
            : '';

        return Section::make($t('section_provider', ['slot' => $slot]) . ' · ' . $provider['label'])
            ->description($t('section_provider_hint') . ' ' . $t('provider_state', ['tokens' => number_format((int) ($provider['tokens_today'] ?? 0)), 'cooldown' => $cooldown]))
            ->collapsible()
            ->collapsed($slot !== 1)
            ->schema([
                Grid::make(2)->schema([
                    Toggle::make($prefix . 'enabled')->label($t('provider_enabled')),
                    TextInput::make($prefix . 'label')->label($t('label'))->maxLength(64)->required(),
                    Select::make($prefix . 'driver')->label($t('driver'))->options([
                        'openai' => $t('driver_openai'),
                        'anthropic' => $t('driver_anthropic'),
                        'gemini' => $t('driver_gemini'),
                    ])->required()->native(false),
                    TextInput::make($prefix . 'model')->label($t('model'))->maxLength(128)->required(),
                    TextInput::make($prefix . 'endpoint')->label($t('endpoint'))->helperText($t('endpoint_hint'))->url()->maxLength(512)->required()->columnSpanFull(),
                    TextInput::make($prefix . 'api_key')
                        ->label($t('api_key'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->placeholder(!empty($provider['api_key_set']) ? '•••••••• ' . ($provider['api_key_hint'] ?? '') . ' · ' . $t('secret_set') : $t('secret_unset')),
                    Toggle::make($prefix . 'clear_api_key')->label($t('clear_api_key'))->visible(!empty($provider['api_key_set'])),
                    TextInput::make($prefix . 'max_tokens')->label($t('max_tokens'))->numeric()->minValue(256)->maxValue(128000)->required(),
                    TextInput::make($prefix . 'timeout')->label($t('timeout'))->numeric()->minValue(5)->maxValue(90)->required(),
                    TextInput::make($prefix . 'daily_token_cap')->label($t('daily_token_cap'))->helperText($t('daily_token_cap_hint'))->numeric()->minValue(0)->required(),
                    Toggle::make($prefix . 'vision')->label($t('vision'))->helperText($t('vision_hint')),
                    Toggle::make($prefix . 'premium')->label($t('premium'))->helperText($t('premium_hint')),
                    Textarea::make($prefix . 'extra_headers')->label($t('extra_headers'))->helperText($t('extra_headers_hint'))->rows(3)->rule(self::jsonObjectRule($t))->columnSpanFull(),
                    Textarea::make($prefix . 'extra_body')->label($t('extra_body'))->helperText($t('extra_body_hint'))->rows(3)->rule(self::jsonObjectRule($t))->columnSpanFull(),
                ]),
                Actions::make([
                    Action::make("test_provider_{$slot}")
                        ->label($t('test', ['slot' => $slot]))
                        ->color('gray')
                        ->action(fn () => self::testProvider($slot)),
                ]),
            ]);
    }

    private static function testProvider(int $slot): void
    {
        $t = fn (string $key, array $replace = []): string => trans('plugged-host-ai::strings.settings.' . $key, $replace);
        $actor = auth()->user();

        if (!$actor instanceof User) {
            return;
        }

        try {
            $result = app(NeoSettingsStore::class)->testProvider($slot, [], $actor);
        } catch (GatewayUnavailableException $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!empty($result['ok'])) {
            Notification::make()->success()->title($t('test_ok', [
                'slot' => $slot,
                'ms' => (int) ($result['ms'] ?? 0),
                'model' => (string) ($result['model'] ?? '?'),
                'tools' => !empty($result['tool_call_ok']) ? $t('yes') : $t('no'),
            ]))->send();

            return;
        }

        $error = (string) ($result['error'] ?? 'unknown');
        if (is_array($result['details'] ?? null)) {
            $error .= ': ' . implode('; ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($result['details']), $result['details']));
        }

        Notification::make()->danger()->persistent()->title($t('test_failed', ['slot' => $slot, 'error' => $error]))->send();
    }

    /**
     * Send the provider and limit part of the form to the NeoSettingsStore. Returns an error
     * message or null.
     *
     * @param  array<string, mixed>  $remote
     */
    private static function saveRemote(array $remote): ?string
    {
        $actor = auth()->user();
        if (!$actor instanceof User) {
            return 'no signed-in admin';
        }

        $providers = [];
        foreach ((array) ($remote['providers'] ?? []) as $slot => $fields) {
            $provider = [
                'enabled' => !empty($fields['enabled']),
                'label' => (string) ($fields['label'] ?? ''),
                'driver' => (string) ($fields['driver'] ?? 'openai'),
                'endpoint' => trim((string) ($fields['endpoint'] ?? '')),
                'model' => trim((string) ($fields['model'] ?? '')),
                'max_tokens' => (int) ($fields['max_tokens'] ?? 4096),
                'timeout' => (int) ($fields['timeout'] ?? 20),
                'daily_token_cap' => (int) ($fields['daily_token_cap'] ?? 0),
                'vision' => !empty($fields['vision']),
                'premium' => !empty($fields['premium']),
                'extra_headers' => self::decodeObject((string) ($fields['extra_headers'] ?? '{}')),
                'extra_body' => self::decodeObject((string) ($fields['extra_body'] ?? '{}')),
            ];

            $key = trim((string) ($fields['api_key'] ?? ''));
            if (!empty($fields['clear_api_key'])) {
                $provider['clear_api_key'] = true;
            } elseif ($key !== '') {
                $provider['api_key'] = $key;
            }

            $providers[(string) $slot] = $provider;
        }

        try {
            $result = app(NeoSettingsStore::class)->saveConfig([
                'gateway_enabled' => !empty($remote['gateway_enabled']),
                'limits' => array_map('intval', (array) ($remote['limits'] ?? [])),
                'failover' => array_map('intval', (array) ($remote['failover'] ?? [])),
                'providers' => $providers,
            ], $actor);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        if (!isset($result['error'])) {
            return null;
        }

        $details = is_array($result['details'] ?? null)
            ? ' (' . implode('; ', array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($result['details']), $result['details'])) . ')'
            : '';

        return (string) $result['error'] . $details;
    }

    /**
     * The NeoSettingsStore's view of the AI settings (this panel's, or noprofit.space's),
     * memoised briefly: upstream builds a fresh plugin object for every Livewire round-trip of
     * the settings form.
     *
     * @return array{ok: bool, data: array<string, mixed>, error: ?string}
     */
    private static function remoteConfig(): array
    {
        $cached = Cache::get(self::REMOTE_CACHE);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = ['ok' => true, 'data' => app(NeoSettingsStore::class)->config(), 'error' => null];
            Cache::put(self::REMOTE_CACHE, $result, 30);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
            Cache::put(self::REMOTE_CACHE, $result, 10);
        }

        return $result;
    }

    /**
     * Filament evaluates a closure given to rule() with its own dependency injection, so the
     * Laravel closure rule is returned from one instead of being passed directly.
     */
    private static function jsonObjectRule(\Closure $t): \Closure
    {
        return static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($t): void {
            if (trim((string) $value) === '') {
                return;
            }

            $decoded = json_decode((string) $value);
            if (!$decoded instanceof stdClass) {
                $fail($t('invalid_json'));
            }
        };
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private static function decodeObject(string $json): array|stdClass
    {
        $decoded = json_decode(trim($json) === '' ? '{}' : $json, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : new stdClass;
    }

    private static function prettyJson(mixed $value): string
    {
        $value = (array) $value;

        return $value === [] ? '{}' : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
