<?php

namespace PluggedHost\Storage\Services;

use App\Models\Node;
use App\Traits\EnvironmentWriterTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\Storage\Providers\StoragePluginProvider;

/**
 * Setting a storage box up from one command.
 *
 * An admin copies `curl -fsSL <panel>/plugged-host/storage/agent/install.sh?code=… | sudo bash`
 * from the storage settings (or `php artisan plugged-host:storage-setup`). The code is one-time
 * and lives an hour: with it the installer fetches what it needs from the panel (the agent
 * secret, generated here if there is none yet, the node list, the mirror settings) and sends back
 * what only the box knows (its mirror public key and the address the agent answers on), so
 * nothing has to be copied between the two by hand.
 */
final class StorageSetup
{
    use EnvironmentWriterTrait;

    public const CODE_PATTERN = '/^[A-Za-z0-9]{40}$/';

    private const KEY = 'plugged-host-storage:setup-code:';

    private const TTL_MINUTES = 60;

    public function issue(): string
    {
        $code = Str::random(40);

        Cache::put(self::KEY . hash('sha256', $code), true, now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    public function command(string $code): string
    {
        return 'curl -fsSL ' . $this->panelUrl() . '/plugged-host/storage/agent/install.sh?code=' . $code . ' | sudo bash';
    }

    public function valid(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1 && Cache::has(self::KEY . hash('sha256', $code));
    }

    /**
     * What the installer needs. Creates the agent secret on the first setup.
     *
     * @return array<string, mixed>
     */
    public function bootstrap(): array
    {
        $secret = (string) config('plugged-host-storage.agent_secret', '');

        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            $this->writeToEnvironment(['STORAGE_AGENT_SECRET' => $secret]);
            config()->set('plugged-host-storage.agent_secret', $secret);
        }

        return [
            'panel_url' => $this->panelUrl(),
            'agent_secret' => $secret,
            'agent_version' => (string) config('plugged-host-storage.agent_version', ''),
            'agent_url' => (string) config('plugged-host-storage.agent_url', ''),
            'mirror_interval_min' => (int) config('plugged-host-storage.mirror_interval_min', 30),
            'mirror_snapshots' => (int) config('plugged-host-storage.mirror_snapshots', 3),
            'min_free_percent' => (float) config('plugged-host-storage.min_free_percent', 10),
            'nodes' => Node::query()->orderBy('id')->get(['name', 'fqdn'])
                ->map(fn (Node $node) => ['name' => (string) $node->name, 'fqdn' => (string) $node->fqdn])
                ->values()
                ->all(),
        ];
    }

    /**
     * Store what the box reported and use up the code.
     *
     * @return list<string> what was not accepted (empty when everything was)
     */
    public function complete(string $code, string $mirrorKey, string $agentUrl): array
    {
        $values = [];
        $rejected = [];

        $mirrorKey = trim($mirrorKey);
        if (preg_match(StoragePluginProvider::PUBLIC_KEY_PATTERN, $mirrorKey) === 1) {
            $values['STORAGE_MIRROR_PUBLIC_KEY'] = $mirrorKey;
        } else {
            $rejected[] = 'mirror_public_key';
        }

        $agentUrl = rtrim(trim($agentUrl), '/');
        if (filter_var($agentUrl, FILTER_VALIDATE_URL) && preg_match('#^https?://#', $agentUrl) === 1) {
            $values['STORAGE_AGENT_URL'] = $agentUrl;
        } else {
            $rejected[] = 'agent_url';
        }

        if ($values !== []) {
            $this->writeToEnvironment($values);
        }

        Cache::forget(self::KEY . hash('sha256', $code));

        return $rejected;
    }

    private function panelUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
