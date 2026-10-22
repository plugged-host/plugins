<?php

namespace PluggedHost\Ai\Rux\Giveaways;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Rux\Discord\Resolver;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Models\RuxGiveawayEntry;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Discord\Contracts\DirectoryUnavailable;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;
use Throwable;

/**
 * Credit giveaways on Discord.
 *
 * Entering always needs a Discord account linked to noprofit.space, and noprofit.space is asked
 * (the panel's own copy of the links still holds pre-2.1 ones). A
 * giveaway can also keep out the winners of the last N giveaways; which giveaways those were is
 * fixed when it is created, so the rule the admin read back is the rule the draw applies.
 *
 * At ends_at the giveaway is claimed by exactly one process (status active → drawing), winners
 * are drawn among entries that are still linked and still in the server, and noprofit.space pays
 * them under the key "rux:<id>", which makes a retried payout pay nobody twice.
 *
 * A giveaway belongs to the server it was posted on (guild_id): only that server's members can
 * enter it and win it. Its prize is always credits; its title names the occasion and can never
 * promise anything else (OTHER_PRIZES), because credits are all rux gives.
 */
class GiveawayService
{
    private const ACCENT = 0x1D4ED8;

    /**
     * Prizes rux cannot give, as a title might promise them ("1 month of Plus", "500 tokens",
     * "8 GB RAM", "Nitro", "VIP role").
     */
    public const OTHER_PRIZES = '/\b(?:plus(?:plus)?|premium|vip|nitro|(?:cloud\s*)?tokens?|ram|cpus?|v?cores?|disk|ssd|\d+\s*(?:gb|mb|tb)|gb|tb|roles?|ranks?|subscriptions?)\b/iu';

    public function __construct(
        private readonly RuxDiscord $discord,
        private readonly DiscordAccounts $accounts,
        private readonly DiscordCreditPayouts $payouts,
        private readonly Resolver $resolver,
    ) {}

    // --- creating and changing ------------------------------------------------

    /**
     * @param  array{credits: int, ends_at: string, winners?: int|null, channel?: string|null, exclude_recent_winners?: int|null, title?: string|null}  $input
     */
    public function create(RuxContext $context, array $input): RuxGiveaway
    {
        $limits = (array) config('plugged-host-ai.rux.giveaway', []);
        $credits = (int) $input['credits'];
        $winners = (int) ($input['winners'] ?? 1);
        $exclude = (int) ($input['exclude_recent_winners'] ?? 0);
        $now = RuxConfig::now();
        $endsAt = RuxTime::parse((string) $input['ends_at'], $now);

        $this->validate($credits, $winners, $endsAt, $now, $limits);
        $title = self::creditsOnlyTitle((string) ($input['title'] ?? ''));

        if ($exclude < 0 || $exclude > (int) ($limits['max_exclude_recent'] ?? 10)) {
            throw new InvalidArgumentException('exclude_recent_winners must be between 0 and ' . (int) ($limits['max_exclude_recent'] ?? 10) . '.');
        }

        $channelInput = $input['channel'] ?? null;
        $channelInput = is_string($channelInput) && trim($channelInput) !== '' ? $channelInput : (string) RuxConfig::guild($context->guildId)?->giveawayChannelId;

        if ($channelInput === '') {
            throw new InvalidArgumentException('No channel given and no default giveaway channel is set. Ask the admin which channel to post it in.');
        }

        $channel = $this->resolver->channel($context->guildId, $channelInput);

        // Fixed now: "the last 2 giveaways" means the last 2 at the moment the admin asked.
        $excluded = null;
        if ($exclude > 0) {
            try {
                $recent = $this->payouts->recentGiveaways($exclude);
            } catch (DirectoryUnavailable $e) {
                throw new InvalidArgumentException('Cannot look up the last giveaways\' winners right now (' . $e->getMessage() . '), so the giveaway was not created. Tell the admin to try again in a minute.');
            }

            $excluded = [
                'giveaways' => array_map(static fn (array $g): array => ['id' => $g['id'], 'prize' => $g['prize'], 'at' => $g['created_at'], 'winners' => count($g['winners'])], $recent),
                'discord_ids' => array_values(array_unique(array_merge(...array_map(static fn (array $g): array => $g['winners'], $recent ?: [['winners' => []]])))),
            ];
        }

        $giveaway = RuxGiveaway::query()->create([
            'guild_id' => $context->guildId,
            'channel_id' => (string) $channel['id'],
            'prize' => mb_substr($title !== '' ? $title : number_format($credits) . ' credits', 0, 120),
            'credits' => $credits,
            'winners_count' => $winners,
            'ends_at' => $endsAt->setTimezone((string) config('app.timezone')),
            'exclude_recent' => $exclude,
            'excluded' => $excluded,
            'status' => RuxGiveaway::ACTIVE,
            'created_by_discord_id' => $context->actorDiscordId,
            'created_by_account_id' => $context->actorAccountId,
        ]);

        try {
            $message = $this->discord->createMessage($giveaway->channel_id, $this->render($giveaway));
        } catch (Throwable $e) {
            $giveaway->delete();

            throw $e;
        }

        $giveaway->update(['message_id' => (string) $message['id']]);

        return $giveaway;
    }

