<?php

namespace PluggedHost\Ai\Services;

use App\Models\User;
use App\Traits\EnvironmentWriterTrait;
use Illuminate\Support\Facades\Log;
use PluggedHost\Ai\Chat\ConfiguredProviders;
use PluggedHost\Ai\Chat\EndpointGuard;
use PluggedHost\Ai\Chat\EndpointNotAllowedException;
use PluggedHost\Ai\Chat\ProviderChain;
use PluggedHost\Ai\Contracts\NeoSettingsStore;
use PluggedHost\Ai\Contracts\ProviderSource;
use stdClass;
use Throwable;

/**
 * neo's provider slots and limits in this panel's .env (plugged-host-ai.local), for the settings
 * form: noprofit.space's EnvNeoSettingsStore, NeoConfigPresenter and provider test, ported, so
 * the form gets and sends the same JSON either way.
 *
 * Only an allowlist of NEO_* keys can ever be written, and every value is validated so it can
 * neither break out of its .env line nor point a provider somewhere it must not go (EndpointGuard).
 * API keys are never shown, only whether one is set and its last four characters.
 */
final class EnvNeoSettingsStore implements NeoSettingsStore
{
    use EnvironmentWriterTrait;

    public const MASK = '••••';

    /**
     * @var list<string>
     */
    private const RESERVED_HEADERS = ['authorization', 'x-api-key', 'x-goog-api-key', 'content-type', 'content-length', 'host', 'accept', 'cookie'];

    /**
     * Lower case: compared against lower-cased keys. The Gemini ones (contents, systemInstruction,
     * toolConfig) are reserved for every driver; generationConfig is not, the Gemini call merges it.
     *
     * @var list<string>
     */
    private const RESERVED_BODY = ['model', 'messages', 'system', 'tools', 'tool_choice', 'stream', 'max_tokens', 'max_completion_tokens', 'n', 'thinking', 'contents', 'systeminstruction', 'system_instruction', 'toolconfig', 'tool_config'];

    private const CONFIG = 'plugged-host-ai.local';

