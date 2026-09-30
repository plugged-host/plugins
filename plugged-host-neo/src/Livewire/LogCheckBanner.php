<?php

namespace PluggedHost\Neo\Livewire;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Neo\Agent\ServerGuard;
use PluggedHost\Neo\Services\LogCheck;
use PluggedHost\Neo\Support\MclogsHandoff;
use PluggedHost\Neo\Support\NeoAccess;
use Throwable;

/**
 * Above a Minecraft server's console: the automatic log check (LogCheck). The log is uploaded to
 * mclo.gs on its own -- nobody has to press anything -- and when mclo.gs finds problems or
 * errors they are listed here, with "Ask neo to fix it", which opens neo on that log. With no
 * problems it is one quiet line with the log's link, for sharing with support.
 *
 * The page renders straight away and the check runs after (wire:init), then again every
 * minute while the console is open. Mounted from NeoPluginProvider on the Console page only.
 */
class LogCheckBanner extends Component
{
    #[Locked]
    public int $serverId;

    public bool $checked = false;

    public static function shouldMount(): bool
    {
        try {
            $user = Filament::auth()->user();
            $server = Filament::getTenant();

            return $user instanceof User
                && $server instanceof Server
                && self::isMinecraft($server)
                && !$server->isInConflictState()
                && $user->can(SubuserPermission::WebsocketConnect, $server);
        } catch (Throwable) {
            return false;
        }
    }

    public function mount(): void
    {
        $server = Filament::getTenant();

        abort_unless($server instanceof Server, 404);

        $this->serverId = (int) $server->id;
    }

    /**
     * Run the check (uploads only when the log changed and is due; see LogCheck).
     */
    public function check(LogCheck $logs): void
    {
        $server = $this->server();

        if ($server === null) {
            return;
        }

        try {
            $running = !$server->retrieveStatus()->isOffline();
        } catch (Throwable) {
            $running = false;
        }

        $logs->check($server, $running);
        $this->checked = true;
    }

    public function render(LogCheck $logs): View
    {
        $server = $this->server();
        $result = $server ? $logs->last($server) : null;
        $user = auth()->user();

        $hasIssues = $result !== null && ($result['problems'] !== [] || (int) $result['errors'] > 0);
        $canAsk = $hasIssues && $user instanceof User && $server !== null
            && NeoAccess::enabledFor($user) && ServerGuard::canUse($user, $server);

        // "Ask neo" only accepts logs this user was shown for this server (MclogsHandoff).
        if ($canAsk) {
            MclogsHandoff::remember($user, $server, $result, count($result['problems']));
        }

        return view('plugged-host-neo::livewire.log-check', [
            'result' => $result,
            'hasIssues' => $hasIssues,
            'canAsk' => $canAsk,
        ]);
    }

    private function server(): ?Server
    {
        $user = auth()->user();
        $server = Server::query()->find($this->serverId);

        if (!$user instanceof User || !$server instanceof Server || !self::isMinecraft($server) || !$user->can(SubuserPermission::WebsocketConnect, $server)) {
            return null;
        }

        return $server;
    }

    /**
     * mclo.gs reads Minecraft logs (Java, Bedrock, proxies); other games get no log check.
     */
    public static function isMinecraft(Server $server): bool
    {
        return in_array('minecraft', (array) ($server->egg?->tags ?? []), true);
    }
}
