<?php

namespace PluggedHost\FiveM\Services;

use App\Enums\ContainerStatus;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use PluggedHost\FiveM\Support\FiveM;
use RuntimeException;
use Throwable;

/**
 * One server's FiveM runtime: what its variables ask for, what is actually installed, and how a
 * change reaches the server.
 *
 * The panel never downloads an artifact itself. The image does (fivem-runtime, at every start),
 * so changing build or edition is: set the variable, move the server onto the image if it is
 * still on the old yolk, and restart it if it is running. A stopped server picks the change up
 * at its next start, which is also when an auto-updating server gets a new build.
 */
class FiveMRuntime
{
    /** fivem-runtime's record of what it installed. */
    public const MARKER = 'alpine/.plugged-host.json';

    public function edition(Server $server): string
    {
        return $this->variable($server, FiveM::EDITION) === FiveM::ENHANCED ? FiveM::ENHANCED : FiveM::LEGACY;
    }

    /**
     * 'recommended', 'latest' or a build number, read the way fivem-runtime reads it.
     */
    public function version(Server $server): string
    {
        $version = trim((string) $this->variable($server, FiveM::VERSION));

        return in_array($version, FiveM::CHANNELS, true) || ctype_digit($version) ? $version : 'recommended';
    }

    public function variable(Server $server, string $env): ?string
    {
        $variable = $server->variables()->where('egg_variables.env_variable', $env)->first();

        if (!$variable) {
            return null;
        }

        $value = $variable->getAttribute('server_value');

        return $value ?? $variable->default_value;
    }

    /**
     * Whether the egg has the variable at all (FIVEM_EDITION only exists once
     * plugged-host:fivem-setup has run).
     */
    public function hasVariable(Server $server, string $env): bool
    {
        return EggVariable::query()->where('egg_id', $server->egg_id)->where('env_variable', $env)->exists();
    }

    public function setVariable(Server $server, string $env, string $value): void
    {
        $variable = EggVariable::query()->where('egg_id', $server->egg_id)->where('env_variable', $env)->first();

        if (!$variable) {
            throw new RuntimeException("$env is not a variable of this server's egg; run php artisan plugged-host:fivem-setup.");
        }

        ServerVariable::query()->updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $variable->id],
            ['variable_value' => $value],
        );
    }

    /**
     * What fivem-runtime last installed, or null when it has not (a server still on the old yolk,
     * or one installed by the old egg script).
     *
     * @return array{edition: string, build: ?int, installed_at: ?string}|null
     *
     * @throws ConnectionException when the node cannot be reached
     */
    public function installed(Server $server): ?array
    {
        try {
            $raw = app(DaemonFileRepository::class)->setServer($server)->getContent(self::MARKER, 65536);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            return null;
        }

        $marker = json_decode($raw, true);

        if (!is_array($marker)) {
            return null;
        }

        $build = $marker['build'] ?? null;

        return [
            'edition' => ($marker['edition'] ?? null) === FiveM::ENHANCED ? FiveM::ENHANCED : FiveM::LEGACY,
            'build' => is_numeric($build) ? (int) $build : null,
            'installed_at' => is_string($marker['installed_at'] ?? null) ? $marker['installed_at'] : null,
        ];
    }

    /**
     * Move a server onto the plugged.host image. Wings syncs the server's configuration and
     * recreates the container at every start, so the next start runs on it.
     */
    public function adopt(Server $server): void
    {
        if (!FiveM::onImage($server)) {
            $server->forceFill(['image' => FiveM::image()])->save();
        }
    }

    /**
     * After a variable change: restart a running server so the change applies now. Returns
     * 'restarting' or 'next_start'.
     */
    public function apply(Server $server): string
    {
        $this->adopt($server);

        if (!$this->isRunning($server)) {
            return 'next_start';
        }

        app(DaemonServerRepository::class)->setServer($server)->power('restart');

        return 'restarting';
    }

    /**
     * The container's state now, not the 15-second cache Server::retrieveStatus() keeps.
     */
    public function state(Server $server): ContainerStatus
    {
        try {
            $details = app(DaemonServerRepository::class)->setServer($server)->getDetails(5);
        } catch (Throwable) {
            return ContainerStatus::Missing;
        }

        return ContainerStatus::tryFrom((string) Arr::get($details, 'state')) ?? ContainerStatus::Missing;
    }

    public function isRunning(Server $server): bool
    {
        return in_array($this->state($server), [ContainerStatus::Running, ContainerStatus::Starting, ContainerStatus::Restarting], true);
    }
}
