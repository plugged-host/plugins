<?php

namespace PluggedHost\Discord\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Contracts\DirectoryUnavailable;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;
use PluggedHost\Discord\Models\DiscordGiveaway;
use PluggedHost\Discord\Models\DiscordGiveawayRecipient;
use PluggedHost\Discord\Models\DiscordLink;
use Throwable;

/**
 * The panel pays Discord users itself: credits through plugged-host-economy, the record in
 * discord_giveaways / discord_giveaway_recipients. A winner without a linked Discord stays
 * pending and is paid when they link (DiscordPayoutsServiceProvider). Without economy every
 * winner stays pending.
 */
final class LocalDiscordCreditPayouts implements DiscordCreditPayouts
{
    private const USER_CREDITS = 'PluggedHost\\Economy\\Models\\UserCredits';

    private const CREDIT_TRANSACTION = 'PluggedHost\\Economy\\Models\\CreditTransaction';

    public function configured(): bool
    {
        return true;
    }

    public function recentGiveaways(int $limit): array
    {
        try {
            return DiscordGiveaway::query()
                ->latest('id')
                ->limit(max(0, $limit))
                ->get()
                ->map(fn (DiscordGiveaway $giveaway): array => [
                    'id' => (int) $giveaway->getKey(),
                    'prize' => (string) $giveaway->prize,
                    'amount' => (int) $giveaway->amount,
                    'created_at' => $giveaway->created_at?->toIso8601String(),
                    'winners' => DiscordGiveawayRecipient::query()
                        ->where('giveaway_id', $giveaway->getKey())
                        ->pluck('discord_id')
                        ->map(fn ($id) => (string) $id)
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            throw new DirectoryUnavailable('Giveaways could not be read: ' . class_basename($e), 0, $e);
        }
    }

    public function payGiveaway(string $key, string $prize, int $amount, array $discordIds, ?int $createdBy): array
    {
        return $this->locked("discord-payout:giveaway:$key", function () use ($key, $prize, $amount, $discordIds, $createdBy): array {
            $giveaway = DiscordGiveaway::query()->where('source_key', $key)->first();
            $created = $giveaway === null;

            if ($created) {
                $giveaway = DiscordGiveaway::query()->forceCreate([
                    'prize' => mb_substr($prize, 0, 120),
                    'currency' => 'credits',
                    'amount' => max(0, $amount),
                    'created_by' => $createdBy !== null && User::query()->whereKey($createdBy)->exists() ? $createdBy : null,
                    'source_key' => $key,
                ]);
            }

            $recipients = [];

            foreach (array_values(array_unique(array_map('strval', $discordIds))) as $discordId) {
                $recipient = DiscordGiveawayRecipient::query()->firstOrCreate(
                    ['giveaway_id' => $giveaway->getKey(), 'discord_id' => $discordId],
                    ['status' => 'pending'],
                );

                if ($recipient->status !== 'paid') {
                    $this->pay($giveaway, $recipient);
                }

                $recipients[$discordId] = ['status' => $recipient->status === 'paid' ? 'paid' : 'pending', 'account_id' => $recipient->user_id];
            }

            return ['giveaway_id' => (int) $giveaway->getKey(), 'created' => $created, 'recipients' => $recipients];
        });
    }

    public function grantCredits(string $key, string $discordId, int $amount, ?string $reason, int $grantedBy): array
    {
        $granter = User::query()->find($grantedBy);

        if (!$granter instanceof User || !$granter->isRootAdmin()) {
            throw new InvalidArgumentException('The one giving credits is not an admin on this panel.');
        }

        $user = DiscordLink::query()->where('discord_id', $discordId)->first()?->user;

        if (!$user instanceof User) {
            throw new InvalidArgumentException("<@{$discordId}> has not linked Discord to a panel account, so there is no account to give credits to.");
        }

        if (!Plugins::available(self::USER_CREDITS)) {
            throw new InvalidArgumentException('Credits are not enabled on this panel (plugged-host-economy).');
        }

        return $this->locked("discord-payout:grant:$key", function () use ($key, $user, $amount, $reason, $grantedBy): array {
            $existing = (self::CREDIT_TRANSACTION)::query()
                ->where('user_id', $user->getKey())
                ->where('metadata->grant_key', $key)
                ->first();

            $transaction = $existing ?? (self::USER_CREDITS)::forUser($user)->addCredits(
                $amount,
                'admin_adjustment',
                $reason !== null && $reason !== '' ? $reason : 'Credits from an admin',
                ['grant_key' => $key, 'granted_by' => $grantedBy, 'via' => 'discord'],
            );

            return [
                'account_id' => (int) $user->getKey(),
                'name' => (string) $user->username,
                'amount' => (int) $transaction->amount,
                'balance_after' => (int) $transaction->balance_after,
                'created' => $existing === null,
            ];
        });
    }

    /**
     * Pay what is still pending for this Discord account (called once it is linked).
     */
    public function payPending(DiscordLink $link): int
    {
        $paid = 0;

        DiscordGiveawayRecipient::query()
            ->where('discord_id', (string) $link->discord_id)
            ->where('status', 'pending')
            ->get()
            ->each(function (DiscordGiveawayRecipient $recipient) use (&$paid): void {
                $giveaway = DiscordGiveaway::query()->find($recipient->giveaway_id);

                if ($giveaway !== null && $this->locked("discord-payout:recipient:{$recipient->getKey()}", fn () => $this->pay($giveaway, $recipient->fresh()))) {
                    $paid++;
                }
            });

        return $paid;
    }

    /**
     * Pay one recipient if they are linked and economy is there; leaves them pending otherwise.
     */
    private function pay(DiscordGiveaway $giveaway, DiscordGiveawayRecipient $recipient): bool
    {
        if ($recipient->status === 'paid') {
            return false;
        }

        $user = DiscordLink::query()->where('discord_id', $recipient->discord_id)->first()?->user;

        if (!$user instanceof User || !Plugins::available(self::USER_CREDITS) || (int) $giveaway->amount <= 0) {
            return false;
        }

        try {
            (self::USER_CREDITS)::forUser($user)->addCredits(
                (int) $giveaway->amount,
                'giveaway',
                'Giveaway: ' . $giveaway->prize,
                ['giveaway_id' => (int) $giveaway->getKey(), 'key' => $giveaway->source_key],
            );

            $recipient->forceFill(['status' => 'paid', 'user_id' => $user->getKey(), 'paid_at' => now(), 'error' => null])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('[Discord] paying a giveaway winner failed', ['giveaway' => $giveaway->getKey(), 'discord_id' => $recipient->discord_id, 'error' => $e->getMessage()]);
            $recipient->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return false;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(string $key, callable $callback): mixed
    {
        try {
            return Cache::lock($key, 30)->block(10, $callback);
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }

            throw new DirectoryUnavailable('The payout could not be recorded: ' . class_basename($e), 0, $e);
        }
    }
}
