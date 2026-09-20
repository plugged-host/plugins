<?php

namespace PluggedHost\Servers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/client/provision/servers (WP7).
 *
 * Deliberately thin: it validates only the *shape* of what the caller sends. Every value that
 * decides what the server actually gets — the plan, the location's validity, the egg's type
 * variant and runtime — is resolved server-side in CreateServerController against the caller's
 * RoleResourceLimits, exactly as ConfigureServerPage::save() does. That is what stops a payload
 * from asking for a plan it may not have or a premium location it has not unlocked; there is no
 * point re-encoding those rules here where they could drift from the page.
 *
 * `advanced` mode mirrors the page's advanced toggle: raw cpu/memory/disk instead of a plan. The
 * controller still clamps those to the caller's limits, so the numbers here are a request, not a
 * grant.
 */
class CreateServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The client-api middleware has already resolved the API key to a user; per-plan and
        // per-location authorisation is enforced in the controller against RoleResourceLimits.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'game' => ['required', 'string'],           // egg id or uuid — validated via GameCatalog::find
            'location' => ['nullable', 'string'],
            'advanced' => ['sometimes', 'boolean'],
            'plan' => ['required_without:advanced', 'nullable', 'string'],
            'cpu' => ['required_if:advanced,true', 'nullable', 'integer', 'min:0'],
            'memory' => ['required_if:advanced,true', 'nullable', 'integer', 'min:0'],
            'disk' => ['required_if:advanced,true', 'nullable', 'integer', 'min:0'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['nullable', 'string'],
        ];
    }
}
