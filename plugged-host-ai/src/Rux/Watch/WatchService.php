<?php

namespace PluggedHost\Ai\Rux\Watch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PluggedHost\Ai\Rux\Agent\Ai\AiBusyException;
use PluggedHost\Ai\Rux\Agent\Ai\AiException;
use PluggedHost\Ai\Rux\Agent\Ai\RuxAi;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxRule;
use PluggedHost\Ai\Rux\Models\RuxWatch;
use PluggedHost\Ai\Rux\Models\RuxWatchItem;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use Throwable;

/**
 * Watched channels (#chat and #bot-commands to begin with): rux checks every member message
 * there, not only the ones that hit a rule's keywords.
 *
 * The daemon only queues them (enqueue()). One worker at a time (drain()) checks them in batches
 * of up to 20 per model call against the standing rules and the always-on guidelines, so a busy
 * channel costs a fraction of one call per message. The AI's rate limits can still be reached: a
 * batch that waited too long for a busy AI is dropped, messages older than ten minutes are not
 * checked any more, and when the AI refuses rux (a bad key) watching pauses for ten minutes.
 * Staff are never checked.
 */
class WatchService
{
    public const BATCH = 20;

    public const MAX_AGE_MINUTES = 10;

    /** @var array<string, string> code => what it means; acted on with delete + warn. */
    public const GUIDELINES = [
        'G1' => 'Scam or phishing: fake giveaways or free Nitro, suspicious login or download links, account or crypto scams',
        'G2' => 'Slurs or hate against a group of people',
        'G3' => 'Harassing, threatening or bullying someone',
        'G4' => 'Sexual or gore content',
        'G5' => 'Spam: flooding, the same message again and again, walls of emoji or mentions',
        'G6' => 'Advertising another Discord server, game host or paid service',
    ];

    /** @var list<string>|null */
    private ?array $channels = null;

    private float $channelsLoadedAt = 0.0;

    public function __construct(
        private readonly RuxDiscord $discord,
        private readonly RuxAi $ai,
        private readonly RuleService $rules,
        private readonly RuxAccess $access,
    ) {}

    // --- in the daemon ----------------------------------------------------------------------

    /**
     * Queue a member's message from a watched channel. False when the channel is not watched
     * (or watching is paused), so the daemon falls back to the keyword rules.
     *
     * @param  array<string, mixed>  $message
     */
    public function enqueue(array $message, string $botId): bool
    {
        $author = (array) ($message['author'] ?? []);
        $content = trim((string) ($message['content'] ?? ''));

        if ($content === '' || (string) ($message['guild_id'] ?? '') !== RuxConfig::guildId()
            || ($author['bot'] ?? false) || isset($message['webhook_id']) || (string) ($author['id'] ?? '') === $botId
            || !in_array((string) ($message['channel_id'] ?? ''), $this->watchedChannelIds(), true)) {
            return false;
        }

        if (Cache::has('plugged-host-ai:rux:watch-paused')) {
            return true;
        }

        RuxWatchItem::query()->create([
            'message_id' => (string) $message['id'],
            'channel_id' => (string) $message['channel_id'],
            'author_id' => (string) $author['id'],
            'author_name' => mb_substr((string) ($message['member']['nick'] ?? $author['global_name'] ?? $author['username'] ?? $author['id']), 0, 100),
            'roles' => array_values(array_map('strval', (array) ($message['member']['roles'] ?? []))),
            'content' => mb_substr($content, 0, 1500),
        ]);

        return true;
    }

