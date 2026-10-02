<?php

namespace PluggedHost\Servers\Services;

use App\Models\Egg;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Exceptions\Service\Deployment\NoViableNodeException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Support\AccountAllowance;
use Throwable;

/**
 * Creating a server for a user: the one place the user-creatable-server rules live.
 *
 * The create page, the client API and plugged-host-ai all go through here, so every surface
 * checks the same allowance, location and plan rules and does the same post-create work
 * (onboarding wizard, EULA). Callers turn ProvisioningException::$reason into their own message.
 *
 * Input keys: name, game (egg id or uuid), location (cc | plus:cc | premium:cc), and either
 * plan (plan id) or cpu/memory/disk.
 */
class ServerProvisioner
{
    public const NAME_MAX = 191;

    public function __construct(
        protected GameCatalog $games,
        protected LocationCatalog $locations,
        protected PlanCatalog $plans,
    ) {}

    /**
     * Why this request cannot be created right now, or null when it can. $fresh drops the
     * usage memos first (needed after a create/resize/delete in the same process).
     *
     * @param  array<string, mixed>  $input
     */
    public function check(User $user, array $input, bool $fresh = false): ?string
    {
        try {
            $this->prepare($user, $input, $fresh);
        } catch (ProvisioningException $e) {
            return $e->reason;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string|null>  $variables  User-editable egg variables (API only).
     *
     * @throws ProvisioningException
     */
    public function create(User $user, array $input, array $variables = []): Server
    {
        // The allowance is read, checked, then spent by inserting the server: parallel requests
        // would all pass the same check, so an account provisions one server at a time.
        return self::oneAtATime($user, fn (): Server => $this->createNow($user, $input, $variables));
    }

    /**
     * Run $callback holding the account's provisioning lock (server creates and resizes).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ProvisioningException
     */
    public static function oneAtATime(User $user, Closure $callback): mixed
    {
        try {
            return Cache::lock('plugged-host-servers:provision:' . $user->getKey(), 120)
                ->block((int) config('plugged-host-servers.provision_lock_wait', 30), $callback);
        } catch (LockTimeoutException) {
            throw new ProvisioningException('busy');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string|null>  $variables
     *
     * @throws ProvisioningException
     */
    private function createNow(User $user, array $input, array $variables): Server
    {
        ['limits' => $limits, 'egg' => $egg, 'resources' => $resources, 'location' => $location, 'name' => $name] = $this->prepare($user, $input, true);

        // Software variant: the catalog's first type (Purpur for Minecraft); the loader and
        // version are picked afterwards in the onboarding wizard.
        $types = $this->games->typesFor($egg);
        $type = $types['types'][0]['value'] ?? null;

        if ($types && $types['variable'] !== '' && $type !== null) {
            $variables[$types['variable']] = $type;
        }

        $runtime = $this->games->resolveRuntime($egg, $type);

        try {
            $server = $limits->createServer(
                $user,
                $name,
                $egg,
                $resources['cpu'],
                $resources['memory'],
                $resources['disk'],
                $variables,
                $location,
                $runtime['startup'],
                $runtime['image'],
            );
        } catch (NoViableNodeException) {
            throw new ProvisioningException('no_viable_node');
        } catch (NoViableAllocationException) {
            throw new ProvisioningException('no_viable_allocation');
        } catch (ProvisioningException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new ProvisioningException('creation_failed');
        } finally {
            $this->forget($user);
        }

        if (!$server) {
            throw new ProvisioningException('over_quota');
        }

        if ($types !== null) {
            // ServerOnboarding lives in plugged-host-minecraft; by class-string so servers does
            // not hard-depend on it.
            if (class_exists(\PluggedHost\Minecraft\Models\ServerOnboarding::class)) {
                \PluggedHost\Minecraft\Models\ServerOnboarding::markRequired($server);
            }

            try {
                app(DaemonFileRepository::class)->setServer($server)->putContent('eula.txt', "eula=true\n");
            } catch (Throwable) {
                // Also written by the Server\Installed listener once the install finishes.
            }
        }

        return $server;
    }

    /**
     * Resolve a plan id or raw numbers into cpu/memory/disk.
     *
     * @param  array<string, mixed>  $input
     * @return array{cpu: int, memory: int, disk: int}
     *
     * @throws ProvisioningException
     */
    public function resources(User $user, array $input, ?AccountAllowance $limits = null): array
    {
        if (is_string($input['plan'] ?? null) && $input['plan'] !== '') {
            $plan = $this->plans->find($user, $input['plan'], $limits);

            if ($plan === null) {
                throw new ProvisioningException('unknown_plan');
            }

            return ['cpu' => $plan['cpu'], 'memory' => $plan['memory'], 'disk' => $plan['disk']];
        }

        return [
            'cpu' => max(0, (int) ($input['cpu'] ?? 0)),
            'memory' => max(0, (int) ($input['memory'] ?? 0)),
            'disk' => max(0, (int) ($input['disk'] ?? 0)),
        ];
    }

    /**
     * Drop the per-process memos so a check right after a create/resize/delete sees the new
     * usage (they are static and would otherwise go stale within one request or CLI run).
     */
    public function forget(User $user): void
    {
        AccountAllowance::forgetMemo($user);

        if (class_exists(\PluggedHost\Core\Services\AccountSummary::class)) {
            app(\PluggedHost\Core\Services\AccountSummary::class)->forget($user);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{limits: AccountAllowance, egg: Egg, resources: array{cpu: int, memory: int, disk: int}, location: string, name: string}
     *
     * @throws ProvisioningException
     */
    protected function prepare(User $user, array $input, bool $fresh): array
    {
        if ($fresh) {
            $this->forget($user);
        }

        $limits = AccountAllowance::forUser($user);
        if ($limits === null) {
            throw new ProvisioningException('no_resource_limits');
        }

        $resources = $this->resources($user, $input, $limits);

        foreach (['cpu', 'memory', 'disk'] as $resource) {
            if ($limits->{$resource} > 0 && $resources[$resource] <= 0) {
                throw new ProvisioningException('resources_required');
            }
        }

        if (!$limits->canCreateServer($user, $resources['cpu'], $resources['memory'], $resources['disk'])) {
            throw new ProvisioningException('over_quota');
        }

        $egg = $this->games->find($input['game'] ?? null);
        if ($egg === null) {
            throw new ProvisioningException('unknown_game');
        }

        $location = is_string($input['location'] ?? null) ? strtolower(trim($input['location'])) : '';
        if ($location === '') {
            throw new ProvisioningException('invalid_location');
        }

        if ($this->locations->resolve($location)[1] !== LocationCatalog::FREE && !\PluggedHost\Servers\Support\PremiumAccess::isUnlocked($user)) {
            throw new ProvisioningException('location_locked');
        }

        if (!$this->locations->isValid($location, $user)) {
            throw new ProvisioningException('invalid_location');
        }

        $offer = $this->locations->available($resources['cpu'], $resources['memory'], $resources['disk'], $user)->firstWhere('code', $location);
        if (!is_array($offer) || !($offer['available'] ?? false)) {
            throw new ProvisioningException('location_unavailable');
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ProvisioningException('name_required');
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw new ProvisioningException('name_too_long');
        }

        return ['limits' => $limits, 'egg' => $egg, 'resources' => $resources, 'location' => $location, 'name' => $name];
    }
}
