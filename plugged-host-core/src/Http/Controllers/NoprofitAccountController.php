<?php

namespace PluggedHost\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\NoprofitLink;

/**
 * The panel half of converting a plugged.host account into a noprofit.space account.
 *
 * noprofit.space calls these (signed, see VerifyNoprofitSignature) right after a
 * password-proven migration, or on the next sign-in for accounts linked before conversions
 * existed. It is a two-step hand-over so there is never a moment where neither side holds a
 * usable password:
 *
 *   1. export  returns the panel's password hash. Nothing changes here yet.
 *   2. commit  runs once noprofit.space has stored that hash (or already had a password of
 *              its own): the panel links the account, locks its sign-in details and deletes
 *              its password. If commit never arrives, the account simply stays unconverted
 *              and the next attempt repeats both steps.
 *
 * Either step is only answered for an account noprofit.space provably owns: one already
 * linked to that noprofit.space account, or one whose migration token this panel issued
 * within the last migration_ttl seconds (the nonce recorded by NoprofitSsoController).
 */
final class NoprofitAccountController
{
    public const ISSUED_MIGRATION_KEY = 'plugged-host:migration-issued:';

    public function export(Request $request): JsonResponse
    {
        [$user, $accountId, $error] = $this->resolve($request);
        if ($error !== null) {
            return $error;
        }

        if (NoprofitLink::isConverted($user)) {
            return response()->json(['error' => 'already_converted'], 409);
        }

        $hash = (string) $user->password;

        return response()->json([
            'password_hash' => password_get_info($hash)['algo'] !== null ? $hash : null,
        ]);
    }

    public function commit(Request $request): JsonResponse
    {
        [$user, $accountId, $error] = $this->resolve($request);
        if ($error !== null) {
            return $error;
        }

        if (!NoprofitLink::isConverted($user)) {
            NoprofitLink::convert($user, $accountId);

            Log::info('plugged.host account converted to noprofit.space', [
                'user_id' => $user->getKey(),
                'noprofit_account_id' => $accountId,
            ]);
        }

        $nonce = $request->input('migration_nonce');
        if (is_string($nonce) && $nonce !== '') {
            Cache::forget(self::ISSUED_MIGRATION_KEY . $nonce);
        }

        return response()->json(['converted' => true]);
    }

    /**
     * @return array{0: ?User, 1: int, 2: ?JsonResponse}
     */
    private function resolve(Request $request): array
    {
        $pelicanUserId = (int) $request->input('pelican_user_id');
        $accountId = (int) $request->input('account_id');
        $nonce = $request->input('migration_nonce');

        $user = $pelicanUserId > 0 ? User::query()->find($pelicanUserId) : null;
        if ($user === null || $accountId < 1) {
            return [null, 0, response()->json(['error' => 'not_found'], 404)];
        }

        $linkedAccount = NoprofitLink::accountId($user);
        $issuedFor = is_string($nonce) && $nonce !== '' ? Cache::get(self::ISSUED_MIGRATION_KEY . $nonce) : null;

        $owned = $linkedAccount === $accountId
            || ($linkedAccount === null && (int) $issuedFor === (int) $user->getKey());

        if (!$owned) {
            return [null, 0, response()->json(['error' => 'forbidden'], 403)];
        }

        return [$user, $accountId, null];
    }
}