    /**
     * Form field => [env key, config path under plugged-host-ai.local, min, max].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    private const INTEGERS = [
        'limits.short_window_hours' => ['NEO_SHORT_WINDOW_HOURS', 'limits.short.hours', 1, 168],
        'limits.short_tokens' => ['NEO_SHORT_TOKENS', 'limits.short.tokens', 1000, 10_000_000_000],
        'limits.long_window_days' => ['NEO_LONG_WINDOW_DAYS', 'limits.long.days', 1, 31],
        'limits.long_tokens' => ['NEO_LONG_TOKENS', 'limits.long.tokens', 1000, 100_000_000_000],
        'limits.discord_unlinked_percent' => ['NEO_DISCORD_UNLINKED_PERCENT', 'limits.discord_unlinked_percent', 0, 100],
        'limits.premium_multiplier' => ['NEO_PREMIUM_MULTIPLIER', 'limits.premium_multiplier', 1, 10],
        'failover.default_cooldown' => ['NEO_DEFAULT_COOLDOWN', 'failover.default_cooldown', 1, 3600],
        'failover.deadline' => ['NEO_CHAIN_DEADLINE', 'failover.deadline', 10, 95],
        'failover.max_concurrent' => ['NEO_MAX_CONCURRENT', 'failover.max_concurrent', 1, 50],
    ];

    private readonly ProviderChain $chain;

    public function __construct(
        private readonly EndpointGuard $guard,
        private readonly ProviderSource $providers,
        private readonly NeoUsageMeter $meter,
        ProviderChain $chain,
    ) {
        $this->chain = $chain->for('neo');
    }

    public function label(): string
    {
        return (string) trans('plugged-host-ai::strings.settings.this_panel');
    }

    public function config(): array
    {
        return [
            'env_writable' => $this->isWritable(),
            'gateway_enabled' => (bool) config(self::CONFIG . '.enabled', true),
            'limits' => [
                'short_window_hours' => (int) config(self::CONFIG . '.limits.short.hours'),
                'short_tokens' => (int) config(self::CONFIG . '.limits.short.tokens'),
                'long_window_days' => (int) config(self::CONFIG . '.limits.long.days'),
                'long_tokens' => (int) config(self::CONFIG . '.limits.long.tokens'),
                'discord_unlinked_percent' => (int) config(self::CONFIG . '.limits.discord_unlinked_percent', 50),
                'premium_multiplier' => (int) config(self::CONFIG . '.limits.premium_multiplier', 2),
            ],
            'failover' => [
                'default_cooldown' => (int) config(self::CONFIG . '.failover.default_cooldown'),
                'deadline' => (int) config(self::CONFIG . '.failover.deadline'),
                'max_concurrent' => (int) config(self::CONFIG . '.failover.max_concurrent'),
            ],
            'providers' => array_values(array_map(fn (array $provider): array => $this->present($provider), $this->slots())),
        ];
    }

    public function saveConfig(array $settings, User $actor): array
    {
        [$env, $config, $errors] = $this->collect($settings);

        if ($errors !== []) {
            return ['error' => 'invalid_settings', 'details' => $errors];
        }

        if ($env === []) {
            return $this->config() + ['changed' => []];
        }

        if (!$this->isWritable()) {
            return ['error' => 'env_not_writable'];
        }

        $this->writeToEnvironment($env);

        // This request keeps the config it booted with; what the form shows next is the new one.
        foreach ($config as $path => $value) {
            config()->set(self::CONFIG . '.' . $path, $value);
        }

        Log::info('[neo] AI settings changed', ['actor_id' => $actor->id, 'actor' => $actor->username, 'changed' => array_keys($env)]);

        return $this->config() + ['changed' => array_keys($env)];
    }

    public function testProvider(int $slot, array $overrides, User $actor): array
    {
        $provider = $this->slots()[$slot] ?? null;

        if ($provider === null) {
            return ['error' => 'unknown_slot'];
        }

        [, $config, $errors] = $this->collect(['providers' => [$slot => $overrides]]);

        if ($errors !== []) {
            return ['error' => 'invalid_settings', 'details' => $errors];
        }

        $prefix = "providers.{$slot}.";
        $changes = [];
        foreach ($config as $path => $value) {
            $changes[substr($path, strlen($prefix))] = $value;
        }

        $provider = ConfiguredProviders::provider($slot, array_merge($provider, ['enabled' => true], $changes));

        Log::info('[neo] AI provider test', ['actor_id' => $actor->id, 'actor' => $actor->username, 'slot' => $slot]);

        if (!ProviderChain::usable($provider)) {
            return ['ok' => false, 'error' => 'missing_endpoint_model_or_key'];
        }

        try {
            $this->guard->pin((string) $provider['endpoint']);
        } catch (EndpointNotAllowedException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $result = $this->chain->probe($provider, [
            ['role' => 'system', 'content' => 'This is a connectivity check. Answer by calling the ping tool.'],
            ['role' => 'user', 'content' => 'Call the ping tool with ok set to true.'],
        ], [[
            'type' => 'function',
            'function' => [
                'name' => 'ping',
                'description' => 'Connectivity check.',
                'parameters' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']],
            ],
        ]]);

        if (!isset($result['message'])) {
            return ['ok' => false, 'status' => $result['status'] ?? null, 'error' => (string) ($result['reason'] ?? 'failed')];
        }

        $prompt = (int) ($result['usage']['prompt_tokens'] ?? 0);
        $completion = (int) ($result['usage']['completion_tokens'] ?? 0);

        try {
            if ($this->meter->ready()) {
                $this->meter->recordFailure(null, [
                    'status' => 'test',
                    'provider_slot' => $slot,
                    'provider_label' => mb_substr((string) $provider['label'], 0, 64),
                    'driver' => (string) $provider['driver'],
                    'model' => mb_substr((string) $provider['model'], 0, 128),
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => $completion,
                    'finish_reason' => mb_substr((string) ($result['finish_reason'] ?? ''), 0, 24),
                    'duration_ms' => (int) ($result['duration_ms'] ?? 0),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return [
            'ok' => true,
            'status' => 200,
            'ms' => (int) ($result['duration_ms'] ?? 0),
            'model' => $provider['model'],
            'finish_reason' => $result['finish_reason'] ?? null,
            'tool_call_ok' => collect((array) ($result['message']['tool_calls'] ?? []))->contains(static fn ($call): bool => is_array($call) && ($call['function']['name'] ?? null) === 'ping'),
            'content_preview' => mb_substr((string) ($result['message']['content'] ?? ''), 0, 200),
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion],
        ];
    }

    private function isWritable(): bool
    {
        $path = base_path('.env');

        return is_file($path) && is_writable($path);
    }

    /**
     * The configured slots, by slot number.
     *
     * @return array<int, array<string, mixed>>
     */
    private function slots(): array
    {
        $slots = [];
        foreach ($this->providers->providers() as $provider) {
            $slots[(int) $provider['slot']] = $provider;
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function present(array $provider): array
    {
        $key = (string) ($provider['api_key'] ?? '');
        $cooldownUntil = $this->chain->coolingDownUntil($provider);

        return [
            'slot' => (int) $provider['slot'],
            'enabled' => (bool) $provider['enabled'],
            'label' => (string) $provider['label'],
            'driver' => (string) $provider['driver'],
            'endpoint' => (string) $provider['endpoint'],
            'api_key_set' => $key !== '',
            'api_key_hint' => strlen($key) >= 12 ? '…' . substr($key, -4) : ($key !== '' ? '…' : null),
            'model' => (string) $provider['model'],
            'max_tokens' => (int) $provider['max_tokens'],
            'timeout' => (int) $provider['timeout'],
            'daily_token_cap' => (int) $provider['daily_token_cap'],
            'extra_headers' => (object) $this->maskHeaders((array) $provider['extra_headers']),
            'extra_body' => (object) $provider['extra_body'],
            'premium' => !empty($provider['premium']),
            'vision' => ProviderChain::seesImages($provider),
            'usable' => ProviderChain::usable($provider),
            'cooldown_until' => $cooldownUntil === null ? null : gmdate('Y-m-d\TH:i:s\Z', $cooldownUntil),
            'last_error' => $this->chain->lastError($provider),
            'tokens_today' => $this->chain->tokensToday($provider),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (preg_match('/key|token|auth|secret|cookie|session/i', (string) $name) === 1) {
                $headers[$name] = self::MASK;
            }
        }

        return $headers;
    }

    /**
     * Validate the form's values into .env lines and config values (paths under
     * plugged-host-ai.local), with an error per field that is not valid.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, string>, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function collect(array $input): array
    {
        $env = [];
        $config = [];
        $errors = [];

        if (array_key_exists('gateway_enabled', $input)) {
            $enabled = filter_var($input['gateway_enabled'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) {
                $errors['gateway_enabled'] = 'must be a boolean';
            } else {
                $env['NEO_GATEWAY_ENABLED'] = $enabled ? 'true' : 'false';
                $config['enabled'] = $enabled;
            }
        }

        foreach (self::INTEGERS as $field => [$envKey, $configPath, $min, $max]) {
            $value = data_get($input, $field);
            if ($value === null) {
                continue;
            }

            if (!is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
                $errors[$field] = "must be an integer between {$min} and {$max}";

                continue;
            }

            $env[$envKey] = (string) (int) $value;
            $config[$configPath] = (int) $value;
        }

        $slots = $this->slots();

        foreach ((array) ($input['providers'] ?? []) as $slot => $fields) {
            $slot = (int) $slot;
            if (!is_array($fields) || !isset($slots[$slot])) {
                $errors["providers.{$slot}"] = 'unknown provider slot';

                continue;
            }

            $this->collectProvider($slot, $slots[$slot], $fields, $env, $config, $errors);
        }

        return [$env, $config, $errors];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $fields
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $errors
     */
    private function collectProvider(int $slot, array $current, array $fields, array &$env, array &$config, array &$errors): void
    {
        $envPrefix = "NEO_P{$slot}_";
        $configPrefix = "providers.{$slot}.";
        $errorPrefix = "providers.{$slot}.";

        $set = static function (string $field, string $envValue, mixed $configValue, ?string $envField = null) use (&$env, &$config, $envPrefix, $configPrefix): void {
            $env[$envPrefix . strtoupper($envField ?? $field)] = $envValue;
            $config[$configPrefix . $field] = $configValue;
        };

        foreach (['enabled', 'premium', 'vision'] as $flag) {
            if (!array_key_exists($flag, $fields)) {
                continue;
            }

            $value = filter_var($fields[$flag], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $value === null
                ? $errors[$errorPrefix . $flag] = 'must be a boolean'
                : $set($flag, $value ? 'true' : 'false', $value);
        }

        if (array_key_exists('label', $fields)) {
            $label = trim((string) $fields['label']);
            preg_match('/^[\pL\pN ._()\-]{1,64}$/u', $label) === 1
                ? $set('label', $label, $label)
                : $errors[$errorPrefix . 'label'] = 'letters, digits, spaces and ._()- only (max 64)';
        }

        if (array_key_exists('driver', $fields)) {
            in_array($fields['driver'], ['openai', 'anthropic', 'gemini'], true)
                ? $set('driver', $fields['driver'], $fields['driver'])
                : $errors[$errorPrefix . 'driver'] = 'must be openai, anthropic or gemini';
        }

        if (array_key_exists('endpoint', $fields)) {
            $endpoint = trim((string) $fields['endpoint']);

            try {
                if (!$this->isSafePlainValue($endpoint) || strlen($endpoint) > 512) {
                    throw new EndpointNotAllowedException('endpoint_invalid');
                }

                $this->guard->pin($endpoint);
                $set('endpoint', $endpoint, $endpoint);
            } catch (EndpointNotAllowedException $e) {
                $errors[$errorPrefix . 'endpoint'] = $e->getMessage();
            }
        }

        if (!empty($fields['clear_api_key'])) {
            $set('api_key', '', '');
        } elseif (is_string($fields['api_key'] ?? null) && trim($fields['api_key']) !== '') {
            $key = trim($fields['api_key']);
            preg_match('/^[\x21-\x7E]{8,512}$/', $key) === 1 && $this->isSafePlainValue($key)
                ? $set('api_key', $key, $key)
                : $errors[$errorPrefix . 'api_key'] = 'must be 8-512 printable characters without quotes, $, # or backslashes';
        }

        if (array_key_exists('model', $fields)) {
            $model = trim((string) $fields['model']);
            preg_match('/^[A-Za-z0-9._:\/-]{1,128}$/', $model) === 1
                ? $set('model', $model, $model)
                : $errors[$errorPrefix . 'model'] = 'invalid model id';
        }

        foreach (['max_tokens' => [256, 128000], 'timeout' => [5, 90], 'daily_token_cap' => [0, 10_000_000_000]] as $field => [$min, $max]) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }

            $value = $fields[$field];
            is_numeric($value) && (int) $value == $value && (int) $value >= $min && (int) $value <= $max
                ? $set($field, (string) (int) $value, (int) $value)
                : $errors[$errorPrefix . $field] = "must be an integer between {$min} and {$max}";
        }

        // JSON values go to the .env base64-encoded (NEO_P{n}_EXTRA_*_B64).
        if (array_key_exists('extra_headers', $fields)) {
            $headers = $this->validHeaders((array) ($current['extra_headers'] ?? []), $fields['extra_headers']);
            $headers === null
                ? $errors[$errorPrefix . 'extra_headers'] = 'must be a JSON object of header names to string values (max 2 KB, no auth/host headers)'
                : $set('extra_headers', base64_encode((string) json_encode((object) $headers)), $headers, 'extra_headers_b64');
        }

        if (array_key_exists('extra_body', $fields)) {
            $body = $this->validBody($fields['extra_body']);
            $body === null
                ? $errors[$errorPrefix . 'extra_body'] = 'must be a JSON object (max 2 KB, depth 3, no reserved keys)'
                : $set('extra_body', base64_encode((string) json_encode((object) $body)), $body, 'extra_body_b64');
        }
    }

    /**
     * @param  array<string, string>  $existing
     * @return array<string, string>|null
     */
    private function validHeaders(array $existing, mixed $headers): ?array
    {
        if ($headers instanceof stdClass) {
            $headers = (array) $headers;
        }

        if (!is_array($headers) || array_is_list($headers) && $headers !== []) {
            return null;
        }

        $clean = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) !== 1
                || in_array(strtolower($name), self::RESERVED_HEADERS, true)
                || !is_scalar($value) || preg_match('/[\r\n]/', (string) $value) === 1) {
                return null;
            }

            // A masked value coming back from the form means "unchanged".
            $clean[$name] = (string) $value === self::MASK && isset($existing[$name]) ? (string) $existing[$name] : (string) $value;
        }

        return strlen((string) json_encode($clean)) <= 2048 ? $clean : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validBody(mixed $body): ?array
    {
        if ($body instanceof stdClass) {
            $body = json_decode((string) json_encode($body), true);
        }

        if (!is_array($body) || array_is_list($body) && $body !== []) {
            return null;
        }

        if (array_intersect(array_map('strtolower', array_map('strval', array_keys($body))), self::RESERVED_BODY) !== []) {
            return null;
        }

        return strlen((string) json_encode($body)) <= 2048 && $this->depth($body) <= 3 ? $body : null;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function depth(array $value): int
    {
        $max = 1;
        foreach ($value as $item) {
            if (is_array($item)) {
                $max = max($max, 1 + $this->depth($item));
            }
        }

        return $max;
    }

    /**
     * Values written verbatim into .env must not be able to break out of their line or quoting.
     */
    private function isSafePlainValue(string $value): bool
    {
        return preg_match('/["\'\\\\$#\r\n\x00]/', $value) !== 1;
    }
}
