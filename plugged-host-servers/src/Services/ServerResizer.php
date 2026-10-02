<?php

namespace PluggedHost\Servers\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Support\AccountAllowance;
use Throwable;

/**
 * Changing a server's CPU / memory / disk within its OWNER's allowance: the rules behind the
 * Resources section of the server settings page, shared with plugged-host-ai.
 *
 * The server's own current size always counts as available to it, so an over-quota owner can
 * keep or shrink a server but not grow it.
 */
class ServerResizer
{
    public function enabled(): bool
    {
        return (bool) config('plugged-host-servers.user-creatable-servers.can_users_update_servers');
    }

    public function limits(Server $server): ?AccountAllowance
    {
        AccountAllowance::forgetMemo($server->user);

        return $this->enabled() ? AccountAllowance::forUser($server->user) : null;
    }

    /**
     * The largest value each resource may be set to (null = unlimited).
     *
     * @return array{cpu: ?int, memory: ?int, disk: ?int}|null
     */
    public function bounds(Server $server): ?array
    {
        $limits = $this->limits($server);

        if ($limits === null) {
            return null;
        }

        return [
            'cpu' => $limits->cpu > 0 ? $server->cpu + ($limits->getCpuLeft($server->user) ?? 0) : null,
            'memory' => $limits->memory > 0 ? $server->memory + ($limits->getMemoryLeft($server->user) ?? 0) : null,
            'disk' => $limits->disk > 0 ? $server->disk + ($limits->getDiskLeft($server->user) ?? 0) : null,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(Server $server): array
    {
        $limits = $this->limits($server);

        if ($limits === null) {
            return [];
        }

        $bounds = $this->bounds($server);
        $rule = static fn (int $roleLimit, ?int $max): array => $roleLimit > 0
            ? ['required', 'integer', 'min:1', 'max:' . (int) $max]
            : ['required', 'integer', 'min:0'];

        return [
            'cpu' => $rule($limits->cpu, $bounds['cpu']),
            'memory' => $rule($limits->memory, $bounds['memory']),
            'disk' => $rule($limits->disk, $bounds['disk']),
        ];
    }

    /**
     * Apply new limits and push them to wings. Returns whether wings took the change live
     * (false means the server needs a restart to pick it up).
     *
     * @param  array{cpu: mixed, memory: mixed, disk: mixed}  $resources
     *
     * @throws ProvisioningException When resizing is not allowed for this server.
     * @throws ValidationException When a value is outside the owner's allowance.
     */
    public function resize(Server $server, array $resources, array $attributeNames = []): bool
    {
        // The limits come from what the account has left: resizes (and creates) of one account run
        // one at a time, or two parallel resizes would both spend the same headroom.
        return ServerProvisioner::oneAtATime($server->user, function () use ($server, $resources, $attributeNames): bool {
            AccountAllowance::forgetMemo($server->user);

            $rules = $this->rules($server);

            if ($rules === []) {
                throw new ProvisioningException('resize_not_allowed');
            }

            $validated = Validator::make($resources, $rules, [], $attributeNames)->validate();

            $server->update([
                'cpu' => (int) $validated['cpu'],
                'memory' => (int) $validated['memory'],
                'disk' => (int) $validated['disk'],
            ]);

            AccountAllowance::forgetMemo($server->user);

            try {
                app(DaemonServerRepository::class)->setServer($server)->sync();

                return true;
            } catch (ConnectionException) {
                return false;
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        });
    }
}
