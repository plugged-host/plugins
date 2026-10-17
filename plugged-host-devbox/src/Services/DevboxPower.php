<?php

namespace PluggedHost\Devbox\Services;

use App\Models\Server;
use App\Models\ServerVariable;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxAccess;
use PluggedHost\Devbox\Support\DevboxWeb;
use Throwable;

/**
 * Starting and stopping a devbox. Every start gets a new bearer token (the devbox's only way to
 * talk to the panel; only its sha256 is kept) and new connect codes; a stop retires both. The
 * devbox never stops itself (Wings restarts a clean exit), so idle and max-runtime stops come
 * from here, through Wings.
 *
 * start() checks everything again itself (who asks, Plus, the running cap, the game server, an
 * abuse finding, the node), whatever the caller checked: it is the one way a devbox gets a token.
 *
 * A stop never fails open. stop() marks the devbox stopping (from then on the panel refuses its
 * token: `ph` closes every session at its next heartbeat) and asks Wings; the token is only
 * retired once Wings reports the devbox offline (settle()). Until then the reconcile asks again
 * every minute, and kills it once a stop has taken longer than KILL_AFTER seconds.
 */
final class DevboxPower
{
    /** Seconds after a stop request before the devbox is killed instead. */
    public const KILL_AFTER = 60;

    /** Seconds the start lock is held at most (one per payer, around the running cap). */
    private const LOCK_SECONDS = 60;

    public function start(Devbox $devbox, User $actor): void
    {
        $game = $devbox->game ?? throw new DevboxException('missing');
        $server = $devbox->server ?? throw new DevboxException('missing');

        // The person asking must get exactly this devbox (their own, or the server's shared one),
        // with the Plus that pays for it.
        $resolved = DevboxAccess::resolve($actor, $game);
        if ($resolved === null || (int) ($resolved[1]?->id ?? 0) !== (int) $devbox->id) {
            throw new DevboxException('not_allowed');
        }

        $payer = DevboxAccess::payer($devbox) ?? throw new DevboxException('not_allowed');
        if ($reason = DevboxAccess::refusal($actor, $game, $payer)) {
            throw new DevboxException($reason);
        }
        if ($server->isSuspended()) {
            // The devbox's own server: only the abuse checks (or an admin) suspend one.
            throw new DevboxException('abuse');
        }

        // One start at a time per payer, so two clicks (or two servers) never both pass the cap.
        Cache::lock("plugged-host-devbox:start:{$payer->id}", self::LOCK_SECONDS)->block(20, function () use ($devbox, $server, $game, $payer): void {
            $devbox->refresh();

            if ($devbox->isStopping()) {
                throw new DevboxException('stopping');
            }
            if ($devbox->token_hash !== null) {
                return; // started meanwhile
            }
            if (DevboxAccess::running($payer) >= DevboxAccess::runningCap()) {
                throw new DevboxException('too_many');
            }

            // Wings must have it offline: a devbox the panel thinks is stopped but that still runs
            // (a stop that never reached Wings before this release) is killed, not started twice.
            $state = $this->state($devbox);
            if ($state === null) {
                throw new DevboxException('node_down');
            }
            if ($state === 'missing') {
                throw new DevboxException('missing'); // Wings doesn't have it: nothing to start
            }
            if ($state !== 'offline') {
                $this->power($server, 'kill');

                throw new DevboxException('stopping');
            }

            if ($this->gameDiskFull($game)) {
                throw new DevboxException('disk_full');
            }

            $this->launch($devbox, $server, 'start');
        });
    }

    /**
     * A running devbox starts over with a new token and new codes, everything in it ended: what
     * happens to a shared devbox when someone loses access to it (removed, a permission taken,
     * the server changed hands), since they shared its Linux user and could have left something
     * running. Stopped instead when nobody pays for it or may use it any more.
     */
    public function restart(Devbox $devbox): void
    {
        $devbox->refresh();
        if (!$devbox->isRunning()) {
            return;
        }

        $game = $devbox->game;
        $server = $devbox->server;
        $payer = DevboxAccess::payer($devbox);

        if ($game === null || $server === null || $payer === null
            || DevboxAccess::tierFor($devbox) === null
            || DevboxAccess::people($devbox)->isEmpty()
            || DevboxAccess::blockedReason($game) !== null
            || DevboxAbuse::blocked($payer)) {
            $this->stop($devbox);

            return;
        }

        $this->launch($devbox, $server, 'restart');
    }

    /**
     * Asks Wings to stop the devbox. True once it is offline (token retired); false while that is
     * not confirmed yet, and settle() (the reconcile, the card's poll) carries on from there.
     */
    public function stop(Devbox $devbox): bool
    {
        if ($devbox->token_hash === null) {
            if ($devbox->stopping_at !== null) {
                $devbox->forceFill(['stopping_at' => null])->save();
            }

            return true;
        }

        if ($devbox->stopping_at === null) {
            $devbox->forceFill(['stopping_at' => now(), 'connections' => 0])->save();
            ConnectCodes::forgetAll($devbox);
        }

        return $this->settle($devbox);
    }

