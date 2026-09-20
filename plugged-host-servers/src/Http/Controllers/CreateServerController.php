<?php

namespace PluggedHost\Servers\Http\Controllers;

use App\Exceptions\Service\Deployment\NoViableNodeException;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\JsonResponse;
use PluggedHost\Servers\Http\Requests\CreateServerRequest;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Servers\Services\GameCatalog;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\PlanCatalog;
use Throwable;

/**
 * POST /api/client/provision/servers (WP7) — create a server through the client API.
 *
 * This is the create page's save() as a controller. It must reach the same outcome as the UI, so
 * it reproduces the same server-side resolution rather than trusting the payload:
 *
 *  - the egg comes from GameCatalog::find() (validates it is an offered egg, by id or uuid),
 *  - the software variant + runtime (startup/image) come from the catalog's first type, never the
 *    request — the same "software is chosen in the wizard, not here" rule as the page,
 *  - the plan is resolved out of PlanCatalog against the caller's RoleResourceLimits (or, in
 *    advanced mode, raw numbers that createServer() then clamps to the caller's limits),
 *  - the location's validity (including premium/unlocked) is checked by LocationCatalog and again
 *    inside createServer().
 *
 * createServer() is the single authority for limits and premium gating and returns false when
 * they are exceeded, which maps to 422 here. A server created via the API is marked for
 * onboarding exactly like a UI-created one, so the owner still gets the setup wizard.
 */
class CreateServerController
{
    public function __invoke(CreateServerRequest $request): JsonResponse
    {
        $user = $request->user();
        $resourceLimits = RoleResourceLimits::forUser($user);

        if (!$resourceLimits) {
            return response()->json(['error' => 'no_resource_limits'], 403);
        }

        $egg = app(GameCatalog::class)->find($request->input('game'));

        if (!$egg) {
            return response()->json(['error' => 'unknown_game'], 422);
        }

        $catalog = app(GameCatalog::class);
        $types = $catalog->typesFor($egg);

        // Software variant + hidden variant variable: from the catalog's first type, not the
        // payload — identical to save(). User variables come off the request, keyed by env name.
        $variables = [];
        foreach ((array) $request->input('variables', []) as $envKey => $value) {
            if (is_string($envKey)) {
                $variables[$envKey] = is_scalar($value) ? (string) $value : null;
            }
        }

        $type = $types['types'][0]['value'] ?? null;
        if ($types && $types['variable'] !== '' && $type !== null) {
            $variables[$types['variable']] = $type;
        }

        $runtime = $catalog->resolveRuntime($egg, $type);

        $location = is_string($request->input('location')) ? $request->input('location') : null;

        if (!app(LocationCatalog::class)->isValid($location, $user)) {
            return response()->json(['error' => 'invalid_location'], 422);
        }

        // Plan resolved server-side (or advanced raw numbers), mirroring save(). createServer()
        // clamps/authorises either way.
        if ((bool) $request->input('advanced', false)) {
            $cpu = (int) $request->input('cpu', 0);
            $memory = (int) $request->input('memory', 0);
            $disk = (int) $request->input('disk', 0);
        } else {
            $plan = app(PlanCatalog::class)->find(
                $user,
                is_string($request->input('plan')) ? $request->input('plan') : null,
                $resourceLimits,
            );

            if (!$plan) {
                return response()->json(['error' => 'no_plan_selected'], 422);
            }

            $cpu = $plan['cpu'];
            $memory = $plan['memory'];
            $disk = $plan['disk'];
        }

        try {
            $server = $resourceLimits->createServer(
                $user,
                (string) $request->input('name'),
                $egg,
                $cpu,
                $memory,
                $disk,
                $variables,
                $location,
                $runtime['startup'],
                $runtime['image'],
            );
        } catch (NoViableNodeException) {
            return response()->json(['error' => 'no_viable_node'], 503);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'server_creation_failed'], 500);
        }

        if (!$server) {
            // createServer() returns false for an exceeded limit or a locked premium location.
            return response()->json(['error' => 'resource_limit_exceeded'], 422);
        }

        if ($types !== null) {
            // ServerOnboarding lives in plugged-host-minecraft; guarded so server creation still
            // works if minecraft is absent (the wizard just won't be forced). Same reach the
            // create page makes, by class-string here so servers doesn't hard-depend on it.
            if (class_exists(\PluggedHost\Minecraft\Models\ServerOnboarding::class)) {
                \PluggedHost\Minecraft\Models\ServerOnboarding::markRequired($server);
            }

            try {
                app(DaemonFileRepository::class)->setServer($server)->putContent('eula.txt', "eula=true\n");
            } catch (Throwable) {
                // Also written on the ServerInstalled event.
            }
        }

        return response()->json(['data' => [
            'uuid' => $server->uuid,
            'identifier' => $server->uuid_short ?? null,
            'name' => $server->name,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'location' => $location,
        ]], 201);
    }
}
