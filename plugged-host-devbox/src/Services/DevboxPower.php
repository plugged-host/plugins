<?php

namespace PluggedHost\Devbox\Services;

use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Str;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxWeb;
use Throwable;

/**
 * Starting and stopping a devbox. Every start gets a new bearer token (the devbox's only way to
 * talk to the panel; only its sha256 is kept) and new connect codes; a stop retires both. The
 * devbox never stops itself (Wings restarts a clean exit), so idle and max-runtime stops come
 * from here, through Wings.
 */
final class DevboxPower
{
    public function start(Devbox $devbox): void
    {
        $server = $devbox->server ?? throw new DevboxException('missing');

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

        $devbox->forceFill([
            'token_hash' => Devbox::hashToken($token),
            'boot_status' => null,
            'boot_detail' => null,
            'booted_at' => null,
            'connections' => 0,
            'started_at' => now(),
            'last_used_at' => now(),
        ])->save();

        app(DaemonServerRepository::class)->setServer($server)->power('start');
    }

    public function stop(Devbox $devbox): void
    {
        $devbox->forceFill(['token_hash' => null, 'connections' => 0])->save();
        ConnectCodes::forgetAll($devbox);

        if ($server = $devbox->server) {
            try {
                app(DaemonServerRepository::class)->setServer($server)->power('stop');
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * What Wings says: running, starting, stopping, offline, or null when it can't be asked.
     */
    public function state(Devbox $devbox): ?string
    {
        $server = $devbox->server;
        if ($server === null) {
            return null;
        }

        try {
            $state = app(DaemonServerRepository::class)->setServer($server)->getDetails(2)['state'] ?? null;

            return is_string($state) ? $state : null;
        } catch (Throwable) {
            return null;
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
