<?php

namespace PluggedHost\Economy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PluggedHost\Economy\Services\NoprofitCreditSync;

/**
 * noprofit.space pushes its balances here after changing them (signed with the sync secret).
 */
final class NoprofitCreditsController
{
    public function sync(Request $request): JsonResponse
    {
        $items = $request->validate([
            'items' => ['required', 'array', 'max:500'],
            'items.*.account_id' => ['required', 'integer', 'min:1'],
            'items.*.balance' => ['required', 'integer', 'min:0'],
            'items.*.version' => ['required', 'integer', 'min:0'],
        ])['items'];

        return response()->json(['applied' => NoprofitCreditSync::applyBalances($items)]);
    }
}
