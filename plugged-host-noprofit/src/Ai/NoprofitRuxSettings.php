<?php

namespace PluggedHost\Noprofit\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use PluggedHost\Ai\Contracts\RuxSettingsSource;
use PluggedHost\Ai\Rux\Support\EnvRuxSettingsSource;
use PluggedHost\Ai\Rux\Support\RuxGuild;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Noprofit\Services\NoprofitClient;
use RuntimeException;
use Throwable;

/**
 * rux's settings as noprofit.space keeps them (Admin > rux on accounts.noprofit.space), fetched
 * from GET /api/v1/internal/rux/settings, signed with the sync secret like rux's other calls.
 *
 * Until noprofit.space says `configured` (they were saved there once: by an admin, or by
 * plugged-host:rux-settings-upload moving this panel's own), this panel's own settings apply,
 * exactly as without this plugin (EnvRuxSettingsSource), and the rux tab edits them. Once it does,
 * noprofit.space's values are laid over this panel's config (overlay(); the few it does not keep,
 * like the gateway intents, stay this panel's) and the rux tab only shows them.
 *
 * guilds() and settings() are read for every message the gateway daemon sees and by every rux
 * process, so a fetch is reused for `rux_settings_refresh` seconds (60): in memory, and through an
 * encrypted copy (app key) next to the supervisor's files that every process on this box shares.
 * The copy is also what rux keeps running on while noprofit.space cannot be reached; a failed
 * fetch is not tried again for RETRY_AFTER seconds, so an outage never holds every message up on
 * the network. With no copy at all and noprofit.space down, this panel's own settings apply.
 */
class NoprofitRuxSettings implements RuxSettingsSource
{
    public const PATH = '/api/v1/internal/rux/settings';

    /** Seconds before a failed fetch is tried again. */
    public const RETRY_AFTER = 30;

    /** What noprofit.space keeps of rux's settings, besides the servers. */
    public const BOOLEANS = ['enabled', 'watch.guidelines', 'spam.enabled'];

    public const STRINGS = ['bot_token', 'guild_id', 'giveaway_channel_id', 'mod_log_channel_id', 'timezone', 'locale', 'warnings.timeout', 'warnings.ban'];

    public const INTEGERS = [
        'giveaway.max_credits', 'giveaway.max_winners', 'giveaway.max_days', 'credits.max_per_grant',
        'warnings.timeout_at', 'warnings.ban_at', 'rules.cooldown_seconds',
        'spam.flood_messages', 'spam.flood_seconds', 'spam.duplicates', 'spam.duplicate_seconds', 'spam.mentions', 'spam.emoji',
        'messages_per_minute', 'agent.max_steps', 'agent.history_minutes',
    ];

    public const LISTS = ['trusted_domains', 'credits.granters'];

    private const EMPTY_SERVER = ['id' => '', 'name' => '', 'about' => '', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => []];

    /** @var array{fetched_at: int, configured: bool, settings: ?array<string, mixed>, admin_url: ?string, saved_at: ?string, saved_by: ?string}|null */
    private ?array $loaded = null;

    private int $retryAt = 0;

    /** @var array{0: int, 1: list<RuxGuild>}|null The servers of the copy fetched at [0]. */
    private ?array $guilds = null;

    public function __construct(
        private readonly NoprofitClient $client,
        private readonly RuxSupervisor $supervisor,
    ) {}

    /**
     * Whether this panel can ask noprofit.space at all (no network involved).
     */
    public function configured(): bool
    {
        return $this->client->configured($this->secret());
    }

    public function guilds(): array
    {
        $current = $this->managedCopy();

        if ($current === null) {
            return EnvRuxSettingsSource::guildsFrom((array) config('plugged-host-ai.rux', []));
        }

        if ($this->guilds === null || $this->guilds[0] !== $current['fetched_at']) {
            $this->guilds = [$current['fetched_at'], EnvRuxSettingsSource::guildsFrom($this->settings())];
        }

        return $this->guilds[1];
    }

    public function settings(): array
    {
        $panel = (array) config('plugged-host-ai.rux', []);
        $current = $this->managedCopy();

        return $current === null ? $panel : self::overlay($panel, (array) $current['settings']);
    }

