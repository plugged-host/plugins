<?php

namespace PluggedHost\Neo;

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
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Neo\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Neo\Services\NeoGateway;
use stdClass;
use Throwable;

/**
 * neo's Filament registration (no pages of its own; the chat and its settings are wired by
 * NeoPluginProvider) and its settings tab.
 *
 * The settings tab edits two places at once:
 *   - panel-side values (master switch, gateway secret, loop budget, privacy switches) go to
 *     this panel's .env through EnvironmentWriterTrait, like every plugged.host plugin;
 *   - provider slots and usage limits live on noprofit.space and are read and written over the
 *     signed gateway API, so they never have to be hand-edited in noprofit.space's .env.
 *
 * Secrets follow the "masked, save-if-changed" convention (see PluggedHostMinecraftPlugin):
 * never loaded into the form, written only when a new value is typed. Upstream swallows any
 * exception from these methods (App\Models\Plugin), so every outcome is reported through a
 * Notification instead.
 */
class PluggedHostNeoPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    private const REMOTE_CACHE = 'plugged-host-neo:remote-config';

    public function getId(): string
    {
        return 'plugged-host-neo';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    /**
     * @return array<string, mixed>
     */
    public function getSettingsFormData(): array
    {
        $data = [
            'enabled' => (bool) config('plugged-host-neo.enabled', false),
            'gateway_secret' => '',
            'max_steps' => (int) config('plugged-host-neo.max_steps', 10),
            'context_tokens' => (int) config('plugged-host-neo.context_tokens', 16000),
            'redact_secrets' => (bool) config('plugged-host-neo.redact_secrets', true),
            'always_confirm_destructive' => (bool) config('plugged-host-neo.always_confirm_destructive', true),
            'retention_days' => (int) config('plugged-host-neo.retention_days', 30),
            'support_url' => (string) config('plugged-host-neo.support_url', ''),
        ];

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
        $t = fn (string $key, array $replace = []): string => trans('plugged-host-neo::strings.settings.' . $key, $replace);
        $hasSecret = strlen((string) config('plugged-host-neo.gateway_secret')) >= 32;
        $remote = self::remoteConfig();

        $form = [
            Section::make($t('section_panel'))
                ->description($t('section_panel_hint'))
                ->schema([
                    Toggle::make('enabled')->label($t('enabled'))->helperText($t('enabled_hint')),
                    TextInput::make('gateway_secret')
                        ->label($t('gateway_secret'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->minLength(32)
                        ->placeholder($hasSecret ? '•••••••• ' . $t('secret_set') : $t('secret_unset'))
                        ->helperText($t('gateway_secret_hint')),
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
                    !$remote['ok'] => $t('unreachable', ['reason' => $remote['error']]),
                    !($remote['data']['env_writable'] ?? false) => $t('env_not_writable'),
                    default => $t('connected'),
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

        $secret = trim((string) ($data['gateway_secret'] ?? ''));
        if (strlen($secret) >= 32 && preg_match('/^[\x21-\x7E]+$/', $secret) === 1) {
            $local['NEO_GATEWAY_SECRET'] = $secret;
        }

        $this->writeToEnvironment($local);

        // writeToEnvironment() cleared the config cache, but this request still holds the old
        // values; the remote save below must already sign with a newly typed secret.
        if (isset($local['NEO_GATEWAY_SECRET'])) {
            config()->set('plugged-host-neo.gateway_secret', $local['NEO_GATEWAY_SECRET']);
        }

        if (!is_array($data['remote'] ?? null) || !self::remoteConfig()['ok']) {
            Notification::make()->title(trans('plugged-host-neo::strings.settings.saved'))->success()->send();

            return;
        }

        $error = self::saveRemote($data['remote']);
        Cache::forget(self::REMOTE_CACHE);

        $error === null
            ? Notification::make()->title(trans('plugged-host-neo::strings.settings.saved'))->success()->send()
            : Notification::make()->title(trans('plugged-host-neo::strings.settings.remote_failed', ['error' => $error]))->danger()->persistent()->send();
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
        $t = fn (string $key, array $replace = []): string => trans('plugged-host-neo::strings.settings.' . $key, $replace);
        $actor = auth()->user();

        if (!$actor instanceof User) {
            return;
        }

        try {
            $result = app(NeoGateway::class)->testProvider($slot, [], $actor);
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
     * Send the remote part of the form to noprofit.space. Returns an error message or null.
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
            $result = app(NeoGateway::class)->saveConfig([
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
     * noprofit.space's view of the AI settings, memoised briefly: upstream builds a fresh
     * plugin object for every Livewire round-trip of the settings form.
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
            $result = ['ok' => true, 'data' => app(NeoGateway::class)->config(), 'error' => null];
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
