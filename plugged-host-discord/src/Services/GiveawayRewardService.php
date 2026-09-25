<?php

namespace PluggedHost\Discord\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Discord\Models\DiscordGiveaway;
use PluggedHost\Discord\Models\DiscordGiveawayRecipient;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\TokenService;
use RuntimeException;
use Throwable;

/**
 * Pays a giveaway's winners, identified by Discord user id, in credits or tokens.
 *
 * Winners are named by Discord id because that is what a giveaway on Discord produces.
 * Each id is resolved to a panel account through its `discord_links` row, falling back
 * to `users.oauth->discord` for an account that signed in with Discord but has not had
 * its link row built yet. An id that resolves to nobody is recorded as `unlinked` and
 * paid on a later retry, once the winner has linked.
 *
 * Every payment goes through plugged-host-economy's ledger — `UserCredits::addCredits()`
 * or `TokenService::add()` — inside the same transaction that locks the recipient row
 * and marks it `paid`. The lock is what makes pay() safe to run twice: a second run (or
 * a second admin pressing retry at the same moment) waits, sees `paid`, and skips.
 */
class GiveawayRewardService
{
    public const LEDGER_TYPE = 'giveaway';

    /**
     * Both balance columns are unsigned int; a billion per winner is far past any real
     * prize and keeps a typo a validation message instead of an overflow.
     */
    public const MAX_AMOUNT = 1000000000;

    /** Keeps one form submission's payout loop comfortably inside a request. */
    public const MAX_RECIPIENTS = 500;

    protected static ?bool $tablesExist = null;

    /**
     * Whether giveaways can run at all: the economy plugin that owns the ledgers is
     * installed, and this plugin's migration has been run.
     */
    public function isAvailable(): bool
    {
        if (!class_exists(UserCredits::class) || !class_exists(TokenService::class)) {
            return false;
        }

        try {
            return self::$tablesExist ??= Schema::hasTable('discord_giveaways')
                && Schema::hasTable('discord_giveaway_recipients');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The currencies that can be given away right now, keyed by the stored value.
     *
     * @return array<string, string>
     */
    public function currencies(): array
    {
        $currencies = [];

        if ((bool) config('plugged-host-economy.credits.enabled', true)) {
            $currencies[DiscordGiveaway::CURRENCY_CREDITS] = trans('plugged-host-discord::strings.giveaways.currency_credits');
        }

        if (app(TokenService::class)->isEnabled()) {
            $currencies[DiscordGiveaway::CURRENCY_TOKENS] = trans('plugged-host-discord::strings.giveaways.currency_tokens');
        }

        return $currencies;
    }

    /**
     * Pull Discord user ids out of whatever was pasted.
     *
     * Accepts ids separated by newlines, spaces, commas or semicolons, and mentions
     * (`<@123…>` / `<@!123…>`) as copied out of a Discord message. Anything else is
     * returned as invalid rather than dropped, so a typo stops the form instead of
     * silently leaving a winner unpaid.
     *
     * @return array{ids: list<string>, invalid: list<string>}
     */
    public function parseDiscordIds(string $raw): array
    {
        $ids = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $id = preg_match('/^<@!?(\d+)>$/', $token, $match) ? $match[1] : $token;

            if (preg_match('/^\d{17,20}$/', $id)) {
                $ids[$id] = $id;
            } else {
                $invalid[$token] = $token;
            }
        }

        return ['ids' => array_values($ids), 'invalid' => array_values($invalid)];
    }

    /**
     * Record a giveaway and pay every winner that can be paid.
     *
     * @param  list<string>  $discordIds
     *
     * @throws InvalidArgumentException when the input would not survive the form's own validation
     */
    public function create(string $prize, string $currency, int $amount, array $discordIds, ?User $admin): DiscordGiveaway
    {
        $prize = trim($prize);

        if ($prize === '' || mb_strlen($prize) > 120) {
            throw new InvalidArgumentException('A giveaway needs a prize of at most 120 characters.');
        }

        if (!array_key_exists($currency, $this->currencies())) {
            throw new InvalidArgumentException("Currency [{$currency}] is not available.");
        }

        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('Giveaway amount is out of range.');
        }

        $discordIds = array_values(array_unique($discordIds));

        if ($discordIds === [] || count($discordIds) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException('A giveaway needs between 1 and ' . self::MAX_RECIPIENTS . ' winners.');
        }

        $giveaway = DB::transaction(function () use ($prize, $currency, $amount, $discordIds, $admin): DiscordGiveaway {
            $giveaway = DiscordGiveaway::create([
                'prize' => $prize,
                'currency' => $currency,
                'amount' => $amount,
                'created_by' => $admin?->id,
            ]);

            foreach ($discordIds as $discordId) {
                $giveaway->recipients()->create([
                    'discord_id' => $discordId,
                    'status' => DiscordGiveawayRecipient::STATUS_PENDING,
                ]);
            }

            return $giveaway;
        });

        $this->pay($giveaway, $admin);

        return $giveaway;
    }