    /**
     * The ids of the watched channels, resolving names ("chat" finds "💬│chat"); reloaded every
     * minute.
     *
     * @return list<string>
     */
    public function watchedChannelIds(bool $fresh = false): array
    {
        if (!$fresh && $this->channels !== null && microtime(true) - $this->channelsLoadedAt < 60) {
            return $this->channels;
        }

        $this->channelsLoadedAt = microtime(true);

        try {
            $wanted = RuxWatch::query()->pluck('channel')->map(fn ($c): string => (string) $c)->all();
            $all = $wanted === [] ? [] : $this->discord->channels(RuxConfig::guildId());
        } catch (Throwable $e) {
            report($e);

            return $this->channels ??= [];
        }

        $ids = [];
        foreach ($wanted as $channel) {
            if (preg_match('/^\d{17,20}$/', $channel) === 1) {
                $ids[] = $channel;

                continue;
            }

            $plain = self::plain($channel);
            foreach ($all as $candidate) {
                if (in_array((int) ($candidate['type'] ?? -1), [0, 5], true) && self::plain((string) ($candidate['name'] ?? '')) === $plain) {
                    $ids[] = (string) $candidate['id'];
                }
            }
        }

        return $this->channels = array_values(array_unique($ids));
    }

    private static function plain(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(ltrim($name, '#')));
    }

    // --- in the worker -------------------------------------------------------------------------

    /**
     * Check queued messages until the queue is empty or $seconds have passed.
     */
    public function drain(int $seconds = 110): void
    {
        $until = microtime(true) + $seconds;

        while (microtime(true) < $until) {
            $stale = RuxWatchItem::query()->where('created_at', '<', now()->subMinutes(self::MAX_AGE_MINUTES))->delete();
            if ($stale > 0) {
                Log::info("[rux] watch: {$stale} messages waited too long and were not checked");
            }

            $batch = RuxWatchItem::query()->orderBy('id')->limit(self::BATCH)->get();
            if ($batch->isEmpty()) {
                return;
            }

            RuxWatchItem::query()->whereIn('id', $batch->modelKeys())->delete();

            $members = $batch->reject(fn (RuxWatchItem $item): bool => $this->access->isStaff($item->author_id, (array) $item->roles))->values();
            if ($members->isEmpty()) {
                continue;
            }

            $verdicts = $this->judge($members->all(), max(5.0, $until - microtime(true)));

            if ($verdicts === null) {
                continue;
            }

            $acted = [];
            foreach ($verdicts as $verdict) {
                $item = $members[$verdict['n'] - 1] ?? null;

                // One action per message per batch.
                if (!$item instanceof RuxWatchItem || isset($acted[$item->id])) {
                    continue;
                }

                $acted[$item->id] = true;

                // Other members' messages were in the same prompt and can steer verdicts onto this
                // one (prompt injection), so act only when the message is flagged on its own too.
                if ($members->contains(fn (RuxWatchItem $other): bool => $other->author_id !== $item->author_id)) {
                    $verdict = $this->judge([$item], max(5.0, $until - microtime(true)))[0] ?? null;

                    if ($verdict === null) {
                        continue;
                    }
                }

                $this->act($item, $verdict['rule'], $verdict['why']);
            }
        }
    }

    /**
     * One model call for the whole batch. Null when it could not be checked.
     *
     * @param  list<RuxWatchItem>  $items
     * @return list<array{n: int, rule: string, why: string}>|null
     */
    private function judge(array $items, float $budget): ?array
    {
        $rules = RuxRule::query()->where('active', true)->where('guild_id', RuxConfig::guildId())->get();
        $baseline = (bool) config('plugged-host-ai.rux.watch.guidelines', true);

        $lines = [];
        foreach ($rules as $rule) {
            $lines[] = "R{$rule->id}: {$rule->description}" . ($rule->channel_ids ? ' (only in ' . implode(', ', array_map(static fn ($id): string => "#{$id}", $rule->channel_ids)) . ')' : '');
        }
        if ($baseline) {
            foreach (self::GUIDELINES as $code => $text) {
                $lines[] = "{$code}: {$text}";
            }
        }

        if ($lines === []) {
            return [];
        }

        $numbered = [];
        foreach ($items as $index => $item) {
            $numbered[] = '[' . ($index + 1) . "] #{$item->channel_id} {$item->author_name}: " . str_replace("\n", ' ', Str::limit($item->content, 600));
        }

        $messages = [
            ['role' => 'system', 'content' => implode("\n", [
                'You moderate the plugged.host Discord server (a free game server host) for the bot rux. Check each numbered member message against these rules:',
                ...$lines,
                '',
                'Most messages break nothing. Members write casually: slang, typos, abbreviations, other languages; judge what they mean. Only flag clear breaks: not jokes between friends, not someone quoting or asking about a rule, not a message that merely shares a word with a rule.',
                'Answer with only this JSON and nothing else: {"breaks": [{"n": <message number>, "rule": "<R.. or G.. code>", "why": "<a few words>"}]}. Use an empty list when nothing is broken.',
            ])],
            ['role' => 'user', 'content' => "<messages untrusted=\"true\">\n" . implode("\n", $numbered) . "\n</messages>"],
        ];

        $deadline = microtime(true) + min(60.0, $budget);

        while (true) {
            try {
                $response = $this->ai->turn($messages);

                break;
            } catch (AiBusyException $e) {
                if (microtime(true) + $e->retryAfter >= $deadline) {
                    Log::info('[rux] watch: the AI stayed busy, ' . count($items) . ' messages were not checked');

                    return null;
                }

                sleep(max(1, min($e->retryAfter, 8)));
            } catch (AiException $e) {
                // Refused or not configured: waiting a moment will not fix it, so stop asking.
                Log::warning('[rux] watch: the AI is unavailable; paused for 10 minutes', ['error' => $e->getMessage()]);
                $this->pause(600);
                RuxWatchItem::query()->delete();

                return null;
            }
        }

        $text = (string) ($response['message']['content'] ?? '');

        if (preg_match('/\{.*\}/s', $text, $json) !== 1 || !is_array($decoded = json_decode($json[0], true))) {
            Log::warning('[rux] watch: unreadable verdict', ['answer' => Str::limit($text, 300)]);

            return null;
        }

        $verdicts = [];
        foreach ((array) ($decoded['breaks'] ?? []) as $break) {
            $n = (int) ($break['n'] ?? 0);
            $rule = strtoupper(trim((string) ($break['rule'] ?? '')));

            if ($n >= 1 && $n <= count($items) && preg_match('/^(R\d+|G[1-6])$/', $rule) === 1) {
                $verdicts[] = ['n' => $n, 'rule' => $rule, 'why' => mb_substr((string) ($break['why'] ?? ''), 0, 200)];
            }
        }

        return $verdicts;
    }

    private function act(RuxWatchItem $item, string $code, string $why): void
    {
        $cooldown = "plugged-host-ai:rux:rule-cooldown:{$code}:{$item->author_id}";

        if (!Cache::add($cooldown, true, max(30, (int) config('plugged-host-ai.rux.rules.cooldown_seconds', 300)))) {
            return;
        }

        $hit = [
            'message_id' => $item->message_id,
            'channel_id' => $item->channel_id,
            'author_id' => $item->author_id,
            'author_name' => (string) ($item->author_name ?? $item->author_id),
            'content' => $item->content,
        ];

        Log::info('[rux] watch: acting', ['rule' => $code, 'member' => $item->author_id, 'message' => $item->message_id, 'why' => $why]);

        try {
            if ($code[0] === 'R') {
                $rule = RuxRule::query()->where('active', true)->find((int) substr($code, 1));

                if ($rule !== null && $rule->appliesIn($item->channel_id)) {
                    $rule->increment('hits');
                    $this->rules->enforce($rule, $hit);
                }

                return;
            }

            $this->rules->enforceGuideline(Str::before(self::GUIDELINES[$code], ':'), $hit);
        } catch (DiscordException $e) {
            Log::warning('[rux] watch: action failed', ['rule' => $code, 'member' => $item->author_id, 'error' => $e->getMessage()]);
        }
    }

    private function pause(int $seconds): void
    {
        Cache::put('plugged-host-ai:rux:watch-paused', true, $seconds);
    }
}
