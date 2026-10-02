<?php

namespace PluggedHost\Servers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Http\Requests\CreateServerRequest;
use PluggedHost\Servers\Services\ServerProvisioner;

/**
 * POST /api/client/provision/servers (WP7) — create a server through the client API.
 *
 * All rules (allowance, game, location incl. paid-tier unlock, plan resolution, onboarding and
 * EULA) live in ServerProvisioner, shared with the create page and plugged-host-ai; this only
 * maps the request onto it and its reason codes onto the API's existing error codes.
 */
class CreateServerController
{
    public function __invoke(CreateServerRequest $request, ServerProvisioner $provisioner): JsonResponse
    {
        $user = $request->user();

        $input = [
            'name' => (string) $request->input('name'),
            'game' => $request->input('game'),
            'location' => is_string($request->input('location')) ? $request->input('location') : null,
        ];

        if ((bool) $request->input('advanced', false)) {
            $input += ['cpu' => (int) $request->input('cpu', 0), 'memory' => (int) $request->input('memory', 0), 'disk' => (int) $request->input('disk', 0)];
        } else {
            $input['plan'] = is_string($request->input('plan')) ? $request->input('plan') : null;

            if ($input['plan'] === null || $input['plan'] === '') {
                return response()->json(['error' => 'no_plan_selected'], 422);
            }
        }

        // User variables come off the request, keyed by env name; createServer() only keeps the
        // ones the egg marks user-editable.
        $variables = [];
        foreach ((array) $request->input('variables', []) as $envKey => $value) {
            if (is_string($envKey)) {
                $variables[$envKey] = is_scalar($value) ? (string) $value : null;
            }
        }

        try {
            $server = $provisioner->create($user, $input, $variables);
        } catch (ProvisioningException $e) {
            [$error, $status] = match ($e->reason) {
                'no_resource_limits' => ['no_resource_limits', 403],
                'unknown_game' => ['unknown_game', 422],
                'unknown_plan' => ['no_plan_selected', 422],
                'invalid_location', 'location_locked', 'location_unavailable' => ['invalid_location', 422],
                'name_required', 'name_too_long' => ['invalid_name', 422],
                'no_viable_node', 'no_viable_allocation' => ['no_viable_node', 503],
                'creation_failed' => ['server_creation_failed', 500],
                default => ['resource_limit_exceeded', 422],
            };

            return response()->json(['error' => $error], $status);
        }

        return response()->json(['data' => [
            'uuid' => $server->uuid,
            'identifier' => $server->uuid_short ?? null,
            'name' => $server->name,
            'cpu' => $server->cpu,
            'memory' => $server->memory,
            'disk' => $server->disk,
            'location' => $input['location'],
        ]], 201);
    }
}
