<?php

namespace PluggedHost\Rux\Agent\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * rux's AI: neo's provider chain, 1:1, fetched from noprofit.space (NeoProviders) so it is
 * always the chain neo runs, and called directly: the model calls go nowhere near neo, and no
 * neo limits apply.
 *
 * The failover is noprofit.space's ProviderChain, copied: providers are tried in order; one that
 * fails cools down (auth errors 10 minutes, rate limits and outages for their Retry-After or a
 * minute, a request it found too large not at all) and is skipped meanwhile; a provider's daily
 * token cap is kept per day. When all fail, AiBusyException says how long until one is back,
 * unless none can be (AiUnavailableException).
 */
class RuxAi
{
    /** Headers a slot's extra headers may not override. */
    private const RESERVED_HEADERS = ['authorization', 'x-api-key', 'x-goog-api-key', 'content-type', 'content-length', 'host', 'accept'];

    private const RESERVED_BODY = [
        'model', 'messages', 'system', 'tools', 'tool_choice', 'stream', 'max_tokens', 'max_completion_tokens', 'n', 'thinking',
        'contents', 'systemInstruction', 'system_instruction', 'toolConfig', 'tool_config', 'generationConfig', 'generation_config',
    ];

    /** How long a Gemini thought signature is kept for the next turn. */
    private const SIGNATURE_TTL = 172800;

    public function __construct(
        private readonly OpenAiTranslator $openAi,
        private readonly AnthropicTranslator $anthropic,
        private readonly GeminiTranslator $gemini,
        private readonly NeoProviders $neo,
    ) {}

    /**
     * @return list<array<string, mixed>> The usable providers, in failover order.
     */
    public function providers(): array
    {
        return array_values(array_filter(
            $this->neo->providers(),
            static fn ($p): bool => is_array($p) && ($p['enabled'] ?? false) && ($p['endpoint'] ?? '') !== '' && ($p['api_key'] ?? '') !== '' && ($p['model'] ?? '') !== '',
        ));
    }

    /**
     * Whether rux can get neo's providers at all. Whether neo has a usable one is only known
     * per call (AiUnavailableException).
     */
    public function configured(): bool
    {
        return $this->neo->configured();
    }

    /**
     * One model turn on the first provider that answers.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed> ['message' => ['content', 'tool_calls'], 'finish_reason', 'usage', 'provider']
     *
     * @throws AiBusyException|AiUnavailableException
     */
    public function turn(array $messages, array $tools = [], string $toolChoice = 'auto'): array
    {
        $providers = $this->providers();

        if ($providers === []) {
            throw new AiUnavailableException('not_configured');
        }

        $deadline = microtime(true) + $this->neo->failover()['deadline'];
        $transient = false;

        foreach ($providers as $provider) {
            if ($this->coolingDownUntil($provider) !== null) {
                $transient = true;

                continue;
            }

            $cap = (int) ($provider['daily_token_cap'] ?? 0);
            if ($cap > 0 && (int) Cache::get($this->key($provider, 'tokens:' . gmdate('Y-m-d')), 0) >= $cap) {
                continue;
            }

            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 5) {
                $transient = true;

                break;
            }

            $started = microtime(true);
            $outcome = $this->call($provider, $messages, $tools, $toolChoice, min(max(5, (int) $provider['timeout']), $remaining));

            if (isset($outcome['message'])) {
                $tokens = (int) ($outcome['usage']['prompt_tokens'] ?? 0) + (int) ($outcome['usage']['completion_tokens'] ?? 0);
                $key = $this->key($provider, 'tokens:' . gmdate('Y-m-d'));
                Cache::add($key, 0, 172800);
                Cache::increment($key, max(0, $tokens));

                return $outcome + [
                    'provider' => ['slot' => $provider['slot'], 'label' => $provider['label'], 'model' => $provider['model']],
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ];
            }

            $this->fail($provider, $outcome['status'], $outcome['reason'], $outcome['cooldown']);
            $transient = $transient || $outcome['cooldown'] > 0;
        }

        $soonest = $this->soonestBack($providers);

        if ($transient && $soonest !== null) {
            throw new AiBusyException('all_providers_failed', max(1, $soonest - time()));
        }

