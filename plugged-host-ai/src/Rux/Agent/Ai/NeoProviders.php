<?php

namespace PluggedHost\Ai\Rux\Agent\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Services\NoprofitClient;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use Throwable;

/**
 * neo's AI providers, fetched from noprofit.space (GET /api/v1/internal/neo/providers, signed
 * with neo's gateway secret) so rux always runs the chain neo runs: whatever is changed in neo's
 * settings reaches rux within a minute, with nothing to copy.
 *
 * A fetch is reused for `refresh_seconds` by every rux process. The last good one is kept,
 * encrypted with the app key, next to the supervisor's files, so a noprofit.space outage (or
 * optimize:clear) leaves rux on the chain it last saw instead of without one.
 */
class NeoProviders
{
    private const PATH = '/api/v1/internal/neo/providers';

    /** @var array{fetched_at: int, providers: list<array<string, mixed>>, failover: array<string, int>}|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly NoprofitClient $client,
        private readonly RuxSupervisor $supervisor,
    ) {}

    /**
     * Whether rux can ask noprofit.space for the chain at all (no network involved).
     */
    public function configured(): bool
    {
        return $this->client->configured($this->secret());
    }

    /**
     * Every provider slot, usable or not, in neo's order, premium ones included (flag `premium`):
     * RuxAi decides what may use them.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(): array
    {
        return array_values(array_filter($this->current()['providers'] ?? [], 'is_array'));
    }

    /**
     * neo's failover timings, with rux's defaults for any it did not send.
     *
     * @return array{default_cooldown: int, auth_cooldown: int, max_cooldown: int, deadline: int}
     */
    public function failover(): array
    {
        return array_merge(
            ['default_cooldown' => 60, 'auth_cooldown' => 600, 'max_cooldown' => 900, 'deadline' => 80],
            array_map('intval', $this->current()['failover'] ?? []),
        );
    }

    /**
     * When the chain in use was fetched (unix time), or null when rux has never had one.
     */
    public function fetchedAt(): ?int
    {
        $fetchedAt = $this->current()['fetched_at'] ?? null;

        return is_int($fetchedAt) ? $fetchedAt : null;
    }

    /**
     * Fetch now, whatever the age of the copy in hand.
     *
     * @throws AiUnavailableException When noprofit.space does not answer with a chain.
     */
    public function refresh(): void
    {
        $this->loaded = $this->fetch();
        $this->store($this->loaded);
    }

    /**
     * @return array{fetched_at: int, providers: list<array<string, mixed>>, failover: array<string, int>}|null
     */
    private function current(): ?array
    {
        $fresh = time() - (int) config('plugged-host-ai.rux.ai.refresh_seconds', 60);

        if ($this->loaded !== null && $this->loaded['fetched_at'] > $fresh) {
            return $this->loaded;
        }

        $stored = $this->load();

        if ($stored !== null && $stored['fetched_at'] > $fresh) {
            return $this->loaded = $stored;
        }

        try {
            $this->refresh();
        } catch (AiUnavailableException $e) {
            if (Cache::add('plugged-host-ai:rux:ai:neo-fetch-warned', true, 600)) {
                Log::warning('[rux] could not fetch neo\'s AI providers; using the last ones fetched', [
                    'reason' => $e->getMessage(),
                    'last_fetched_at' => $stored['fetched_at'] ?? null,
                ]);
            }

            $this->loaded = $stored;
        }

        return $this->loaded;
    }

    /**
     * @return array{fetched_at: int, providers: list<array<string, mixed>>, failover: array<string, int>}
     *
     * @throws AiUnavailableException
     */
    private function fetch(): array
    {
        if (!$this->configured()) {
            throw new AiUnavailableException('neo_gateway_not_configured');
        }

        try {
            $response = $this->client->send('GET', self::PATH, null, $this->secret(), 5);
        } catch (Throwable $e) {
            throw new AiUnavailableException('noprofit_unreachable: ' . class_basename($e));
        }

        $providers = $response->json('providers');

        if (!$response->successful() || !is_array($providers)) {
            throw new AiUnavailableException('noprofit_http_' . $response->status());
        }

        return [
            'fetched_at' => time(),
            'providers' => array_values(array_filter($providers, 'is_array')),
            'failover' => array_map('intval', array_filter((array) $response->json('failover', []), 'is_numeric')),
        ];
    }

    /**
     * @return array{fetched_at: int, providers: list<array<string, mixed>>, failover: array<string, int>}|null
     */
    private function load(): ?array
    {
        $raw = @file_get_contents($this->path());

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (Throwable) {
            return null; // Written under another APP_KEY.
        }

        return is_array($data) && is_int($data['fetched_at'] ?? null) && is_array($data['providers'] ?? null) ? $data : null;
    }

    /**
     * @param  array{fetched_at: int, providers: list<array<string, mixed>>, failover: array<string, int>}  $data
     */
    private function store(array $data): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, Crypt::encryptString((string) json_encode($data))) === false) {
            return;
        }

        @chmod($temporary, 0600);
        @rename($temporary, $path);
    }

    private function path(): string
    {
        return $this->supervisor->runtimeDirectory() . '/neo-providers.enc';
    }

    private function secret(): string
    {
        return (string) config('plugged-host-ai.rux.ai.gateway_secret', '');
    }
}