    /**
     * @param  array{credits?: int|null, ends_at?: string|null, winners?: int|null, title?: string|null}  $changes
     */
    public function update(RuxGiveaway $giveaway, array $changes): RuxGiveaway
    {
        if (!$giveaway->isOpen()) {
            throw new InvalidArgumentException("Giveaway #{$giveaway->id} is {$giveaway->status}; only a running giveaway can be changed.");
        }

        $now = RuxConfig::now();
        $credits = isset($changes['credits']) ? (int) $changes['credits'] : $giveaway->credits;
        $winners = isset($changes['winners']) ? (int) $changes['winners'] : $giveaway->winners_count;
        $endsAt = isset($changes['ends_at']) && $changes['ends_at'] !== '' ? RuxTime::parse((string) $changes['ends_at'], $now) : CarbonImmutable::instance($giveaway->ends_at);

        $this->validate($credits, $winners, $endsAt, $now, (array) config('plugged-host-ai.rux.giveaway', []));

        $autoTitle = $giveaway->prize === number_format($giveaway->credits) . ' credits';
        $title = self::creditsOnlyTitle((string) ($changes['title'] ?? ''));

        $giveaway->update([
            'credits' => $credits,
            'winners_count' => $winners,
            'ends_at' => $endsAt->setTimezone((string) config('app.timezone')),
            'prize' => mb_substr($title !== '' ? $title : ($autoTitle ? number_format($credits) . ' credits' : $giveaway->prize), 0, 120),
        ]);

        $this->refreshMessage($giveaway);

        return $giveaway;
    }

    public function cancel(RuxGiveaway $giveaway): RuxGiveaway
    {
        $claimed = RuxGiveaway::query()->whereKey($giveaway->id)->where('status', RuxGiveaway::ACTIVE)->update(['status' => RuxGiveaway::CANCELLED, 'ended_at' => now()]);

        if ($claimed === 0) {
            throw new InvalidArgumentException("Giveaway #{$giveaway->id} is {$giveaway->fresh()?->status}; only a running giveaway can be cancelled.");
        }

        $giveaway->refresh();
        $this->refreshMessage($giveaway);

        return $giveaway;
    }

    /**
     * End it now: the draw happens right away.
     */
    public function endNow(RuxGiveaway $giveaway): RuxGiveaway
    {
        if ($giveaway->status !== RuxGiveaway::ACTIVE) {
            throw new InvalidArgumentException("Giveaway #{$giveaway->id} is {$giveaway->status}; only a running giveaway can be ended.");
        }

        $giveaway->update(['ends_at' => now()]);

        return $this->draw($giveaway) ?? $giveaway->refresh();
    }

