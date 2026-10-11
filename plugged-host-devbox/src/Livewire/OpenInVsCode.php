<?php

namespace PluggedHost\Devbox\Livewire;

use App\Models\Allocation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Devbox\Http\WebController;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\ConnectCodes;
use PluggedHost\Devbox\Services\DevboxException;
use PluggedHost\Devbox\Services\DevboxPower;
use PluggedHost\Devbox\Services\DevboxProvisioner;
use PluggedHost\Devbox\Support\DevboxAccess;
use PluggedHost\Devbox\Support\DevboxWeb;
use Throwable;

/**
 * "Open in VS Code" on a game server's settings: creates the person's devbox (or the shared one),
 * starts it, waits until `ph serve` reports in, then shows the one-click link, the connect code
 * and the SSH details. Every action re-checks access: the server id is locked, but the person
 * clicking may have lost Plus or their permission since the page loaded.
 */
class OpenInVsCode extends Component
{
    #[Locked]
    public int $serverId;

    /** Devbox id waiting for its server to finish installing before it can be started. */
    public ?int $pendingStart = null;

    public ?string $error = null;

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;
    }

    public function open(): void
    {
        $this->error = null;
        [$user, $game] = $this->context();
        if ($user === null || $game === null) {
            return;
        }

        try {
            $devbox = app(DevboxProvisioner::class)->ensure($user, $game);

            if ($devbox->token_hash !== null) {
                return; // already running
            }

            if (!$devbox->isShared()) {
                $running = Devbox::query()->where('user_id', $user->id)->whereNotNull('token_hash')->count();
                if ($running >= max(1, (int) config('plugged-host-devbox.running_per_user', 2))) {
                    throw new DevboxException('too_many');
                }
            }

            $this->startWhenInstalled($devbox);
        } catch (DevboxException $exception) {
            $this->error = $exception->reason;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'failed';
        }
    }

    /**
     * wire:poll while something is on its way: starts a devbox whose server just finished
     * installing, and re-renders until it reports in.
     */
    public function poll(): void
    {
        if ($this->pendingStart === null) {
            return;
        }

        $devbox = Devbox::query()->find($this->pendingStart);
        if ($devbox === null || $devbox->token_hash !== null) {
            $this->pendingStart = null;

            return;
        }

        try {
            $this->startWhenInstalled($devbox);
        } catch (Throwable $exception) {
            report($exception);
            $this->pendingStart = null;
            $this->error = 'failed';
        }
    }

    /**
     * VS Code in the browser: a one-time ticket for this person and devbox, then off to the
     * devbox's own address (the edge turns the ticket into a cookie for that host).
     */
    public function openInBrowser(): void
    {
        [$user, $game] = $this->context();
        $devbox = $user && $game ? $this->current($user, $game) : null;
        if ($devbox === null || $devbox->token_hash === null || !DevboxWeb::enabled()) {
            return;
        }

        $this->redirect(DevboxWeb::url($devbox, '/_ph/login?ticket=' . WebController::ticket($devbox, $user)));
    }

    public function stop(): void
    {
        [$user, $game] = $this->context();
        $devbox = $user && $game ? $this->current($user, $game) : null;
        if ($devbox === null || ($devbox->isShared() && (int) $game->owner_id !== (int) $user->id)) {
            return;
        }

        app(DevboxPower::class)->stop($devbox);
    }

    public function enableShared(): void
    {
        [$user, $game] = $this->context();
        if ($user === null || $game === null || (int) $game->owner_id !== (int) $user->id || DevboxAccess::tier($user) === null) {
            return;
        }

        app(DevboxProvisioner::class)->enableShared($game);
    }

    public function disableShared(): void
    {
        [$user, $game] = $this->context();
        if ($user === null || $game === null || (int) $game->owner_id !== (int) $user->id) {
            return;
        }

        app(DevboxProvisioner::class)->disableShared($game);
    }

    public function render(): View
    {
        [$user, $game] = $this->context();

        return view('plugged-host-devbox::livewire.open-in-vscode', $this->viewData($user, $game));
    }

    private function startWhenInstalled(Devbox $devbox): void
    {
        $server = $devbox->server;
        if ($server === null) {
            throw new DevboxException('missing');
        }

        if (!$server->isInstalled()) {
            $this->pendingStart = $devbox->id;

            return;
        }

        $this->pendingStart = null;
        app(DevboxPower::class)->start($devbox);
    }

    /**
     * @return array{0: ?User, 1: ?Server}
     */
    private function context(): array
    {
        $user = user();
        $game = Server::query()->find($this->serverId);

        if (!$user instanceof User || $game === null || !DevboxAccess::ready() || !DevboxAccess::isMember($user, $game)) {
            return [null, null];
        }

        return [$user, $game];
    }

    private function current(User $user, Server $game): ?Devbox
    {
        return DevboxAccess::resolve($user, $game)[1] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(?User $user, ?Server $game): array
    {
        if ($user === null || $game === null) {
            return ['state' => 'hidden'];
        }

        $isOwner = (int) $game->owner_id === (int) $user->id;
        $resolved = DevboxAccess::resolve($user, $game);
        $shared = DevboxAccess::shared($game);

        $base = [
            'isOwner' => $isOwner,
            'shared' => $shared !== null,
            'ownerHasPlus' => DevboxAccess::tier($game->user) !== null,
            'error' => $this->error,
            'plusUrl' => url('/plus'),
            'remoteSshUrl' => 'vscode:extension/ms-vscode-remote.remote-ssh',
        ];

        if ($resolved === null) {
            return $base + ['state' => 'locked'];
        }

        [$kind, $devbox] = $resolved;
        $server = $devbox?->server;
        $base += ['kind' => $kind];

        $state = match (true) {
            $devbox === null || $server === null => 'none',
            $this->pendingStart === $devbox->id || !$server->isInstalled() => 'creating',
            $devbox->token_hash === null => 'stopped',
            $devbox->booted_at === null => $devbox->started_at?->lt(now()->subMinutes(10)) ? 'failed' : 'starting',
            $devbox->boot_status === 'mount_missing' => 'mount_missing',
            default => 'ready',
        };

        $data = $base + ['state' => $state, 'polling' => in_array($state, ['creating', 'starting'], true)];

        if ($state !== 'ready') {
            return $data;
        }

        // Not $server->allocation: on a game server's page Filament scopes allocation queries to
        // that game server (the tenant), which would hide the devbox's own.
        $allocation = Allocation::withoutGlobalScopes()->find($server->allocation_id);
        $host = (string) ($allocation?->alias ?: $server->node?->fqdn);
        $port = (int) ($allocation?->port ?? 0);
        $authority = bin2hex((string) json_encode(['hostName' => $host, 'user' => $user->username, 'port' => $port], JSON_UNESCAPED_SLASHES));

        return $data + [
            'host' => $host,
            'port' => $port,
            'username' => $user->username,
            'code' => ConnectCodes::for($devbox, $user),
            'hostKey' => $devbox->host_key,
            'sshCommand' => "ssh -p {$port} {$user->username}@{$host}",
            'vscodeUrl' => "vscode://vscode-remote/ssh-remote+{$authority}/server",
            'connections' => $devbox->connections,
            'canStop' => !$devbox->isShared() || $isOwner,
            'web' => DevboxWeb::enabled(),
        ];
    }
}
