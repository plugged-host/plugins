<?php

namespace PluggedHost\Ai;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\User;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Field;
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
use Filament\Schemas\Components\Utilities\Get;
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
 * When the store reports fields as `managed` (noprofit.space: which API and model each slot is, and
 * the failover timings, set on its Admin > AI models), the form shows them read-only with a link
 * there and never sends them; what neo offers (which models, to whom, limits, the default model)
 * stays editable here. rux's tab is read-only the same way once noprofit.space keeps rux's
 * settings (RuxSettings::managed()), and saving writes none of rux's settings here then.
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
            'max_steps' => (int) config('plugged-host-ai.max_steps', 25),
            'context_tokens' => (int) config('plugged-host-ai.context_tokens', 24000),
            'redact_secrets' => (bool) config('plugged-host-ai.redact_secrets', true),
            'always_confirm_destructive' => (bool) config('plugged-host-ai.always_confirm_destructive', true),
            'retention_days' => (int) config('plugged-host-ai.retention_days', 30),
            'support_url' => (string) config('plugged-host-ai.support_url', ''),
            'legal_email' => (string) config('plugged-host-ai.legal_email', ''),
            'privacy_email' => (string) config('plugged-host-ai.privacy_email', ''),
            'provider_disclosure' => (string) config('plugged-host-ai.provider_disclosure', ''),
            'log_check_enabled' => (bool) config('plugged-host-ai.log_check.enabled', true),
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
                'description' => (string) ($provider['description'] ?? ''),
                'access' => in_array($provider['access'] ?? null, ['everyone', 'plus', 'plusplus'], true) ? $provider['access'] : (!empty($provider['premium']) ? 'plusplus' : 'everyone'),
                'limited' => (bool) ($provider['limited'] ?? !empty($provider['premium'])),
                'weekly_tokens' => (int) ($provider['weekly_tokens'] ?? 0),
                'multiplier' => (int) ($provider['multiplier'] ?? 1),
                'thinking' => (bool) ($provider['thinking'] ?? true),
                'extra_headers' => self::prettyJson($provider['extra_headers'] ?? []),
                'extra_body' => self::prettyJson($provider['extra_body'] ?? []),
            ];

            // Whether neo offers it in its picker, for stores that have the flag.
            if (array_key_exists('offered', $provider)) {
                $providers[(string) $provider['slot']]['offered'] = (bool) $provider['offered'];
            }
        }

        $data['remote'] = [
            'gateway_enabled' => (bool) ($config['gateway_enabled'] ?? true),
            // The saved mode wins; 'all' only for a store that does not have modes.
            'limits' => (array) ($config['limits'] ?? []) + ['mode' => 'all'],
            'default_model' => (string) (int) ($config['default_model'] ?? 0),
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
        $managed = self::managed($remote);

        $form = [
            Section::make($t('section_panel'))
                ->description($t('section_panel_hint'))
                ->schema([
                    Toggle::make('enabled')->label($t('enabled'))->helperText($t('enabled_hint')),
                    Grid::make(2)->schema([
                        TextInput::make('max_steps')->label($t('max_steps'))->helperText($t('max_steps_hint'))->numeric()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('context_tokens')->label($t('context_tokens'))->helperText($t('context_tokens_hint'))->numeric()->minValue(4000)->maxValue(200000)->required(),
                        TextInput::make('retention_days')->label($t('retention_days'))->numeric()->minValue(1)->maxValue(365)->required(),
                        TextInput::make('support_url')->label($t('support_url'))->helperText($t('support_url_hint'))->url()->maxLength(255),
                        TextInput::make('legal_email')->label($t('legal_email'))->helperText($t('legal_email_hint'))->email()->maxLength(255),
                        TextInput::make('privacy_email')->label($t('privacy_email'))->helperText($t('privacy_email_hint'))->email()->maxLength(255),
                    ]),
                    TextInput::make('provider_disclosure')->label($t('provider_disclosure'))->helperText($t('provider_disclosure_hint'))->maxLength(255),
                    Toggle::make('redact_secrets')->label($t('redact_secrets'))->helperText($t('redact_secrets_hint')),
                    Toggle::make('always_confirm_destructive')->label($t('always_confirm_destructive'))->helperText($t('always_confirm_destructive_hint')),
                    Toggle::make('log_check_enabled')->label($t('log_check_enabled'))->helperText($t('log_check_enabled_hint')),
                ]),
            Section::make($t('section_status'))->schema(array_values(array_filter([
                Text::make(match (true) {
                    !$remote['ok'] => $t('unreachable', $where + ['reason' => $remote['error']]),
                    !($remote['data']['env_writable'] ?? false) => $t('env_not_writable', $where),
                    default => $t('connected', $where),
                }),
                $managed === null ? null : Text::make($t('managed_here', ['where' => $managed['by']])),
                $managed === null ? null : Actions::make([
                    Action::make('neo_models_elsewhere')
                        ->label($t('managed_open', ['where' => $managed['by']]))
                        ->url($managed['url'], shouldOpenInNewTab: true)
                        ->link(),
                ]),
            ]))),
        ];

        if (!$remote['ok']) {
            return $form;
        }

        $models = ['0' => $t('default_model_auto')];

        foreach ((array) ($remote['data']['providers'] ?? []) as $provider) {
            $form[] = self::providerSection((int) $provider['slot'], $provider, $t, $managed['providers'] ?? []);
            $models[(string) $provider['slot']] = $t('section_provider', ['slot' => $provider['slot']]) . ' · ' . $provider['label'];
        }

        // The failover timings, when they are set where the providers are.
        $failover = static fn (Field $field): Field => ($managed['failover'] ?? false) ? $field->disabled()->validatedWhenNotDehydrated(false) : $field;

        $form[] = Section::make($t('section_limits'))
            ->description($t('section_limits_hint'))
            ->schema([
                Toggle::make('remote.gateway_enabled')->label($t('gateway_enabled')),
                Grid::make(2)->schema([
                    // Where to switch every model back to the usage limits (limits mode all).
                    Select::make('remote.limits.mode')->label($t('limits_mode'))->helperText($t('limits_mode_hint'))->options([
                        'models' => $t('limits_mode_models'),
                        'all' => $t('limits_mode_all'),
                    ])->required()->native(false),
                    Select::make('remote.default_model')->label($t('default_model'))->helperText($t('default_model_hint'))->options($models)->required()->native(false),
                    TextInput::make('remote.limits.plusplus_percent')->label($t('plusplus_percent'))->helperText($t('plusplus_percent_hint'))->numeric()->minValue(100)->maxValue(1000)->default(150)->required(),
                    TextInput::make('remote.limits.discord_unlinked_percent')->label($t('discord_unlinked_percent'))->helperText($t('discord_unlinked_percent_hint'))->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make('remote.limits.short_window_hours')->label($t('short_window_hours'))->helperText($t('all_mode_only'))->numeric()->minValue(1)->maxValue(168)->required(),
                    TextInput::make('remote.limits.short_tokens')->label($t('short_tokens'))->helperText($t('all_mode_only'))->numeric()->minValue(1000)->required(),
                    TextInput::make('remote.limits.long_window_days')->label($t('long_window_days'))->helperText($t('long_window_days_hint'))->numeric()->minValue(1)->maxValue(31)->required(),
                    TextInput::make('remote.limits.long_tokens')->label($t('long_tokens'))->helperText($t('all_mode_only'))->numeric()->minValue(1000)->required(),
                    $failover(TextInput::make('remote.failover.default_cooldown')->label($t('default_cooldown'))->numeric()->minValue(1)->maxValue(3600)->required()),
                    $failover(TextInput::make('remote.failover.deadline')->label($t('deadline'))->helperText($t('deadline_hint'))->numeric()->minValue(10)->maxValue(270)->required()),
                    $failover(TextInput::make('remote.failover.max_concurrent')->label($t('max_concurrent'))->numeric()->minValue(1)->maxValue(50)->required()),
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
            'NEO_MAX_STEPS' => (string) max(1, min(30, (int) ($data['max_steps'] ?? 25))),
            'NEO_CONTEXT_TOKENS' => (string) max(4000, min(200000, (int) ($data['context_tokens'] ?? 24000))),
            'NEO_REDACT_SECRETS' => !empty($data['redact_secrets']) ? 'true' : 'false',
            'NEO_ALWAYS_CONFIRM_DESTRUCTIVE' => !empty($data['always_confirm_destructive']) ? 'true' : 'false',
            'NEO_RETENTION_DAYS' => (string) max(1, min(365, (int) ($data['retention_days'] ?? 30))),
            'NEO_LOG_CHECK_ENABLED' => !empty($data['log_check_enabled']) ? 'true' : 'false',
            // Free text, kept on one line (the .env writer quotes it).
            'NEO_PROVIDER_DISCLOSURE' => trim((string) preg_replace('/[\r\n\0]+/', ' ', mb_substr((string) ($data['provider_disclosure'] ?? ''), 0, 255))),
        ];

        $supportUrl = trim((string) ($data['support_url'] ?? ''));
        if ($supportUrl === '' || filter_var($supportUrl, FILTER_VALIDATE_URL) !== false) {
            $local['NEO_SUPPORT_URL'] = $supportUrl;
        }

        foreach (['legal_email' => 'NEO_LEGAL_EMAIL', 'privacy_email' => 'NEO_PRIVACY_EMAIL'] as $field => $env) {
            $email = trim((string) ($data[$field] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $local[$env] = $email;
            }
        }

        // The two Discord bots' tabs, only when the form sent them (an absent tab must not
        // switch a bot off). rux's, never while its settings are kept elsewhere (its tab only
        // shows them then, and an empty form must not overwrite this panel's own).
        foreach (['neo_bot' => NeoBotSettings::class, 'rux' => RuxSettings::class] as $tab => $settings) {
            if ($tab === 'rux' && RuxSettings::managed() !== null) {
                continue;
            }

            if (is_array($data[$tab] ?? null)) {
                $local += $settings::env($data[$tab]);
            }
        }

        $this->writeToEnvironment($local);

        if (!is_array($data['remote'] ?? null) || !self::remoteConfig()['ok']) {
            Notification::make()->title(trans('plugged-host-ai::strings.settings.saved'))->success()->send();

            return;
        }

        $error = self::saveRemote($data['remote'], self::managed(self::remoteConfig()));
        Cache::forget(self::REMOTE_CACHE);

        $error === null
            ? Notification::make()->title(trans('plugged-host-ai::strings.settings.saved'))->success()->send()
            : Notification::make()->title(trans('plugged-host-ai::strings.settings.remote_failed', ['error' => $error]))->danger()->persistent()->send();
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  list<string>  $locked  Fields the store sets itself (managed): shown, never sent.
     */
    private static function providerSection(int $slot, array $provider, \Closure $t, array $locked = []): Section
    {
        $prefix = "remote.providers.{$slot}.";
        // A slot that is switched off may stay empty: its fields are only needed once it is on.
        $enabled = static fn (Get $get): bool => (bool) $get($prefix . 'enabled');
        $isLocked = static fn (string $field): bool => in_array($field, $locked, true);
        $lock = static fn (Field $field, string $name): Field => $isLocked($name) ? $field->disabled()->validatedWhenNotDehydrated(false) : $field;
        $cooldown = !empty($provider['cooldown_until'])
            ? $t('cooling_down', ['time' => (string) $provider['cooldown_until'], 'error' => (string) ($provider['last_error']['reason'] ?? '?')])
            : '';

        return Section::make($t('section_provider', ['slot' => $slot]) . ' · ' . $provider['label'])
            ->description($t('section_provider_hint') . ' ' . $t('provider_state', ['tokens' => number_format((int) ($provider['tokens_today'] ?? 0)), 'cooldown' => $cooldown]))
            ->collapsible()
            ->collapsed($slot !== 1)
            ->schema([
                Grid::make(2)->schema([
                    $lock(Toggle::make($prefix . 'enabled')->label($t('provider_enabled')), 'enabled'),
                    ...(array_key_exists('offered', $provider) ? [Toggle::make($prefix . 'offered')->label($t('offered'))->helperText($t('offered_hint'))] : []),
                    $lock(TextInput::make($prefix . 'label')->label($t('label'))->helperText($t('label_hint'))->maxLength(64)->required($enabled), 'label'),
                    TextInput::make($prefix . 'description')->label($t('description'))->helperText($t('description_hint'))->maxLength(120),
                    Select::make($prefix . 'access')->label($t('access'))->options([
                        'everyone' => $t('access_everyone'),
                        'plus' => $t('access_plus'),
                        'plusplus' => $t('access_plusplus'),
                    ])->required($enabled)->native(false),
                    $lock(Select::make($prefix . 'driver')->label($t('driver'))->options([
                        'openai' => $t('driver_openai'),
                        'anthropic' => $t('driver_anthropic'),
                        'gemini' => $t('driver_gemini'),
                    ])->required($enabled)->native(false), 'driver'),
                    $lock(TextInput::make($prefix . 'model')->label($t('model'))->maxLength(128)->required($enabled), 'model'),
                    $lock(TextInput::make($prefix . 'endpoint')->label($t('endpoint'))->helperText($t('endpoint_hint'))->url()->maxLength(512)->required($enabled)->columnSpanFull(), 'endpoint'),
                    $lock(TextInput::make($prefix . 'api_key')
                        ->label($t('api_key'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->placeholder(!empty($provider['api_key_set']) ? '•••••••• ' . ($provider['api_key_hint'] ?? '') . ($isLocked('api_key') ? '' : ' · ' . $t('secret_set')) : $t('secret_unset')), 'api_key'),
                    Toggle::make($prefix . 'clear_api_key')->label($t('clear_api_key'))->visible(!empty($provider['api_key_set']) && !$isLocked('api_key') && !$isLocked('clear_api_key')),
                    $lock(TextInput::make($prefix . 'max_tokens')->label($t('max_tokens'))->numeric()->minValue(256)->maxValue(128000)->required($enabled), 'max_tokens'),
                    $lock(TextInput::make($prefix . 'timeout')->label($t('timeout'))->helperText($t('timeout_hint'))->numeric()->minValue(5)->maxValue(270)->required($enabled), 'timeout'),
                    $lock(TextInput::make($prefix . 'daily_token_cap')->label($t('daily_token_cap'))->helperText($t('daily_token_cap_hint'))->numeric()->minValue(0)->required($enabled), 'daily_token_cap'),
                    $lock(Toggle::make($prefix . 'vision')->label($t('vision'))->helperText($t('vision_hint')), 'vision'),
                    $lock(Toggle::make($prefix . 'thinking')->label($t('thinking'))->helperText($t('thinking_hint')), 'thinking'),
                    Toggle::make($prefix . 'limited')->label($t('limited'))->helperText($t('limited_hint')),
                    TextInput::make($prefix . 'weekly_tokens')->label($t('weekly_tokens'))->helperText($t('weekly_tokens_hint'))->numeric()->minValue(0)->required($enabled),
                    TextInput::make($prefix . 'multiplier')->label($t('multiplier'))->helperText($t('multiplier_hint'))->numeric()->minValue(1)->maxValue(10)->required($enabled),
                    $lock(Textarea::make($prefix . 'extra_headers')->label($t('extra_headers'))->helperText($t('extra_headers_hint'))->rows(3)->rule(self::jsonObjectRule($t))->columnSpanFull(), 'extra_headers'),
                    $lock(Textarea::make($prefix . 'extra_body')->label($t('extra_body'))->helperText($t('extra_body_hint'))->rows(3)->rule(self::jsonObjectRule($t))->columnSpanFull(), 'extra_body'),
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
     * message or null. Fields the store manages itself ($managed) are left out.
     *
     * @param  array<string, mixed>  $remote
     * @param  array{by: string, url: string, providers: list<string>, failover: bool}|null  $managed
     */
    private static function saveRemote(array $remote, ?array $managed = null): ?string
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
                'timeout' => (int) ($fields['timeout'] ?? 30),
                'daily_token_cap' => (int) ($fields['daily_token_cap'] ?? 0),
                'vision' => !empty($fields['vision']),
                'description' => trim((string) ($fields['description'] ?? '')),
                'access' => (string) ($fields['access'] ?? 'everyone'),
                'limited' => !empty($fields['limited']),
                'weekly_tokens' => (int) ($fields['weekly_tokens'] ?? 0),
                'multiplier' => (int) ($fields['multiplier'] ?? 1),
                'thinking' => !empty($fields['thinking']),
                'extra_headers' => self::decodeObject((string) ($fields['extra_headers'] ?? '{}')),
                'extra_body' => self::decodeObject((string) ($fields['extra_body'] ?? '{}')),
            ];

            // Only stores that have the flag show it.
            if (array_key_exists('offered', $fields)) {
                $provider['offered'] = !empty($fields['offered']);
            }

            // An empty slot keeps its name, model and endpoint as they are (a slot that is off may be empty).
            foreach (['label', 'model', 'endpoint'] as $field) {
                if ($provider[$field] === '') {
                    unset($provider[$field]);
                }
            }

            $key = trim((string) ($fields['api_key'] ?? ''));
            if (!empty($fields['clear_api_key'])) {
                $provider['clear_api_key'] = true;
            } elseif ($key !== '') {
                $provider['api_key'] = $key;
            }

            // What the store sets itself is never sent (the form does not even have it then).
            $providers[(string) $slot] = array_diff_key($provider, array_flip($managed['providers'] ?? []));
        }

        if (!in_array('enabled', $managed['providers'] ?? [], true)
            && !collect($providers)->contains(static fn (array $provider): bool => !empty($provider['enabled']))) {
            return trans('plugged-host-ai::strings.settings.one_model_required');
        }

        try {
            $result = app(NeoSettingsStore::class)->saveConfig([
                'gateway_enabled' => !empty($remote['gateway_enabled']),
                'limits' => ['mode' => ($remote['limits']['mode'] ?? 'all') === 'models' ? 'models' : 'all']
                    + array_map('intval', array_diff_key((array) ($remote['limits'] ?? []), ['mode' => true, 'premium_multiplier' => true])),
                'default_model' => (int) ($remote['default_model'] ?? 0),
                'providers' => $providers,
            ] + (($managed['failover'] ?? false) ? [] : ['failover' => array_map('intval', (array) ($remote['failover'] ?? []))]), $actor);
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
     * What the store sets itself and this form only shows (GET /neo/config's `managed`):
     * the provider fields, whether the failover timings are among them, and where they are edited.
     * Null when the form edits everything (this panel's own store, or an older account system).
     *
     * @param  array{ok: bool, data: array<string, mixed>, error: ?string}  $remote
     * @return array{by: string, url: string, providers: list<string>, failover: bool}|null
     */
    private static function managed(array $remote): ?array
    {
        $managed = $remote['ok'] ? ($remote['data']['managed'] ?? null) : null;
        $url = is_array($managed) && is_string($managed['url'] ?? null) ? $managed['url'] : '';

        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
            return null;
        }

        return [
            'by' => is_string($managed['by'] ?? null) && $managed['by'] !== '' ? $managed['by'] : (string) parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'providers' => array_values(array_filter((array) ($managed['providers'] ?? []), 'is_string')),
            'failover' => ($managed['failover'] ?? false) === true,
        ];
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