    public function managedBy(): ?array
    {
        $current = $this->managedCopy();

        if ($current === null) {
            return null;
        }

        return [
            'by' => $this->host(),
            'url' => $this->adminUrl($current['admin_url']),
            'fetched_at' => $current['fetched_at'],
            'saved_at' => $current['saved_at'],
            'saved_by' => $current['saved_by'],
        ];
    }

    /**
     * When the copy in use was fetched (unix time), or null when there is none.
     */
    public function fetchedAt(): ?int
    {
        return $this->current()['fetched_at'] ?? null;
    }

    /**
     * Fetch now, whatever the age of the copy in hand, and keep it.
     *
     * @return array{fetched_at: int, configured: bool, settings: ?array<string, mixed>, admin_url: ?string, saved_at: ?string, saved_by: ?string}
     *
     * @throws RuntimeException When noprofit.space does not answer with rux's settings.
     */
    public function refresh(): array
    {
        $this->loaded = $this->fetch();
        $this->store($this->loaded);
        $this->retryAt = 0;

        return $this->loaded;
    }

    /**
     * Move settings to noprofit.space (PUT, signed). noprofit.space takes them once: when it has
     * its own already it answers 409 unless $replace. The copy here is fetched again right after.
     *
     * @param  array<string, mixed>  $settings  noprofit.space's shape (forNoprofit()), bot_token included.
     * @param  array{id: string, name: string}  $actor
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws RuntimeException When noprofit.space cannot be reached or is not configured here.
     */
    public function upload(array $settings, array $actor, bool $replace = false): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('noprofit.space is not configured on this panel (its sync secret and URL).');
        }

        try {
            $response = $this->client->send('PUT', self::PATH, [
                'actor' => ['id' => mb_substr($actor['id'], 0, 64), 'name' => mb_substr($actor['name'], 0, 191)],
                'replace' => $replace,
                'settings' => $settings,
            ], $this->secret(), 15);
        } catch (Throwable $e) {
            throw new RuntimeException('noprofit.space could not be reached: ' . class_basename($e), 0, $e);
        }

        $body = $response->json();

        return ['status' => $response->status(), 'body' => is_array($body) ? $body : []];
    }

    /**
     * Settings of config('plugged-host-ai.rux')'s shape with noprofit.space's laid over them: every
     * key noprofit.space keeps replaces this panel's (lists whole; its servers, a list, fill slots 2
     * to 10 in order and empty the rest), every other key stays as it is.
     *
     * @param  array<string, mixed>  $panel
     * @param  array<string, mixed>  $remote  noprofit.space's settings (GET …/rux/settings › settings).
     * @return array<string, mixed>
     */
    public static function overlay(array $panel, array $remote): array
    {
        $rux = $panel;

        foreach (self::BOOLEANS as $key) {
            $value = data_get($remote, $key);
            if (is_bool($value)) {
                data_set($rux, $key, $value);
            }
        }

        foreach (self::STRINGS as $key) {
            $value = data_get($remote, $key);
            if (is_string($value) || is_int($value)) {
                data_set($rux, $key, trim((string) $value));
            }
        }

        foreach (self::INTEGERS as $key) {
            $value = data_get($remote, $key);
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                data_set($rux, $key, (int) $value);
            }
        }

        foreach (self::LISTS as $key) {
            $value = data_get($remote, $key);
            if (is_array($value)) {
                data_set($rux, $key, self::strings($value));
            }
        }

        if (is_array($remote['servers'] ?? null)) {
            $servers = array_fill_keys(EnvRuxSettingsSource::SLOTS, self::EMPTY_SERVER);
            $slots = EnvRuxSettingsSource::SLOTS;

            foreach (array_values(array_filter($remote['servers'], 'is_array')) as $index => $server) {
                if (!isset($slots[$index])) {
                    break;
                }

                $servers[$slots[$index]] = [
                    'id' => trim((string) (is_scalar($server['id'] ?? null) ? $server['id'] : '')),
                    'name' => trim((string) (is_scalar($server['name'] ?? null) ? $server['name'] : '')),
                    'about' => trim((string) (is_scalar($server['about'] ?? null) ? $server['about'] : '')),
                    'giveaway_channel_id' => trim((string) (is_scalar($server['giveaway_channel_id'] ?? null) ? $server['giveaway_channel_id'] : '')),
                    'mod_log_channel_id' => trim((string) (is_scalar($server['mod_log_channel_id'] ?? null) ? $server['mod_log_channel_id'] : '')),
                    'trusted_domains' => self::strings((array) ($server['trusted_domains'] ?? [])),
                ];
            }

            $rux['servers'] = $servers;
        }

        return $rux;
    }

    /**
     * Settings of config('plugged-host-ai.rux')'s shape as noprofit.space takes them (PUT
     * …/rux/settings, and what its GET answers): the servers of slots 2 to 10 that have an id
     * become a list in slot order, the bot token comes along.
     *
     * @param  array<string, mixed>  $rux
     * @return array<string, mixed>
     */
    public static function forNoprofit(array $rux): array
    {
        $settings = ['bot_token' => trim((string) ($rux['bot_token'] ?? ''))];

        foreach (self::BOOLEANS as $key) {
            data_set($settings, $key, (bool) data_get($rux, $key, false));
        }

        foreach (self::STRINGS as $key) {
            if ($key !== 'bot_token') {
                data_set($settings, $key, trim((string) data_get($rux, $key, '')));
            }
        }

        foreach (self::INTEGERS as $key) {
            data_set($settings, $key, (int) data_get($rux, $key, 0));
        }

        foreach (self::LISTS as $key) {
            data_set($settings, $key, self::strings((array) data_get($rux, $key, [])));
        }

        // Spelled the way noprofit.space keeps them (rux reads both without regard to case).
        $settings['trusted_domains'] = self::lower($settings['trusted_domains']);
        $settings['warnings']['timeout'] = strtolower($settings['warnings']['timeout']);
        $settings['warnings']['ban'] = strtolower($settings['warnings']['ban']);
        $settings['servers'] = [];

        foreach (EnvRuxSettingsSource::SLOTS as $slot) {
            $server = (array) ($rux['servers'][$slot] ?? []);
            $id = trim((string) ($server['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $settings['servers'][] = [
                'id' => $id,
                'name' => trim((string) ($server['name'] ?? '')),
                'about' => trim((string) ($server['about'] ?? '')),
                'giveaway_channel_id' => trim((string) ($server['giveaway_channel_id'] ?? '')),
                'mod_log_channel_id' => trim((string) ($server['mod_log_channel_id'] ?? '')),
                'trusted_domains' => self::lower(self::strings((array) ($server['trusted_domains'] ?? []))),
            ];
        }

        return $settings;
    }

    /**
     * The copy in use when it says noprofit.space keeps rux's settings, else null (this panel's
     * own apply).
     *
     * @return array{fetched_at: int, configured: bool, settings: array<string, mixed>, admin_url: ?string, saved_at: ?string, saved_by: ?string}|null
     */
    private function managedCopy(): ?array
    {
        $current = $this->current();

        return $current !== null && $current['configured'] && is_array($current['settings']) ? $current : null;
    }

    /**
     * @return array{fetched_at: int, configured: bool, settings: ?array<string, mixed>, admin_url: ?string, saved_at: ?string, saved_by: ?string}|null
     */
    private function current(): ?array
    {
        // Without the secret or the URL nothing is noprofit.space's, whatever an old copy says.
        if (!$this->configured()) {
            return null;
        }

        $now = now()->getTimestamp();
        $fresh = $now - max(5, (int) config('plugged-host-noprofit.rux_settings_refresh', 60));

        if ($this->loaded !== null && $this->loaded['fetched_at'] > $fresh) {
            return $this->loaded;
        }

        $stored = $this->load();

        if ($stored !== null && ($this->loaded === null || $stored['fetched_at'] >= $this->loaded['fetched_at'])) {
            $this->loaded = $stored;

            if ($stored['fetched_at'] > $fresh) {
                return $this->loaded;
            }
        }

        if ($now < $this->retryAt || $this->failedRecently()) {
            return $this->loaded;
        }

        try {
            $this->refresh();
        } catch (RuntimeException $e) {
            $this->retryAt = $now + self::RETRY_AFTER;
            $this->noteFailure();

            if ($this->warnOnce()) {
                Log::warning('[rux] could not fetch rux\'s settings from noprofit.space; using the last ones fetched', [
                    'reason' => $e->getMessage(),
                    'last_fetched_at' => $this->loaded['fetched_at'] ?? null,
                ]);
            }
        }

        return $this->loaded;
    }

    /**
     * @return array{fetched_at: int, configured: bool, settings: ?array<string, mixed>, admin_url: ?string, saved_at: ?string, saved_by: ?string}
     *
     * @throws RuntimeException
     */
    private function fetch(): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('noprofit.space is not configured on this panel.');
        }

        try {
            $response = $this->client->send('GET', self::PATH, null, $this->secret(), 5);
        } catch (Throwable $e) {
            throw new RuntimeException('noprofit.space could not be reached: ' . class_basename($e), 0, $e);
        }

        $configured = $response->json('configured');
        $settings = $response->json('settings');

        if (!$response->successful() || !is_bool($configured)) {
            throw new RuntimeException('noprofit.space answered HTTP ' . $response->status() . '.');
        }

        // A configured answer without the settings rux needs is not taken: the last copy stays.
        if ($configured && (!is_array($settings) || !is_bool($settings['enabled'] ?? null) || !is_string($settings['guild_id'] ?? null))) {
            throw new RuntimeException('noprofit.space answered without rux\'s settings.');
        }

        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? mb_substr($value, 0, 512) : null;

        return [
            'fetched_at' => now()->getTimestamp(),
            'configured' => $configured,
            'settings' => $configured ? $settings : null,
            'admin_url' => $text($response->json('admin_url')),
            'saved_at' => $text($response->json('saved_at')),
            'saved_by' => $text($response->json('saved_by')),
        ];
    }

    /**
     * @return array{fetched_at: int, configured: bool, settings: ?array<string, mixed>, admin_url: ?string, saved_at: ?string, saved_by: ?string}|null
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
            return null; // Written under another app key.
        }

        if (!is_array($data) || !is_int($data['fetched_at'] ?? null) || !is_bool($data['configured'] ?? null)) {
            return null;
        }

        return [
            'fetched_at' => $data['fetched_at'],
            'configured' => $data['configured'],
            'settings' => is_array($data['settings'] ?? null) ? $data['settings'] : null,
            'admin_url' => is_string($data['admin_url'] ?? null) ? $data['admin_url'] : null,
            'saved_at' => is_string($data['saved_at'] ?? null) ? $data['saved_at'] : null,
            'saved_by' => is_string($data['saved_by'] ?? null) ? $data['saved_by'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
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
        return $this->supervisor->runtimeDirectory() . '/rux-settings.enc';
    }

    /**
     * Whether another process on this box failed to fetch a moment ago.
     */
    private function failedRecently(): bool
    {
        try {
            return Cache::has($this->failureKey());
        } catch (Throwable) {
            return false;
        }
    }

    private function noteFailure(): void
    {
        try {
            Cache::put($this->failureKey(), true, self::RETRY_AFTER);
        } catch (Throwable) {
            // The in-memory backoff still holds for this process.
        }
    }

    private function warnOnce(): bool
    {
        try {
            return Cache::add('plugged-host-ai:rux:settings-fetch-warned:' . gethostname(), true, 600);
        } catch (Throwable) {
            return true;
        }
    }

    private function failureKey(): string
    {
        return 'plugged-host-ai:rux:settings-fetch-failed:' . gethostname();
    }

    /** "accounts.noprofit.space" */
    private function host(): string
    {
        return (string) (parse_url($this->client->baseUrl(), PHP_URL_HOST) ?: 'noprofit.space');
    }

    /**
     * The page rux's settings are edited on: the one noprofit.space named, when it is on its own
     * host over https, else its Admin > rux address.
     */
    private function adminUrl(?string $url): string
    {
        if (is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === $this->host()) {
            return $url;
        }

        return $this->client->baseUrl() . '/admin/rux';
    }

    /**
     * @param  array<mixed>  $list
     * @return list<string>
     */
    private static function strings(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $list,
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param  list<string>  $list
     * @return list<string>
     */
    private static function lower(array $list): array
    {
        return array_values(array_unique(array_map('strtolower', $list)));
    }

    private function secret(): string
    {
        return (string) config('plugged-host-noprofit.sync_secret', '');
    }
}
