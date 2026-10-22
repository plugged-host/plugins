<?php

namespace PluggedHost\Ai\Rux\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Support\ImageInput;

/**
 * The gateway daemon's filter for MESSAGE_CREATE. It has to be cheap, because it runs inside
 * the daemon's read loop: no HTTP, only the database and the cache.
 *
 * An order is a DM to rux, or a message on one of rux's servers that @mentions rux, replies to
 * it, or calls it by name ("hey rux, …", "yo rux …", "rux, …"; this needs the Message Content
 * intent). Messages from servers rux is in but does not work on are ignored.
 * Bots are ignored, people noprofit.space recently said are not admins are dropped here without
 * a worker, and everyone gets a few orders per minute at most.
 */
final class RuxInbox
{
    /** "hey rux, …", "yo rux …", "rux: …", "ok rux …" at the start of a message. */
    private const NAME_CALL = '/^\W*(?:(?:hey|hi|hello|yo|oi|ok|okay|ay|aye|sup|pls|please|dear|hiya|heya)\W+)?rux\b/iu';

    /**
     * @param  array<string, mixed>  $message
     * @return int|null The stored order's id, for its worker; null when it is not an order.
     */
    public function accept(array $message, string $botId): ?int
    {
        $author = (array) ($message['author'] ?? []);
        $authorId = (string) ($author['id'] ?? '');

        if ($authorId === '' || $authorId === $botId || ($author['bot'] ?? false) || ($author['system'] ?? false) || isset($message['webhook_id'])) {
            return null;
        }

        // Normal messages and replies only; joins, pins and the like have other types.
        if (!in_array((int) ($message['type'] ?? 0), [0, 19], true)) {
            return null;
        }

        $guildId = isset($message['guild_id']) ? (string) $message['guild_id'] : null;

        if ($guildId !== null) {
            if (!RuxConfig::serves($guildId)) {
                return null;
            }

            $mentioned = collect((array) ($message['mentions'] ?? []))->contains(fn ($user): bool => (string) ($user['id'] ?? '') === $botId);
            $repliedTo = (string) ($message['referenced_message']['author']['id'] ?? '') === $botId;

            $called = preg_match(self::NAME_CALL, (string) ($message['content'] ?? '')) === 1;

            if (!$mentioned && !$repliedTo && !$called) {
                return null;
            }
        }

        $content = trim((string) preg_replace('/<@!?' . preg_quote($botId, '/') . '>/', '', (string) ($message['content'] ?? '')));
        $reference = (array) ($message['referenced_message'] ?? []);
        $images = ImageInput::discordAttachments($message);
        $replyImages = (string) ($reference['author']['id'] ?? '') !== $botId ? ImageInput::discordAttachments($reference) : [];

        // A screenshot alone (or "@bot" in reply to one) is a question too.
        if ($content === '' && $images === [] && $replyImages === []) {
            return null;
        }

        if (Cache::get(RuxAgent::deniedKey($authorId, $guildId))) {
            return null;
        }

        $limiter = "plugged-host-ai:rux:inbox:{$authorId}";
        if (RateLimiter::tooManyAttempts($limiter, max(1, (int) config('plugged-host-ai.rux.messages_per_minute', 6)))) {
            return null;
        }
        RateLimiter::hit($limiter, 60);

        $channelId = (string) $message['channel_id'];

        $mentions = collect((array) ($message['mentions'] ?? []))
            ->reject(fn ($user): bool => (string) ($user['id'] ?? '') === $botId)
            ->map(fn ($user): array => array_filter([
                'id' => (string) ($user['id'] ?? ''),
                'name' => (string) ($user['global_name'] ?? $user['username'] ?? ''),
                'bot' => ($user['bot'] ?? false) ?: null,
            ], static fn ($value): bool => $value !== null))
            ->values()
            ->all();

        $order = RuxMessage::query()->create([
            'conversation' => RuxMessage::conversationKey($channelId, $authorId),
            'discord_message_id' => (string) ($message['id'] ?? '') ?: null,
            'channel_id' => $channelId,
            'guild_id' => $guildId,
            'author_id' => $authorId,
            'author_name' => mb_substr((string) ($message['member']['nick'] ?? $author['global_name'] ?? $author['username'] ?? ''), 0, 100) ?: null,
            'role' => RuxMessage::USER,
            'content' => mb_substr($content, 0, max(200, (int) config('plugged-host-ai.rux.agent.max_message_chars', 4000))),
            'status' => RuxMessage::PENDING,
            'meta' => array_filter([
                'mentions' => $mentions ?: null,
                'images' => $images ?: null,
                'reply_to' => isset($reference['id']) && (string) ($reference['author']['id'] ?? '') !== $botId ? [
                    'id' => (string) $reference['id'],
                    'author_id' => (string) ($reference['author']['id'] ?? ''),
                    'author_name' => (string) ($reference['author']['global_name'] ?? $reference['author']['username'] ?? ''),
                    'content' => mb_substr((string) ($reference['content'] ?? ''), 0, 1000),
                    'images' => $replyImages ?: null,
                ] : null,
            ]) ?: null,
        ]);

        $order->update(['turn_id' => $order->id]);

        return $order->id;
    }
}
