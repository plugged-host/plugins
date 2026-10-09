<?php

namespace PluggedHost\Economy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PluggedHost\Economy\Services\NoprofitCreditSync;

/**
 * noprofit.space pushes each account's state here after changing it (signed with the sync
 * secret): balance, bought resources and the plan the panel enforces, plus the store prices and
 * the compute tiers.
 */
final class NoprofitCreditsController
{
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Empty when noprofit.space only sends new prices (Admin > Settings saved).
            'items' => ['present', 'array', 'max:500'],
            'items.*.account_id' => ['required', 'integer', 'min:1'],
            'items.*.balance' => ['required', 'integer', 'min:0'],
            'items.*.version' => ['required', 'integer', 'min:0'],
            'items.*.extra_cpu' => ['nullable', 'integer', 'min:0'],
            'items.*.extra_memory' => ['nullable', 'integer', 'min:0'],
            'items.*.extra_disk' => ['nullable', 'integer', 'min:0'],
            'items.*.plan' => ['nullable', 'array'],
            'items.*.plan.*' => ['integer', 'min:0'],
            'pricing' => ['nullable', 'array'],
            'pricing.*' => ['integer', 'min:0'],
            'tiers' => ['nullable', 'array'],
            'tiers.*' => ['array'],
            'tiers.*.*' => ['integer', 'min:0'],
        ]);

        return response()->json(['applied' => NoprofitCreditSync::applyBalances($data['items'], $data['pricing'] ?? null, $data['tiers'] ?? null)]);
    }
}
