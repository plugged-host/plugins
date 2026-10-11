<?php

namespace PluggedHost\Devbox\Http;

use App\Facades\Activity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use phpseclib4\Crypt\PublicKeyLoader;
use PluggedHost\Devbox\Http\Concerns\ResolvesDevbox;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Services\ConnectCodes;
use PluggedHost\Devbox\Support\DevboxAccess;
use Throwable;

/**
 * What a running devbox (`ph serve`) asks the panel. Authenticated by the bearer token the devbox
 * got for this start, which the person in it can read: so every answer is limited to that devbox
 * and re-checks access, and nothing here can start, extend or reconfigure anything.
 */
final class AgentController
{
    use ResolvesDevbox;

    public function boot(Request $request): JsonResponse
    {
        $devbox = $this->devbox($request);
        if ($devbox === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (!$this->allow($devbox, 'boot', 10)) {
            return response()->json(['error' => 'too many requests'], 429);
        }

        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:40'],
            'mount' => ['nullable', 'string', 'max:32'],
            'detail' => ['nullable', 'string', 'max:255'],
            'host_key' => ['nullable', 'string', 'max:100'],
        ]);

        $devbox->forceFill([
            'version' => $data['version'] ?? null,
            'boot_status' => $data['mount'] ?? 'unknown',
            'boot_detail' => $data['detail'] ?? null,
            'host_key' => $data['host_key'] ?? null,
            'booted_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        return response()->json($this->access($devbox));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $devbox = $this->devbox($request);
        if ($devbox === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (!$this->allow($devbox, 'heartbeat', 6)) {
            return response()->json(['error' => 'too many requests'], 429);
        }

        $connections = max(0, min(1000, (int) $request->input('connections', 0)));
        $devbox->forceFill([
            'connections' => $connections,
            'last_seen_at' => now(),
            ...($connections > 0 ? ['last_used_at' => now()] : []),
        ])->save();

        // Once an hour while someone works in it, an entry on the game server: its owner sees it,
        // and limbo (which goes by the server's last activity) never hibernates it from under them.
        if ($connections > 0 && Cache::add("plugged-host-devbox:active:{$devbox->id}", 1, 3600)) {
            try {
                if ($game = $devbox->game) {
                    $log = Activity::event('server:devbox.active')->subject($game)->property('shared', $devbox->isShared());
                    if ($actor = ($devbox->isShared() ? $game->user : $devbox->user)) {
                        $log->actor($actor);
                    }
                    $log->log();
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json($this->access($devbox));
    }

    public function auth(Request $request): JsonResponse
    {
        $devbox = $this->devbox($request);
        if ($devbox === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $username = (string) $request->input('username', '');
        $code = (string) $request->input('code', '');
        if ($username === '' || $code === '' || strlen($code) > 256 || strlen($username) > 191) {
            return response()->json(['ok' => false]);
        }

        // 20 checks a minute per devbox: VS Code opens a few connections per window, a bot
        // guessing codes against ~79 bits gets nowhere at that rate.
        if (!$this->allow($devbox, 'auth', 20)) {
            return response()->json(['ok' => false, 'error' => 'too many requests'], 429);
        }

        $person = DevboxAccess::people($devbox)->first(fn (User $user): bool => strtolower($user->username) === strtolower($username));
        if ($person === null || !ConnectCodes::check($devbox, $person, $code)) {
            return response()->json(['ok' => false]);
        }

        $devbox->forceFill(['last_used_at' => now()])->save();

        try {
            if ($game = $devbox->game) {
                Activity::event('server:devbox.connect')
                    ->subject($game)
                    ->actor($person)
                    ->property('ip', (string) $request->input('ip', ''))
                    ->property('shared', $devbox->isShared())
                    ->log();
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Who may sign in, with the public keys on their accounts.
     *
     * @return array{users: list<array{username: string, keys: list<string>}>}
     */
    private function access(Devbox $devbox): array
    {
        $users = DevboxAccess::people($devbox)->map(fn (User $user): array => [
            'username' => $user->username,
            'keys' => $this->keys($user),
        ])->values()->all();

        return ['users' => $users];
    }

    /**
     * @return list<string>
     */
    private function keys(User $user): array
    {
        $keys = [];
        foreach ($user->sshKeys()->get() as $key) {
            try {
                $keys[] = PublicKeyLoader::loadPublicKey($key->public_key)->toString('OpenSSH');
            } catch (Throwable) {
                // A key phpseclib can't read can't sign in either.
            }
        }

        return $keys;
    }
}