        throw new AiUnavailableException('all_providers_failed');
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed> The answer, or ['status', 'reason', 'cooldown'] when it failed.
     */
    private function call(array $provider, array $messages, array $tools, string $toolChoice, int $timeout): array
    {
        $driver = (string) $provider['driver'];
        $model = (string) $provider['model'];
        $maxTokens = max(256, (int) $provider['max_tokens']);
        $extraBody = (array) ($provider['extra_body'] ?? []);

        $body = array_merge(
            array_diff_key($extraBody, array_flip(self::RESERVED_BODY)),
            match ($driver) {
                'anthropic' => $this->anthropic->toBody($messages, $tools, $toolChoice, $model, $maxTokens),
                'gemini' => $this->gemini->toBody($messages, $tools, $toolChoice, $model, static fn (string $id): ?string => Cache::get(self::signatureKey($id))),
                default => $this->openAi->toBody($messages, $tools, $toolChoice, $model, $maxTokens),
            },
        );

        if ($driver === 'gemini') {
            // An operator's generationConfig (thinkingConfig…) is kept; only the output cap is ours.
            $body['generationConfig'] = ['maxOutputTokens' => $maxTokens] + (array) ($extraBody['generationConfig'] ?? $extraBody['generation_config'] ?? []);
        }

        $extraHeaders = array_filter(
            (array) ($provider['extra_headers'] ?? []),
            static fn ($value, $name): bool => is_string($name) && !in_array(strtolower($name), self::RESERVED_HEADERS, true),
            ARRAY_FILTER_USE_BOTH,
        );

        $headers = match ($driver) {
            'anthropic' => ['x-api-key' => $provider['api_key'], 'anthropic-version' => (string) ($provider['extra_headers']['anthropic-version'] ?? '2023-06-01')],
            'gemini' => ['x-goog-api-key' => $provider['api_key']],
            default => ['Authorization' => 'Bearer ' . $provider['api_key']],
        };

        $default = $this->neo->failover()['default_cooldown'];

        try {
            $response = Http::withHeaders(array_merge($extraHeaders, $headers))
                ->acceptJson()
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->post($this->requestUrl($provider), $body);
        } catch (ConnectionException $e) {
            return ['status' => null, 'reason' => 'connection: ' . $e->getMessage(), 'cooldown' => $default];
        } catch (Throwable $e) {
            return ['status' => null, 'reason' => 'transport: ' . class_basename($e), 'cooldown' => $default];
        }

        if (!$response->successful()) {
            return ['status' => $response->status(), 'reason' => $this->detail($response), 'cooldown' => $this->cooldownFor($response)];
        }

        $answer = match ($driver) {
            'anthropic' => $this->anthropic->fromResponse($response->body()),
            'gemini' => $this->gemini->fromResponse($response->body()),
            default => $this->openAi->fromResponse($response->body()),
        };

        if ($answer === null) {
            return ['status' => $response->status(), 'reason' => 'malformed_response', 'cooldown' => $default];
        }

        foreach ($answer['signatures'] ?? [] as $id => $signature) {
            Cache::put(self::signatureKey((string) $id), $signature, self::SIGNATURE_TTL);
        }

        unset($answer['signatures']);

        return $answer;
    }

    /**
     * noprofit.space's cooldown rules (ProviderException::fromResponse).
     */
    private function cooldownFor(Response $response): int
    {
        $failover = $this->neo->failover();
        $status = $response->status();
        $retryAfter = $response->header('Retry-After');
        $retryAfter = is_numeric($retryAfter) ? (int) ceil((float) $retryAfter) : $this->retryDelay($response);

        $cooldown = match (true) {
            in_array($status, [401, 403, 404], true) => $failover['auth_cooldown'],
            in_array($status, [400, 413, 422], true) => 0,
            in_array($status, [408, 409, 425, 429, 498, 529], true), $status >= 500 => $retryAfter ?? $failover['default_cooldown'],
            default => $failover['default_cooldown'],
        };

        return max(0, min($cooldown, $failover['max_cooldown']));
    }

    /**
     * Gemini says when to retry in the error body: a google.rpc.RetryInfo detail ("37s").
     */
    private function retryDelay(Response $response): ?int
    {
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && str_ends_with((string) ($detail['@type'] ?? ''), 'google.rpc.RetryInfo')
                && preg_match('/^(\d+(?:\.\d+)?)s$/', (string) ($detail['retryDelay'] ?? ''), $match) === 1) {
                return max(1, (int) ceil((float) $match[1]));
            }
        }

        return null;
    }

    private function detail(Response $response): string
    {
        $said = $response->json('error.message') ?? $response->json('message') ?? $response->json('error');
        $said = is_string($said) ? (string) preg_replace('/\bAIza[A-Za-z0-9_-]{20,}/', '[redacted]', $said) : null;

        return 'http_' . $response->status() . ($said !== null && $said !== '' ? ': ' . mb_substr($said, 0, 200) : '');
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function fail(array $provider, ?int $status, string $reason, int $cooldown): void
    {
        Log::warning('[rux] AI provider failed', ['slot' => $provider['slot'], 'label' => $provider['label'], 'status' => $status, 'reason' => $reason, 'cooldown' => $cooldown]);

        if ($cooldown > 0) {
            Cache::put($this->key($provider, 'cooldown'), time() + $cooldown, $cooldown);
        }
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function coolingDownUntil(array $provider): ?int
    {
        $until = Cache::get($this->key($provider, 'cooldown'));

        return is_int($until) && $until > time() ? $until : null;
    }

    /**
     * @param  list<array<string, mixed>>  $providers
     */
    private function soonestBack(array $providers): ?int
    {
        $times = array_filter(array_map(fn (array $p): ?int => $this->coolingDownUntil($p), $providers));

        return $times === [] ? time() + 2 : min($times);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function requestUrl(array $provider): string
    {
        if ($provider['driver'] === 'gemini') {
            return $this->gemini->requestUrl((string) $provider['endpoint'], (string) $provider['model']);
        }

        $suffix = $provider['driver'] === 'anthropic' ? '/messages' : '/chat/completions';
        $url = rtrim((string) $provider['endpoint'], '/');

        return str_ends_with($url, $suffix) ? $url : $url . $suffix;
    }

    private static function signatureKey(string $toolCallId): string
    {
        return 'plugged-host-rux:ai:gemini-signature:' . $toolCallId;
    }

    /**
     * Per provider, and reset when its endpoint, model or key change.
     *
     * @param  array<string, mixed>  $provider
     */
    private function key(array $provider, string $what): string
    {
        return 'plugged-host-rux:ai:' . substr(sha1($provider['endpoint'] . '|' . $provider['model'] . '|' . sha1((string) $provider['api_key'])), 0, 16) . ':' . $what;
    }
}
