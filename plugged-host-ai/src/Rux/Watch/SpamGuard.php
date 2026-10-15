<?php

namespace PluggedHost\Ai\Rux\Watch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PluggedHost\Ai\Discord\BotSupervisor;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxConfig;

/**
 * Spam, caught without AI in every channel rux reads (config rux.spam): flooding, the same text
 * again and again (across channels too, the way hijacked accounts post), mass mentions and emoji
 * walls. The AI checks of watched channels no longer judge spam: they warned members for a single
 * "." and for a complaint posted twice, and spam has to be caught when the AI is out of quota too.
 *
 * The daemon only counts (screen(): memory only, no network). What it catches waits per member
 * and goes to a worker at most every few seconds (flush()), so a flood starts one or two workers,
 * not one per message. The worker (act()) leaves staff alone, deletes the spam and warns the
 * member at most once per rules.cooldown_seconds; the warning counts towards the automatic steps.
 */
final class SpamGuard
{
    /** Seconds between two workers for the same member. */
    private const SPAWN_GAP = 3;

    /** @var array<string, list<array{t: float, id: string, channel: string, text: string, said: string}>> member id => recent messages */
    private array $recent = [];

    /** @var array<string, array{name: string, roles: list<string>, reason: string, messages: array<string, array{id: string, channel: string, said: string}>}> */
    private array $pending = [];

    /** @var array<string, float> member id => when a worker last started for them */
    private array $spawned = [];

    /** @var array<string, float> message id => when it was handed on (never twice) */
    private array $handled = [];

    public function __construct(
        private readonly RuxDiscord $discord,
        private readonly RuleService $rules,
        private readonly RuxAccess $access,
    ) {}

    // --- in the daemon ----------------------------------------------------------------------

    /**
     * Count a member's message. True when it is spam: it waits for flush(), and the AI checks
     * (watched channels, standing rules) should skip it.
     *
     * @param  array<string, mixed>  $message
     */
    public function screen(array $message, string $botId): bool
    {
        $author = (array) ($message['author'] ?? []);
        $memberId = (string) ($author['id'] ?? '');
        $messageId = (string) ($message['id'] ?? '');

        if (!config('plugged-host-ai.rux.spam.enabled', true) || $memberId === '' || $messageId === ''
            || (string) ($message['guild_id'] ?? '') !== RuxConfig::guildId()
            || ($author['bot'] ?? false) || isset($message['webhook_id']) || $memberId === $botId) {
            return false;
        }

        $config = (array) config('plugged-host-ai.rux.spam', []);
        $floodSeconds = max(1, (int) ($config['flood_seconds'] ?? 10));
        $duplicateSeconds = max(1, (int) ($config['duplicate_seconds'] ?? 120));
        $now = microtime(true);
        $content = (string) ($message['content'] ?? '');
        $text = self::fingerprint($content);

        $recent = array_values(array_filter(
            $this->recent[$memberId] ?? [],
            static fn (array $seen): bool => $now - $seen['t'] <= max($floodSeconds, $duplicateSeconds),
        ));
        $recent[] = ['t' => $now, 'id' => $messageId, 'channel' => (string) ($message['channel_id'] ?? ''), 'text' => $text, 'said' => mb_substr($content, 0, 300)];
        $this->recent[$memberId] = array_slice($recent, -60);

        $flood = array_values(array_filter($recent, static fn (array $seen): bool => $now - $seen['t'] <= $floodSeconds));
        $copies = $text === '' ? [] : array_values(array_filter($recent, static fn (array $seen): bool => $seen['text'] === $text && $now - $seen['t'] <= $duplicateSeconds));
        $mentioned = count(array_unique([
            ...array_map(static fn ($user): string => 'u' . ($user['id'] ?? ''), array_filter((array) ($message['mentions'] ?? []), static fn ($user): bool => is_array($user) && ($user['id'] ?? '') !== $memberId)),
            ...array_map(static fn ($role): string => 'r' . $role, (array) ($message['mention_roles'] ?? [])),
        ]));

        [$reason, $spam] = match (true) {
            count($flood) >= max(2, (int) ($config['flood_messages'] ?? 7)) => ['Spam: too many messages at once', $flood],
            count($copies) >= max(2, (int) ($config['duplicates'] ?? 3)) => ['Spam: the same message again and again', array_slice($copies, 1)],
            $mentioned >= max(2, (int) ($config['mentions'] ?? 6)) => ['Spam: mentioning many people at once', [end($recent)]],
            self::emoji($content) >= max(2, (int) ($config['emoji'] ?? 25)) => ['Spam: a wall of emoji', [end($recent)]],
            default => [null, []],
        };

        if ($reason === null) {
            return false;
        }

        $member = (array) ($message['member'] ?? []);
        $this->pending[$memberId] ??= [
            'name' => mb_substr((string) ($member['nick'] ?? $author['global_name'] ?? $author['username'] ?? $memberId), 0, 100),
            'roles' => array_values(array_map('strval', (array) ($member['roles'] ?? []))),
            'reason' => $reason,
            'messages' => [],
        ];

        foreach ($spam as $seen) {
            if (!isset($this->handled[$seen['id']])) {
                $this->handled[$seen['id']] = $now;
                $this->pending[$memberId]['messages'][$seen['id']] = ['id' => $seen['id'], 'channel' => $seen['channel'], 'said' => $seen['said']];
            }
        }

        return true;
    }