    /**
     * Pay every winner of the giveaway who has not been paid yet.
     *
     * @return array{paid: int, unlinked: int, failed: int}
     */
    public function pay(DiscordGiveaway $giveaway, ?User $admin): array
    {
        $counts = [
            DiscordGiveawayRecipient::STATUS_PAID => 0,
            DiscordGiveawayRecipient::STATUS_UNLINKED => 0,
            DiscordGiveawayRecipient::STATUS_FAILED => 0,
        ];

        $unpaid = $giveaway->recipients()
            ->where('status', '!=', DiscordGiveawayRecipient::STATUS_PAID)
            ->orderBy('id')
            ->pluck('id');

        foreach ($unpaid as $recipientId) {
            $outcome = $this->payRecipient($giveaway, (int) $recipientId, $admin);

            if ($outcome !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /**
     * Resolve the panel account behind a Discord id, or null if nobody has linked it.
     */
    public function resolveUser(string $discordId): ?User
    {
        $link = DiscordLink::query()->where('discord_id', $discordId)->with('user')->first();

        if ($link?->user instanceof User) {
            return $link->user;
        }

        return User::query()->where('oauth->discord', $discordId)->first();
    }

    /**
     * One winner, in one transaction: lock the row, resolve, pay, mark paid.
     *
     * Returns the outcome, or null when another run had already paid this winner.
     */
    protected function payRecipient(DiscordGiveaway $giveaway, int $recipientId, ?User $admin): ?string
    {
        try {
            return DB::transaction(function () use ($giveaway, $recipientId, $admin): ?string {
                /** @var ?DiscordGiveawayRecipient $recipient */
                $recipient = DiscordGiveawayRecipient::query()->lockForUpdate()->find($recipientId);

                if (!$recipient || $recipient->status === DiscordGiveawayRecipient::STATUS_PAID) {
                    return null;
                }

                $user = $this->resolveUser($recipient->discord_id);

                if (!$user) {
                    $recipient->update([
                        'status' => DiscordGiveawayRecipient::STATUS_UNLINKED,
                        'user_id' => null,
                        'error' => null,
                    ]);

                    return DiscordGiveawayRecipient::STATUS_UNLINKED;
                }

                $this->grant($giveaway, $user, $recipient, $admin);

                $recipient->update([
                    'status' => DiscordGiveawayRecipient::STATUS_PAID,
                    'user_id' => $user->id,
                    'error' => null,
                    'paid_at' => now(),
                ]);

                return DiscordGiveawayRecipient::STATUS_PAID;
            });
        } catch (Throwable $e) {
            Log::warning('plugged-host-discord: giveaway payout failed', [
                'giveaway_id' => $giveaway->id,
                'recipient_id' => $recipientId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            DiscordGiveawayRecipient::query()
                ->whereKey($recipientId)
                ->where('status', '!=', DiscordGiveawayRecipient::STATUS_PAID)
                ->update([
                    'status' => DiscordGiveawayRecipient::STATUS_FAILED,
                    'error' => Str::limit($e->getMessage(), 250),
                ]);

            return DiscordGiveawayRecipient::STATUS_FAILED;
        }
    }

    /**
     * Post the prize to the winner's ledger. Throws on any failure so the caller's
     * transaction rolls the recipient row back with it.
     */
    protected function grant(DiscordGiveaway $giveaway, User $user, DiscordGiveawayRecipient $recipient, ?User $admin): void
    {
        // `description` is a plain string(255) column, and it is what the winner sees
        // in their transaction list.
        $description = Str::limit(trans('plugged-host-discord::strings.giveaways.tx_description', [
            'prize' => $giveaway->prize,
        ]), 250);

        $metadata = array_filter([
            'giveaway_id' => $giveaway->id,
            'discord_id' => $recipient->discord_id,
            'admin_id' => $admin?->id,
            'admin' => $admin?->username,
        ], fn ($value): bool => $value !== null);

        if ($giveaway->currency === DiscordGiveaway::CURRENCY_CREDITS) {
            $credits = UserCredits::forUser($user);

            if (!$credits instanceof UserCredits) {
                throw new RuntimeException('Credit ledger is unavailable.');
            }

            $credits->addCredits($giveaway->amount, self::LEDGER_TYPE, $description, $metadata);

            return;
        }

        if ($giveaway->currency === DiscordGiveaway::CURRENCY_TOKENS) {
            // TokenService reports failure as null rather than throwing.
            if (app(TokenService::class)->add($user, $giveaway->amount, self::LEDGER_TYPE, $description, $metadata) === null) {
                throw new RuntimeException('Token ledger is unavailable.');
            }

            return;
        }

        throw new RuntimeException("Unknown giveaway currency [{$giveaway->currency}].");
    }
}
