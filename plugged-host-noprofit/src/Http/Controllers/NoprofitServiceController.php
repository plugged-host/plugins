<?php

namespace PluggedHost\Noprofit\Http\Controllers;

use App\Exceptions\DisplayException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Services\AccountDeletionService;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Jobs\SyncDiscordMembershipJob;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Noprofit\Services\NoprofitAccountSync;
use PluggedHost\Noprofit\Support\NoprofitLink;
use PluggedHost\Noprofit\Support\NoprofitMigrationToken;
use Throwable;

/**
 * Signed calls noprofit.space makes about a linked account (VerifyNoprofitSignature):
 *
 *  - account/summary, account/delete (migration secret): noprofit.space is where people delete
 *    their account; deleting it deletes the linked panel account and its servers here.
 *  - discord/resolve (migration secret): someone signed in to noprofit.space with Discord.
 *    If a panel account has that Discord account linked (proven with Discord OAuth when it was
 *    linked, discord_id is unique), it is moved over just as if its password had been entered
 *    on /migrate. Admin accounts are excluded: they still have to prove the panel password once.
 *  - discord/link (sync secret): Discord is linked and unlinked on noprofit.space now; the
 *    panel keeps a copy for its Discord roles and rewards.
 */
final class NoprofitServiceController
{
    public function summary(Request $request): JsonResponse
    {
        $user = NoprofitLink::userFor((int) $request->input('account_id'));
        if ($user === null) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'username' => $user->username,
            'servers' => $user->servers()->orderBy('name')->pluck('name')->all(),
        ]);
    }

    public function deleteAccount(Request $request, AccountDeletionService $deletion): JsonResponse
    {
        $accountId = (int) $request->input('account_id');
        $user = NoprofitLink::userFor($accountId);

        // Nothing linked (never used the panel, or already deleted): nothing to do here.
        if ($user === null) {
            return response()->json(['deleted' => true, 'servers' => 0]);
        }

        try {
            $user->setRememberToken(null);
            $servers = $deletion->handle($user, withServers: true);
        } catch (DisplayException $exception) {
            return response()->json(['error' => 'refused', 'message' => $exception->getMessage()], 409);
        }

        Log::info('plugged.host account deleted from noprofit.space', [
            'user_id' => $user->getKey(),
            'noprofit_account_id' => $accountId,
            'servers' => $servers,
        ]);

        return response()->json(['deleted' => true, 'servers' => $servers]);
    }

    public function resolveDiscord(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discord_id' => ['required', 'string', 'regex:/^\d{5,25}$/'],
            'state' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32,128}$/'],
        ]);

        $user = Plugins::available(DiscordLink::class)
            ? DiscordLink::query()->where('discord_id', $data['discord_id'])->first()?->user
            : null;

        if (!$user instanceof User) {
            return response()->json(['match' => 'none']);
        }

        if (($accountId = NoprofitLink::accountId($user)) !== null) {
            return response()->json(['match' => 'linked', 'account_id' => $accountId]);
        }

        if ($user->isAdmin()) {
            return response()->json(['match' => 'password_required']);
        }

        return response()->json([
            'match' => 'migrate',
            'token' => NoprofitMigrationToken::issue($user, $data['state']),
        ]);
    }

    public function linkDiscord(Request $request, DiscordVerificationService $discord): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'discord' => ['nullable', 'array'],
            'discord.discord_id' => ['required_with:discord', 'string', 'regex:/^\d{5,25}$/'],
            'discord.username' => ['nullable', 'string', 'max:255'],
            'discord.avatar' => ['nullable', 'string', 'max:255'],
            'discord.access_token' => ['required_with:discord', 'string', 'max:2048'],
            'discord.refresh_token' => ['nullable', 'string', 'max:2048'],
            'discord.expires_in' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = NoprofitLink::userFor((int) $data['account_id']);
        if ($user === null) {
            return response()->json(['linked' => false, 'reason' => 'not_found'], 404);
        }

        $link = $data['discord'] ?? null;

        try {
            if ($link === null) {
                $discord->unlinkUser($user);

                return response()->json(['linked' => false, 'snapshot' => $this->snapshot($user)]);
            }

            // noprofit.space just proved this Discord account with OAuth, so it moves here from
            // any other panel account still holding it (discord_id is unique).
            $holderLink = DiscordLink::query()->where('discord_id', $link['discord_id'])->where('user_id', '!=', $user->getKey())->first();
            $holder = $holderLink?->user;
            if ($holder instanceof User) {
                $discord->unlinkUser($holder);
            }

            $oauth = is_array($user->oauth) ? $user->oauth : [];
            $oauth['discord'] = $link['discord_id'];
            $user->forceFill(['oauth' => $oauth])->saveQuietly();

            $discord->saveOAuthCredentials(
                $user,
                $link['discord_id'],
                $link['access_token'],
                $link['refresh_token'] ?? null,
                isset($link['expires_in']) ? (int) $link['expires_in'] : null,
                ['username' => $link['username'] ?? null, 'discriminator' => '0', 'avatar' => $link['avatar'] ?? null],
            );

            // Boost tiers already paid to this Discord account, and invite debt it owes, follow it:
            // they were kept per panel account, so moving the link to a fresh account paid the
            // boost reward again and left the debt behind. Moved before the sync below pays.
            if ($holderLink instanceof DiscordLink) {
                $moved = DiscordLink::query()->where('user_id', $user->getKey())->first();
                $moved?->forceFill([
                    'boosts_rewarded' => max((int) $moved->boosts_rewarded, (int) $holderLink->boosts_rewarded),
                    'invite_debt' => (int) $moved->invite_debt + (int) $holderLink->invite_debt,
                ])->save();
                $holderLink->forceFill(['boosts_rewarded' => 0, 'invite_debt' => 0])->save();
            }

            // Checked right away, not behind the queue: server membership gives the Discord
            // role here and the Discord tier on noprofit.space, which reads it from the answer.
            SyncDiscordMembershipJob::dispatchSync($user->fresh());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'failed'], 500);
        }

        return response()->json(['linked' => true, 'snapshot' => $this->snapshot($user)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(User $user): ?array
    {
        $snapshot = NoprofitAccountSync::snapshotFor($user->fresh());

        if ($snapshot !== null) {
            NoprofitAccountSync::remember($snapshot);
        }

        return $snapshot;
    }
}