    /**
     * A giveaway's title, refused when it promises a prize other than credits.
     */
    public static function creditsOnlyTitle(string $title): string
    {
        $title = trim($title);

        if ($title !== '' && preg_match(self::OTHER_PRIZES, $title, $m) === 1) {
            throw new InvalidArgumentException("A giveaway's prize is always credits, and rux can give nothing else, so the title cannot promise \"{$m[0]}\". Use a title that names the occasion (or none) and say it is credits.");
        }

        return $title;
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function validate(int $credits, int $winners, CarbonImmutable $endsAt, CarbonImmutable $now, array $limits): void
    {
        $maxCredits = max(1, (int) ($limits['max_credits'] ?? 10000));
        $maxWinners = max(1, (int) ($limits['max_winners'] ?? 25));
        $maxDays = max(1, (int) ($limits['max_days'] ?? 60));

        if ($credits < 1 || $credits > $maxCredits) {
            throw new InvalidArgumentException("Credits per winner must be between 1 and {$maxCredits} (the limit set in rux's settings).");
        }

        if ($winners < 1 || $winners > $maxWinners) {
            throw new InvalidArgumentException("Winners must be between 1 and {$maxWinners}.");
        }

        if ($endsAt->lessThan($now->addMinute())) {
            throw new InvalidArgumentException('The end time ' . RuxTime::human($endsAt) . ' is not in the future (it is now ' . RuxTime::human($now) . '). Ask the admin when it should end.');
        }

        if ($endsAt->greaterThan($now->addDays($maxDays))) {
            throw new InvalidArgumentException("A giveaway can run for at most {$maxDays} days.");
        }
    }

    // --- entering -----------------------------------------------------------------

    /**
     * Answer a press on a giveaway's button with $respond. Returns false for components that are
     * not rux's. Everything here is local except one lookup on noprofit.space (about 100 ms,
     * 2 s at most), so the answer stays inside Discord's 3-second limit.
     *
     * @param  array<string, mixed>  $interaction
     * @param  callable(array<string, mixed>): void  $respond
     */
    public function handleInteraction(array $interaction, callable $respond): bool
    {
        $customId = (string) ($interaction['data']['custom_id'] ?? '');

        if ((int) ($interaction['type'] ?? 0) !== 3 || preg_match('/^rux:gw:(enter|leave):(\d+)$/', $customId, $m) !== 1) {
            return false;
        }

        $user = (array) ($interaction['member']['user'] ?? $interaction['user'] ?? []);
        $discordId = (string) ($user['id'] ?? '');
        $giveaway = RuxGiveaway::query()->find((int) $m[2]);

        // Only from the giveaway's own server: a button elsewhere cannot enter it.
        if ($giveaway !== null && (string) ($interaction['guild_id'] ?? '') !== $giveaway->guild_id) {
            $giveaway = null;
        }

        $answer = match (true) {
            $discordId === '' || $giveaway === null => $this->ephemeralData(RuxConfig::say('entry.closed')),
            $m[1] === 'leave' => $this->ephemeralData($this->leave($giveaway, $discordId)),
            default => $this->enter($giveaway, $discordId, (string) ($user['username'] ?? '')),
        };

        $respond(['type' => 4, 'data' => $answer]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function enter(RuxGiveaway $giveaway, string $discordId, string $username): array
    {
        if (!$giveaway->isOpen()) {
            return $this->ephemeralData(RuxConfig::say('entry.closed'));
        }

        if ($giveaway->entries()->where('discord_id', $discordId)->exists()) {
            return $this->alreadyIn($giveaway);
        }

        if ($giveaway->excludes($discordId)) {
            return $this->ephemeralData(RuxConfig::sayChoice('entry.excluded', $giveaway->exclude_recent));
        }

        // noprofit.space decides: the panel's own Discord links still include pre-2.1 ones.
        $cacheKey = "plugged-host-ai:rux:linked:{$discordId}";

        if (!Cache::get($cacheKey)) {
            try {
                $linked = $this->accounts->account($discordId, 2) !== null;
            } catch (DirectoryUnavailable $e) {
                Log::warning('[rux] could not check a giveaway entry with noprofit.space', ['giveaway' => $giveaway->id, 'discord_id' => $discordId, 'error' => $e->getMessage()]);

                return $this->ephemeralData(RuxConfig::say('entry.unavailable'));
            }

            if (!$linked) {
                return $this->ephemeralData(RuxConfig::say('entry.unlinked'), [[
                    'type' => 1,
                    'components' => [['type' => 2, 'style' => 5, 'label' => RuxConfig::say('entry.link'), 'url' => RuxConfig::linkUrl()]],
                ]]);
            }

            Cache::put($cacheKey, true, 600);
        }

        return $this->join($giveaway, $discordId, $username);
    }

    /**
     * @return array<string, mixed>
     */
    private function join(RuxGiveaway $giveaway, string $discordId, string $username): array
    {
        try {
            RuxGiveawayEntry::query()->create([
                'giveaway_id' => $giveaway->id,
                'discord_id' => $discordId,
                'username' => mb_substr($username, 0, 64) ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->alreadyIn($giveaway);
        }

        return $this->ephemeralData(RuxConfig::say('entry.joined', ['relative' => RuxTime::discord($giveaway->ends_at, 'R')]));
    }

    private function leave(RuxGiveaway $giveaway, string $discordId): string
    {
        if (!$giveaway->isOpen()) {
            return RuxConfig::say('entry.closed');
        }

        $giveaway->entries()->where('discord_id', $discordId)->whereNull('won_at')->delete();

        return RuxConfig::say('entry.left');
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyIn(RuxGiveaway $giveaway): array
    {
        return $this->ephemeralData(RuxConfig::say('entry.already', ['relative' => RuxTime::discord($giveaway->ends_at, 'R')]), [[
            'type' => 1,
            'components' => [['type' => 2, 'style' => 2, 'label' => RuxConfig::say('entry.leave'), 'custom_id' => "rux:gw:leave:{$giveaway->id}"]],
        ]]);
    }

    // --- drawing --------------------------------------------------------------------

    /**
     * @return Collection<int, RuxGiveaway>
     */
    public function due(): Collection
    {
        return RuxGiveaway::query()->where('status', RuxGiveaway::ACTIVE)->where('ends_at', '<=', now())->orderBy('ends_at')->get();
    }

    /**
     * Draw a giveaway whose time is up. Only the process that moves it from active to drawing
     * draws; everyone else gets null. When noprofit.space cannot say who is linked, nothing is
     * drawn and it goes back to active for the next tick.
     */
    public function draw(RuxGiveaway $giveaway): ?RuxGiveaway
    {
        $claimed = RuxGiveaway::query()
            ->whereKey($giveaway->id)
            ->where('status', RuxGiveaway::ACTIVE)
            ->where('ends_at', '<=', now())
            ->update(['status' => RuxGiveaway::DRAWING, 'updated_at' => now()]);

        if ($claimed === 0) {
            return null;
        }

        $giveaway->refresh();

        try {
            $winners = $this->pick($giveaway, $giveaway->winners_count);
        } catch (DirectoryUnavailable $e) {
            Log::warning('[rux] giveaway draw postponed: noprofit.space cannot say who is linked', ['giveaway' => $giveaway->id, 'error' => $e->getMessage()]);
            $giveaway->update(['status' => RuxGiveaway::ACTIVE]);

            return null;
        }

        DB::transaction(function () use ($giveaway, $winners): void {
            $now = now();

            RuxGiveawayEntry::query()
                ->where('giveaway_id', $giveaway->id)
                ->whereIn('discord_id', $winners)
                ->update(['won_at' => $now, 'draw' => 0]);

            $giveaway->update([
                'status' => RuxGiveaway::ENDED,
                'ended_at' => $now,
                'payout_status' => $winners === [] ? RuxGiveaway::PAYOUT_NONE : RuxGiveaway::PAYOUT_PENDING,
            ]);
        });

        Log::info('[rux] giveaway drawn', ['giveaway' => $giveaway->id, 'winners' => $winners]);

        $paid = $winners === [] ? true : $this->payDraw($giveaway, 0);

        $this->refreshMessage($giveaway);
        $this->announce($giveaway, $winners, $paid, reroll: false);

        return $giveaway;
    }

    /**
     * Draw more winners for an ended giveaway, among entries that have not won yet; they are
     * paid like the first ones.
     *
     * @return list<string> The new winners.
     */
    public function reroll(RuxGiveaway $giveaway, int $count): array
    {
        if ($giveaway->status !== RuxGiveaway::ENDED) {
            throw new InvalidArgumentException("Giveaway #{$giveaway->id} is {$giveaway->status}; only an ended giveaway can be rerolled.");
        }

        $count = max(1, min($count, (int) config('plugged-host-ai.rux.giveaway.max_winners', 25)));

        try {
            $winners = $this->pick($giveaway, $count);
        } catch (DirectoryUnavailable $e) {
            throw new InvalidArgumentException('Cannot reroll right now: ' . $e->getMessage());
        }

        if ($winners === []) {
            throw new InvalidArgumentException("Nobody else in giveaway #{$giveaway->id} can win: every remaining entry is unlinked, excluded or gone from the server.");
        }

        $draw = $giveaway->rerolls + 1;

        DB::transaction(function () use ($giveaway, $winners, $draw): void {
            RuxGiveawayEntry::query()
                ->where('giveaway_id', $giveaway->id)
                ->whereIn('discord_id', $winners)
                ->update(['won_at' => now(), 'draw' => $draw]);

            $giveaway->update(['rerolls' => $draw, 'payout_status' => RuxGiveaway::PAYOUT_PENDING]);
        });

        $paid = $this->payDraw($giveaway, $draw);

        $this->refreshMessage($giveaway);
        $this->announce($giveaway, $winners, $paid, reroll: true);

        return $winners;
    }

    /**
     * Up to $count winners in random order among the entries that have not won, are not excluded,
     * are linked on noprofit.space and are still in the server.
     *
     * @return list<string>
     *
     * @throws DirectoryUnavailable
     */
    private function pick(RuxGiveaway $giveaway, int $count): array
    {
        $excluded = $giveaway->excludedDiscordIds();

        $candidates = $giveaway->entries()
            ->whereNull('won_at')
            ->pluck('discord_id')
            ->map(fn ($id): string => (string) $id)
            ->reject(fn (string $id): bool => in_array($id, $excluded, true))
            ->values()
            ->all();

        if ($candidates === []) {
            return [];
        }

        $linked = $this->accounts->accounts($candidates);
        $eligible = array_values(array_filter($candidates, static fn (string $id): bool => isset($linked[$id])));

        // Fisher-Yates with the CSPRNG.
        for ($i = count($eligible) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$eligible[$i], $eligible[$j]] = [$eligible[$j], $eligible[$i]];
        }

        $winners = [];
        foreach ($eligible as $discordId) {
            if (count($winners) >= $count) {
                break;
            }

            try {
                if ($this->discord->member($giveaway->guild_id, $discordId) === null) {
                    continue;
                }
            } catch (DiscordException) {
                // Discord could not say; do not punish the entrant for it.
            }

            $winners[] = $discordId;
        }

        return $winners;
    }

    /**
     * Hand one draw's winners to noprofit.space. True once it took them (paid, or waiting until
     * they link there); false leaves the payout pending for retryPayouts().
     */
    public function payDraw(RuxGiveaway $giveaway, int $draw): bool
    {
        $entries = $giveaway->entries()->where('draw', $draw)->whereNotNull('won_at')->get();

        if ($entries->isEmpty()) {
            return true;
        }

        try {
            $result = $this->payouts->payGiveaway(
                $giveaway->payoutKey($draw),
                $giveaway->prize,
                $giveaway->credits,
                $entries->pluck('discord_id')->map(fn ($id): string => (string) $id)->values()->all(),
                $giveaway->created_by_account_id,
            );
        } catch (DirectoryUnavailable $e) {
            $giveaway->update([
                'payout_attempts' => $giveaway->payout_attempts + 1,
                'payout_error' => mb_substr($e->getMessage(), 0, 250),
            ]);

            Log::warning('[rux] giveaway payout failed, will retry', ['giveaway' => $giveaway->id, 'draw' => $draw, 'error' => $e->getMessage()]);

            return false;
        }

        foreach ($entries as $entry) {
            $entry->update(['payout' => $result['recipients'][$entry->discord_id]['status'] ?? 'pending']);
        }

        $unpaidDraws = $giveaway->entries()->whereNotNull('won_at')->whereNull('payout')->exists();

        $giveaway->update(array_filter([
            'payout_status' => $unpaidDraws ? RuxGiveaway::PAYOUT_PENDING : RuxGiveaway::PAYOUT_PAID,
            'payout_attempts' => $giveaway->payout_attempts + 1,
            'payout_error' => null,
            'noprofit_giveaway_id' => $draw === 0 ? $result['giveaway_id'] : null,
        ], static fn ($value, string $key): bool => $value !== null || $key === 'payout_error', ARRAY_FILTER_USE_BOTH));

        return true;
    }

    /**
     * Pay the draws noprofit.space did not take yet (it was down at the draw).
     */
    public function retryPayouts(): void
    {
        RuxGiveaway::query()
            ->where('status', RuxGiveaway::ENDED)
            ->where('payout_status', RuxGiveaway::PAYOUT_PENDING)
            ->orderBy('id')
            ->each(function (RuxGiveaway $giveaway): void {
                $draws = $giveaway->entries()->whereNotNull('won_at')->whereNull('payout')->distinct()->pluck('draw');

                foreach ($draws as $draw) {
                    $this->payDraw($giveaway, (int) $draw);
                }
            });
    }

    /**
     * A draw that died halfway (the process was killed) holds the giveaway in "drawing" with
     * no winners written; put it back so the next tick draws it.
     */
    public function recoverStalled(): void
    {
        RuxGiveaway::query()
            ->where('status', RuxGiveaway::DRAWING)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => RuxGiveaway::ACTIVE]);
    }

    /**
     * Keep the entry count on running giveaways' messages current, at most once a minute each.
     */
    public function refreshCounts(): void
    {
        RuxGiveaway::query()
            ->where('status', RuxGiveaway::ACTIVE)
            ->whereNotNull('message_id')
            ->withCount('entries')
            ->get()
            ->each(function (RuxGiveaway $giveaway): void {
                if ((int) $giveaway->entries_count !== $giveaway->rendered_entries) {
                    $this->refreshMessage($giveaway);
                }
            });
    }

    // --- Discord messages -------------------------------------------------------------

    public function refreshMessage(RuxGiveaway $giveaway): void
    {
        if ($giveaway->message_id === null) {
            return;
        }

        try {
            $this->discord->editMessage($giveaway->channel_id, $giveaway->message_id, $this->render($giveaway));
        } catch (DiscordException $e) {
            Log::warning('[rux] could not update a giveaway message', ['giveaway' => $giveaway->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The giveaway's message: what it is, when it ends, who can enter, how many did, and the
     * Enter button (disabled once it is over).
     *
     * @return array<string, mixed>
     */
    public function render(RuxGiveaway $giveaway): array
    {
        $entries = $giveaway->entries()->count();
        $giveaway->rendered_entries = $entries;
        if ($giveaway->exists && $giveaway->isDirty('rendered_entries')) {
            $giveaway->saveQuietly();
        }

        $prizeLine = $giveaway->winners_count > 1
            ? RuxConfig::say('giveaway.prize_each', ['credits' => number_format($giveaway->credits)])
            : RuxConfig::say('giveaway.prize_one', ['credits' => number_format($giveaway->credits)]);

        $rules = ['- ' . RuxConfig::say('giveaway.rule_link')];
        if ($giveaway->exclude_recent > 0) {
            $rules[] = '- ' . RuxConfig::sayChoice('giveaway.rule_exclude', $giveaway->exclude_recent);
        }

        $winnerIds = $giveaway->entries()->whereNotNull('won_at')->orderBy('draw')->orderBy('id')->pluck('discord_id');

        [$body, $winnersValue, $button, $color] = match ($giveaway->status) {
            RuxGiveaway::ACTIVE, RuxGiveaway::DRAWING => [
                RuxConfig::say('giveaway.open_body', ['relative' => RuxTime::discord($giveaway->ends_at, 'R'), 'absolute' => RuxTime::discord($giveaway->ends_at, 'f')]),
                (string) $giveaway->winners_count,
                ['style' => 1, 'label' => RuxConfig::say('giveaway.enter'), 'custom_id' => "rux:gw:enter:{$giveaway->id}", 'disabled' => $giveaway->status !== RuxGiveaway::ACTIVE],
                self::ACCENT,
            ],
            RuxGiveaway::CANCELLED => [
                RuxConfig::say('giveaway.cancelled_body'),
                (string) $giveaway->winners_count,
                ['style' => 2, 'label' => RuxConfig::say('giveaway.cancelled'), 'custom_id' => "rux:gw:enter:{$giveaway->id}", 'disabled' => true],
                null,
            ],
            default => [
                RuxConfig::say('giveaway.ended_body', ['relative' => RuxTime::discord($giveaway->ended_at ?? $giveaway->ends_at, 'R')]),
                $winnerIds->isEmpty() ? RuxConfig::say('giveaway.no_winner') : $winnerIds->map(fn ($id): string => "<@{$id}>")->implode(', '),
                ['style' => 2, 'label' => RuxConfig::say('giveaway.ended'), 'custom_id' => "rux:gw:enter:{$giveaway->id}", 'disabled' => true],
                null,
            ],
        };

        $embed = array_filter([
            'title' => $giveaway->prize,
            'description' => ($giveaway->prize !== number_format($giveaway->credits) . ' credits' || $giveaway->winners_count > 1 ? $prizeLine . "\n\n" : '') . $body,
            'color' => $color,
            'fields' => [
                ['name' => RuxConfig::say('giveaway.winners'), 'value' => mb_substr($winnersValue, 0, 1024), 'inline' => true],
                ['name' => RuxConfig::say('giveaway.entries'), 'value' => number_format($entries), 'inline' => true],
                ['name' => RuxConfig::say('giveaway.rules'), 'value' => implode("\n", $rules), 'inline' => false],
            ],
            'footer' => ['text' => RuxConfig::say('giveaway.footer', ['id' => $giveaway->id])],
            'timestamp' => $giveaway->ends_at->toIso8601String(),
        ], static fn ($value): bool => $value !== null);

        return [
            'content' => '',
            'embeds' => [$embed],
            'components' => [['type' => 1, 'components' => [['type' => 2] + $button]]],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /**
     * @param  list<string>  $winners
     */
    private function announce(RuxGiveaway $giveaway, array $winners, bool $paid, bool $reroll): void
    {
        if ($winners === []) {
            $content = RuxConfig::say('giveaway.announce_none', ['prize' => $giveaway->prize]);
        } else {
            $mentions = implode(', ', array_map(static fn (string $id): string => "<@{$id}>", $winners));
            $content = RuxConfig::say($reroll ? 'giveaway.announce_reroll' : 'giveaway.announce', ['winners' => $mentions, 'prize' => $giveaway->prize])
                . ' ' . RuxConfig::say($paid && $this->allPaid($giveaway, $winners) ? 'giveaway.announce_paid' : 'giveaway.announce_pending');
        }

        $payload = [
            'content' => $content,
            'allowed_mentions' => ['parse' => [], 'users' => array_slice($winners, 0, 100)],
        ];

        if ($giveaway->message_id !== null) {
            $payload['message_reference'] = ['message_id' => $giveaway->message_id, 'fail_if_not_exists' => false];
        }

        try {
            $this->discord->createMessage($giveaway->channel_id, $payload);
        } catch (DiscordException $e) {
            Log::warning('[rux] could not announce giveaway winners', ['giveaway' => $giveaway->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $winners
     */
    private function allPaid(RuxGiveaway $giveaway, array $winners): bool
    {
        return !$giveaway->entries()->whereIn('discord_id', $winners)->where(fn ($q) => $q->whereNull('payout')->orWhere('payout', '!=', 'paid'))->exists();
    }

    // --- for the model ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function summary(RuxGiveaway $giveaway): array
    {
        $winners = $giveaway->entries()->whereNotNull('won_at')->orderBy('draw')->get(['discord_id', 'draw', 'payout']);

        return array_filter([
            'id' => $giveaway->id,
            'status' => $giveaway->status,
            'prize' => $giveaway->prize,
            'credits_per_winner' => $giveaway->credits,
            'winners_wanted' => $giveaway->winners_count,
            'channel' => "<#{$giveaway->channel_id}>",
            'ends_at' => RuxTime::human($giveaway->ends_at),
            'ended_at' => $giveaway->ended_at !== null ? RuxTime::human($giveaway->ended_at) : null,
            'entries' => $giveaway->entries()->count(),
            'requires_linked_noprofit_account' => true,
            'excludes_winners_of' => $giveaway->exclude_recent > 0
                ? array_map(static fn (array $g): string => '#' . $g['id'] . ' "' . $g['prize'] . '"' . (isset($g['at']) ? ' (' . RuxTime::human(CarbonImmutable::parse($g['at'])) . ')' : ''), (array) ($giveaway->excluded['giveaways'] ?? []))
                : null,
            'excluded_people' => $giveaway->exclude_recent > 0 ? count($giveaway->excludedDiscordIds()) : null,
            'winners' => $winners->isEmpty() ? null : $winners->map(fn (RuxGiveawayEntry $e): string => "<@{$e->discord_id}>" . ($e->draw > 0 ? " (reroll {$e->draw})" : '') . ' ' . ($e->payout ?? 'not paid yet'))->all(),
            'payout' => $giveaway->status === RuxGiveaway::ENDED ? $giveaway->payout_status . ($giveaway->payout_error ? " ({$giveaway->payout_error})" : '') : null,
            'message' => $giveaway->jumpUrl(),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function ephemeral(string $content): array
    {
        return ['type' => 4, 'data' => $this->ephemeralData($content)];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function ephemeralData(string $content, array $components = []): array
    {
        return array_filter([
            'content' => $content,
            'flags' => 64,
            'components' => $components ?: null,
            'allowed_mentions' => ['parse' => []],
        ], static fn ($value): bool => $value !== null);
    }
}
