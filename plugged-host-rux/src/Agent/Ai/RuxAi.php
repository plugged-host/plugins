<?php

namespace PluggedHost\Rux\Agent\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * rux's AI: neo's provider chain, 1:1 (main, backup, extra backup: Groq Qwen, Groq gpt-oss,
 * justwoker), called directly with rux's own copy of neo's provider settings (RUX_AI_P{n}_*).
 * Nothing goes through noprofit.space or neo and no neo limits apply.
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
    private const RESERVED_HEADERS = ['authorization', 'x-api-key', 'content-type', 'content-length', 'host', 'accept'];

    private const RESERVED_BODY = ['model', 'messages', 'system', 'tools', 'tool_choice', 'stream', 'max_tokens', 'max_completion_tokens', 'n', 'thinking'];

    public function __construct(
        private readonly OpenAiTranslator $openAi,
        private readonly AnthropicTranslator $anthropic,
    ) {}

    /**
     * @return list<array<string, mixed>> The usable providers, in failover order.
     */
    public function providers(): array
    {
        return array_values(array_filter(
            (array) config('plugged-host-rux.ai.providers', []),
            static fn ($p): bool => is_array($p) && ($p['enabled'] ?? false) && ($p['endpoint'] ?? '') !== '' && ($p['api_key'] ?? '') !== '' && ($p['model'] ?? '') !== '',
        ));
    }

    public function configured(): bool
    {
        return $this->providers() !== [];
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

        $failover = (array) config('plugged-host-rux.ai.failover', []);
        $deadline = microtime(true) + (int) ($failover['deadline'] ?? 80);
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
        $anthropic = $provider['driver'] === 'anthropic';
        $model = (string) $provider['model'];
        $maxTokens = max(256, (int) $provider['max_tokens']);

        $body = array_merge(
            array_diff_key((array) ($provider['extra_body'] ?? []), array_flip(self::RESERVED_BODY)),
            $anthropic
                ? $this->anthropic->toBody($messages, $tools, $toolChoice, $model, $maxTokens)
                : $this->openAi->toBody($messages, $tools, $toolChoice, $model, $maxTokens),
        );

        $extraHeaders = array_filter(
            (array) ($provider['extra_headers'] ?? []),
            static fn ($value, $name): bool => is_string($name) && !in_array(strtolower($name), self::RESERVED_HEADERS, true),
            ARRAY_FILTER_USE_BOTH,
        );

        $headers = $anthropic
            ? ['x-api-key' => $provider['api_key'], 'anthropic-version' => (string) ($provider['extra_headers']['anthropic-version'] ?? '2023-06-01')]
            : ['Authorization' => 'Bearer ' . $provider['api_key']];

        $default = (int) config('plugged-host-rux.ai.failover.default_cooldown', 60);

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

        $answer = $anthropic ? $this->anthropic->fromResponse($response->body()) : $this->openAi->fromResponse($response->body());

        return $answer ?? ['status' => $response->status(), 'reason' => 'malformed_response', 'cooldown' => $default];
    }

    /**
     * noprofit.space's cooldown rules (ProviderException::fromResponse).
     */
    private function cooldownFor(Response $response): int
    {
        $failover = (array) config('plugged-host-rux.ai.failover', []);
        $status = $response->status();
        $retryAfter = $response->header('Retry-After');
        $retryAfter = is_numeric($retryAfter) ? (int) ceil((float) $retryAfter) : null;

        $cooldown = match (true) {
            in_array($status, [401, 403, 404], true) => (int) ($failover['auth_cooldown'] ?? 600),
            in_array($status, [400, 413, 422], true) => 0,
            in_array($status, [408, 409, 425, 429, 498, 529], true), $status >= 500 => $retryAfter ?? (int) ($failover['default_cooldown'] ?? 60),
            default => (int) ($failover['default_cooldown'] ?? 60),
        };

        return max(0, min($cooldown, (int) ($failover['max_cooldown'] ?? 900)));
    }

    private function detail(Response $response): string
    {
        $said = $response->json('error.message') ?? $response->json('message') ?? $response->json('error');

        return 'http_' . $response->status() . (is_string($said) && $said !== '' ? ': ' . mb_substr($said, 0, 200) : '');
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
        $suffix = $provider['driver'] === 'anthropic' ? '/messages' : '/chat/completions';
        $url = rtrim((string) $provider['endpoint'], '/');

        return str_ends_with($url, $suffix) ? $url : $url . $suffix;
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