    /**
     * Carries a stop on: retires the token once Wings says offline (or doesn't have the server),
     * asks for the stop again while it runs, kills it once the stop took longer than KILL_AFTER.
     * $known is Wings' state when the caller already has it (the reconcile's node listing).
     */
    public function settle(Devbox $devbox, ?string $known = null): bool
    {
        if ($devbox->stopping_at === null) {
            return $devbox->token_hash === null;
        }

        $server = $devbox->server;
        $state = $server === null ? 'missing' : ($known ?? $this->state($devbox));

        if ($state === 'offline' || $state === 'missing') {
            $devbox->forceFill(['token_hash' => null, 'stopping_at' => null, 'connections' => 0])->save();
            ConnectCodes::forgetAll($devbox);

            return true;
        }

        if ($state === null || $server === null) {
            return false; // Wings didn't answer: asked again at the next reconcile
        }

        if ($devbox->stopping_at->lte(now()->subSeconds(self::KILL_AFTER))) {
            $this->power($server, 'kill');
        } elseif ($state !== 'stopping') {
            $this->power($server, 'stop');
        }

        return false;
    }

    /**
     * What Wings says: running, starting, stopping, offline; 'missing' when Wings doesn't have the
     * server; null when it can't be asked (Wings down, an error).
     */
    public function state(Devbox $devbox): ?string
    {
        $server = $devbox->server;
        if ($server === null) {
            return 'missing';
        }

        try {
            $state = app(DaemonServerRepository::class)->setServer($server)->getHttpClient()
                ->connectTimeout(2)
                ->timeout(4)
                ->get("/api/servers/{$server->uuid}")
                ->json('state');

            return is_string($state) && $state !== '' ? $state : null;
        } catch (RequestException $exception) {
            return $exception->response->status() === 404 ? 'missing' : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * New token, codes and web certificate, written where Wings reads them on the next start,
     * then the power action. If Wings refuses, the devbox is stopped again (the new token must
     * not stay valid for a devbox that never got it).
     */
    private function launch(Devbox $devbox, Server $server, string $action): void
    {
        $token = Str::random(64);
        $this->setVariable($server, 'DEVBOX_TOKEN', $token);
        $this->setVariable($server, 'DEVBOX_PANEL_URL', (string) config('plugged-host-devbox.panel_url'));
        ConnectCodes::forgetAll($devbox);

        // VS Code in the browser: a fresh certificate for <slug>.<web_domain> every start, and the
        // CA the edge's client certificate comes from. Empty switches ph's web side off.
        $web = DevboxWeb::enabled() ? app(DevboxPki::class)->issue(DevboxWeb::host($devbox)) : null;
        $this->setVariable($server, 'DEVBOX_TLS_CERT', $web ? base64_encode($web['cert']) : '', optional: true);
        $this->setVariable($server, 'DEVBOX_TLS_KEY', $web ? base64_encode($web['key']) : '', optional: true);
        $this->setVariable($server, 'DEVBOX_EDGE_CA', $web ? base64_encode($web['ca']) : '', optional: true);
        if ($web !== null) {
            try {
                app(DevboxDns::class)->ensure($devbox);
            } catch (Throwable $exception) {
                report($exception); // SSH works without it; "Open in browser" waits for the record
            }
        }

        // The image follows the settings (a release tag or a pinned digest), for devboxes made
        // before it changed too. Wings reads it from the panel when it starts the devbox. A plain
        // update: nothing listens for a system server's image.
        $image = DevboxProvisioner::image();
        if ((string) $server->image !== $image) {
            Server::query()->whereKey($server->id)->update(['image' => $image]);
        }

        $devbox->forceFill([
            'token_hash' => Devbox::hashToken($token),
            'stopping_at' => null,
            'boot_status' => null,
            'boot_detail' => null,
            'booted_at' => null,
            'connections' => 0,
            // max_hours counts from the first start: a restart for revocation doesn't extend it.
            'started_at' => $action === 'restart' ? ($devbox->started_at ?? now()) : now(),
            'last_used_at' => now(),
        ])->save();

        try {
            app(DaemonServerRepository::class)->setServer($server)->power($action);
        } catch (Throwable $exception) {
            $this->stop($devbox);

            throw $exception;
        }
    }

    /**
     * Whether the game server already uses all the disk it may: the devbox writes into its
     * volume, which Wings only measures for the game server itself. Wings' figure for a game
     * server that is offline can be minutes old (it is refreshed while the server runs, on file
     * manager or SFTP writes and on console connects; or always fresh with Wings' quotas), so
     * this and the reconcile's check go by the latest figure Wings has. Unknown passes.
     */
    public function gameDiskFull(Server $game, ?int $diskBytes = null): bool
    {
        $limit = (int) $game->disk;
        if ($limit <= 0) {
            return false; // unlimited
        }

        if ($diskBytes === null) {
            try {
                $details = app(DaemonServerRepository::class)->setServer($game)->getDetails(2);
            } catch (Throwable) {
                return false;
            }
            $diskBytes = isset($details['utilization']['disk_bytes']) ? (int) $details['utilization']['disk_bytes'] : null;
        }

        return $diskBytes !== null && $diskBytes >= $limit * 1024 * 1024;
    }

    private function power(Server $server, string $action): void
    {
        try {
            app(DaemonServerRepository::class)->setServer($server)->power($action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function setVariable(Server $server, string $env, string $value, bool $optional = false): void
    {
        $variable = $server->egg?->variables()->where('env_variable', $env)->first();
        if ($variable === null) {
            if ($optional && $value === '') {
                return; // an egg from before the web version; nothing to switch off
            }

            throw new DevboxException('not_set_up');
        }

        ServerVariable::query()->updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $variable->id],
            ['variable_value' => $value],
        );
    }
}