    /**
     * Hand what waits to workers: per member at most every SPAWN_GAP seconds (what comes in
     * meanwhile goes with the next one). Called after each spam message and on every daemon tick.
     */
    public function flush(BotSupervisor $supervisor): void
    {
        $now = microtime(true);

        foreach ($this->pending as $memberId => $caught) {
            if ($caught['messages'] === [] || $now - ($this->spawned[$memberId] ?? 0.0) < self::SPAWN_GAP) {
                continue;
            }

            $key = (string) Str::uuid();
            Cache::put("plugged-host-ai:rux:spam:{$key}", array_merge(['member' => (string) $memberId], $caught, ['messages' => array_values($caught['messages'])]), 600);
            unset($this->pending[$memberId]);
            $this->spawned[$memberId] = $now;
            $supervisor->spawnCommand('plugged-host:rux-spam', [$key]);
        }

        // Forget what is too old to matter.
        $this->handled = array_filter($this->handled, static fn (float $at): bool => $now - $at < 600);
        $this->spawned = array_filter($this->spawned, static fn (float $at): bool => $now - $at < 60);
        $this->recent = array_filter($this->recent, static fn (array $seen): bool => $seen !== [] && $now - end($seen)['t'] < 600);
    }

    /**
     * The text two messages share when they are the same message: case, spaces and punctuation
     * do not count. Empty for very short ones ("lol", "?"), which only count towards flooding.
     */
    public static function fingerprint(string $content): string
    {
        $text = (string) preg_replace('/[^\p{L}\p{N}:\/.]+/u', '', mb_strtolower($content));

        return mb_strlen($text) >= 4 ? $text : '';
    }

    public static function emoji(string $content): int
    {
        return (int) preg_match_all('/<a?:\w+:\d+>|\p{Extended_Pictographic}/u', $content);
    }

    // --- in the worker -------------------------------------------------------------------------

    public function act(string $key): void
    {
        $caught = Cache::pull("plugged-host-ai:rux:spam:{$key}");

        if (!is_array($caught) || ($caught['messages'] ?? []) === [] || $this->access->isStaff((string) $caught['member'], (array) $caught['roles'])) {
            return;
        }

        $messages = array_values((array) $caught['messages']);
        $last = array_pop($messages);
        $audit = 'rux: ' . $caught['reason'];

        foreach (collect($messages)->groupBy('channel') as $channelId => $inChannel) {
            $this->delete((string) $channelId, $inChannel->pluck('id')->map(static fn ($id): string => (string) $id)->all(), $audit);
        }

        $hit = [
            'message_id' => (string) $last['id'],
            'channel_id' => (string) $last['channel'],
            'author_id' => (string) $caught['member'],
            'author_name' => (string) $caught['name'],
            'content' => (string) $last['said'],
        ];

        Log::info('[rux] spam', ['member' => $hit['author_id'], 'reason' => $caught['reason'], 'messages' => count($messages) + 1]);

        if (!Cache::add("plugged-host-ai:rux:spam-warned:{$hit['author_id']}", true, max(30, (int) config('plugged-host-ai.rux.rules.cooldown_seconds', 300)))) {
            $this->delete($hit['channel_id'], [$hit['message_id']], $audit);

            return;
        }

        try {
            $this->rules->enforceGuideline((string) $caught['reason'], $hit);
        } catch (DiscordException $e) {
            Log::warning('[rux] spam: warning failed', ['member' => $hit['author_id'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function delete(string $channelId, array $ids, string $audit): void
    {
        foreach (count($ids) > 1 ? array_chunk($ids, 100) : [$ids] as $chunk) {
            try {
                $this->discord->deleteMessages($channelId, $chunk, $audit);

                continue;
            } catch (DiscordException $e) {
                if (count($chunk) === 1) {
                    if (!$e->isNotFound()) {
                        Log::warning('[rux] spam: could not delete a message', ['channel' => $channelId, 'error' => $e->getMessage()]);
                    }

                    continue;
                }
            }

            // A bulk delete fails as a whole (one of them already gone): one by one then.
            foreach ($chunk as $id) {
                $this->delete($channelId, [$id], $audit);
            }
        }
    }
}
