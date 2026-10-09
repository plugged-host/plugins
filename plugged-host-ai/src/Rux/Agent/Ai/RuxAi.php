<?php

namespace PluggedHost\Ai\Rux\Agent\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PluggedHost\Ai\Support\MessageContent;
use stdClass;
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

    /**
     * The extra fields a Gemini request takes besides generationConfig; anything else (a field of
     * the format a slot was switched from, like reasoning_effort) is left out, as on noprofit.space.
     */
    private const GEMINI_EXTRA_FIELDS = ['safetySettings', 'safety_settings', 'cachedContent', 'cached_content'];

    /**
     * Google answers many requests (free-tier Gemma especially) with a passing 500 or 503; a
     * Gemini request is tried this many times in all while 10 seconds of its timeout are left,
     * as on noprofit.space (GeminiDriver).
     */
    private const GEMINI_ATTEMPTS = 3;

    /**
     * Some gateways turn a share of calls away with a bare 403 before their API sees them
     * (justwoker: about one in four); such a call is sent this many times in all, as on
     * noprofit.space (SendsProviderRequests).
     */
    private const BLANK_REJECTION_ATTEMPTS = 3;

    /** How long a Gemini thought signature is kept for the next turn. */
    private const SIGNATURE_TTL = 172800;

    /** How long rux trusts what it found out about a provider passing tools on (passesTools). */
    private const TOOLS_PROBE_TTL = 21600;

    public function __construct(
        private readonly OpenAiTranslator $openAi,
        private readonly AnthropicTranslator $anthropic,
        private readonly GeminiTranslator $gemini,
        private readonly NeoProviders $neo,
        private readonly PromptedTools $prompted,
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
     * neo's vision flag for the slot; from before noprofit sent one, Gemini and Anthropic slots
     * count as seeing images and OpenAI-style ones not (noprofit's default too).
     *
     * @param  array<string, mixed>  $provider
     */
    public static function seesImages(array $provider): bool
    {
        return isset($provider['vision'])
            ? (bool) $provider['vision']
            : in_array($provider['driver'] ?? 'openai', ['gemini', 'anthropic'], true);
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
        $images = MessageContent::hasImages($messages);

        // With images, the slots that see them go first; the others get a note in their place.
        if ($images) {
            usort($providers, static fn (array $a, array $b): int => self::seesImages($b) <=> self::seesImages($a));
        }

        foreach ($providers as $provider) {
            $slotMessages = $images && !self::seesImages($provider) ? MessageContent::withoutImages($messages) : $messages;

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
            $prompted = $tools !== [] && !$this->passesTools($provider, $remaining);

            if ($prompted && ($remaining = (int) floor($deadline - microtime(true))) < 5) {
                $transient = true;

                break;
            }

            $outcome = $prompted
                ? $this->call($provider, $this->prompted->messages($slotMessages, $tools, $toolChoice, $this->gatewayTools($provider)), [], $toolChoice, min(max(5, (int) $provider['timeout']), $remaining))
                : $this->call($provider, $slotMessages, $tools, $toolChoice, min(max(5, (int) $provider['timeout']), $remaining));

            if (isset($outcome['message'])) {
                $this->countTokens($provider, $outcome);

                if ($prompted) {
                    $outcome = $this->prompted->answer($outcome, $tools, $toolChoice);
                }

                return $outcome + [
                    'provider' => ['slot' => $provider['slot'], 'label' => $provider['label'], 'model' => $provider['model'], 'prompted_tools' => $prompted],
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
            $driver === 'gemini'
                ? array_intersect_key($extraBody, array_flip(self::GEMINI_EXTRA_FIELDS))
                : array_diff_key($extraBody, array_flip(self::RESERVED_BODY)),
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

        $deadline = microtime(true) + $timeout;

        for ($attempt = 1; ; $attempt++) {
            $left = max(1, (int) floor($deadline - microtime(true)));

            try {
                $response = Http::withHeaders(array_merge($extraHeaders, $headers))
                    ->acceptJson()
                    ->connectTimeout(min(5, $left))
                    ->timeout($left)
                    ->withOptions(['allow_redirects' => false])
                    ->post($this->requestUrl($provider), $body);
            } catch (ConnectionException $e) {
                return ['status' => null, 'reason' => 'connection: ' . $e->getMessage(), 'cooldown' => $default];
            } catch (Throwable $e) {
                return ['status' => null, 'reason' => 'transport: ' . class_basename($e), 'cooldown' => $default];
            }

            if ($this->isBlankRejection($response)) {
                if ($attempt >= self::BLANK_REJECTION_ATTEMPTS || $deadline - microtime(true) < 2) {
                    break;
                }

                Sleep::for(250)->milliseconds();

                continue;
            }

            if ($driver !== 'gemini' || !in_array($response->status(), [500, 503], true)
                || $attempt >= self::GEMINI_ATTEMPTS || $deadline - microtime(true) < 11) {
                break;
            }

            Sleep::for(1)->second();
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
     * Whether a provider hands the request's own tools to the model. Some gateways drop them and
     * put in their own (justwoker since 2026-10-03, which even runs them itself); for those rux
     * describes its tools in the prompt instead (PromptedTools), and names the gateway's tools so
     * the model leaves them alone. Found out with a tiny call, then kept for 6 hours.
     *
     * @param  array<string, mixed>  $provider
     */
    private function passesTools(array $provider, int $remaining): bool
    {
        $key = $this->key($provider, 'passes-tools');
        $known = Cache::get($key);

        if (is_bool($known)) {
            return $known;
        }

        $probe = [
            'type' => 'function',
            'function' => [
                'name' => 'rux_probe',
                'description' => 'Confirms that tools work. Call it when asked to.',
                'parameters' => ['type' => 'object', 'properties' => new stdClass, 'required' => []],
            ],
        ];

        $outcome = $this->call($provider, [
            ['role' => 'system', 'content' => 'You are a test. Answer by calling the rux_probe tool, nothing else. If you have no tool called rux_probe, call no tool and reply with only the exact names of the tools you do have, comma separated.'],
            ['role' => 'user', 'content' => 'Call rux_probe now.'],
        ], [$probe], 'auto', max(5, min(30, (int) $provider['timeout'], $remaining - 5)));

        if (!isset($outcome['message'])) {
            // Cannot tell: send the tools as usual, and the real call reports the failure.
            return true;
        }

        $this->countTokens($provider, $outcome);

        $passes = collect((array) ($outcome['message']['tool_calls'] ?? []))->contains(static fn ($call): bool => ($call['function']['name'] ?? null) === 'rux_probe');
        Cache::put($key, $passes, self::TOOLS_PROBE_TTL);

        if (!$passes) {
            $named = preg_split('/[,\n]+/', (string) ($outcome['message']['content'] ?? '')) ?: [];
            $gatewayTools = array_values(array_unique(array_filter(
                array_map(static fn (string $name): string => trim($name, " \t.`*-"), $named),
                static fn (string $name): bool => $name !== 'rux_probe' && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{1,63}$/', $name) === 1 && !ctype_digit($name),
            )));
            Cache::put($this->key($provider, 'gateway-tools'), array_slice($gatewayTools, 0, 20), self::TOOLS_PROBE_TTL);

            Log::info('[rux] AI provider drops the tools of a request: rux describes them in the prompt', ['slot' => $provider['slot'], 'label' => $provider['label'], 'its_own_tools' => $gatewayTools]);
        }

        return $passes;
    }

    /**
     * The tools a gateway puts in instead of rux's, as its model named them in the probe.
     *
     * @param  array<string, mixed>  $provider
     * @return list<string>
     */
    private function gatewayTools(array $provider): array
    {
        return array_values(array_filter((array) Cache::get($this->key($provider, 'gateway-tools'), []), 'is_string'));
    }

    /**
     * Add a call's tokens to the provider's count for today (its daily_token_cap).
     *
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $outcome
     */
    private function countTokens(array $provider, array $outcome): void
    {
        $tokens = (int) ($outcome['usage']['prompt_tokens'] ?? 0) + (int) ($outcome['usage']['completion_tokens'] ?? 0);
        $key = $this->key($provider, 'tokens:' . gmdate('Y-m-d'));
        Cache::add($key, 0, 172800);
        Cache::increment($key, max(0, $tokens));
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
            $this->isBlankRejection($response) => $failover['default_cooldown'],
            in_array($status, [401, 403, 404], true) => $failover['auth_cooldown'],
            in_array($status, [400, 413, 422], true) => 0,
            in_array($status, [408, 409, 425, 429, 498, 529], true), $status >= 500 => $retryAfter ?? $failover['default_cooldown'],
            default => $failover['default_cooldown'],
        };

        return max(0, min($cooldown, $failover['max_cooldown']));
    }

    /**
     * A 403 without a body comes from a proxy in front of the provider's API, not from the API
     * judging the key, so it does not earn the auth cooldown.
     */
    private function isBlankRejection(Response $response): bool
    {
        return $response->status() === 403 && trim($response->body()) === '';
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
        return 'plugged-host-ai:rux:ai:gemini-signature:' . $toolCallId;
    }

    /**
     * Per provider, and reset when its endpoint, model or key change.
     *
     * @param  array<string, mixed>  $provider
     */
    private function key(array $provider, string $what): string
    {
        return 'plugged-host-ai:rux:ai:' . substr(sha1($provider['endpoint'] . '|' . $provider['model'] . '|' . sha1((string) $provider['api_key'])), 0, 16) . ':' . $what;
    }
}
