<?php

namespace PluggedHost\Ai\Discord;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A bot's side of Discord's REST API (v10), with that bot's own token (rux's RuxDiscord, neo's
 * NeoBotDiscord).
 *
 * A 429 is waited out and retried when Discord asks for 10 s or less; anything else that is
 * not a success becomes a DiscordException with a message the model can pass on. Moderation
 * calls carry the order in X-Audit-Log-Reason, so Discord's audit log says who asked rux.
 */
class DiscordRest
{
    private const BASE = 'https://discord.com/api/v10';

    /** @var array<string, string> DM channel per user id, for this process. */
    private array $dmChannels = [];

    /**
     * @param  string  $bot  The bot's name: in the User-Agent, and keeping each bot's cached view of the server apart.
     */
    public function __construct(private readonly string $token, private readonly string $bot) {}

    /**
     * @param  array<string, mixed>|list<mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws DiscordException
     */
    public function call(string $method, string $path, ?array $body = null, ?string $reason = null, array $query = []): Response
    {
        for ($attempt = 1; ; $attempt++) {
            $request = Http::withHeaders(array_filter([
                'Authorization' => 'Bot ' . $this->token,
                // Discord's required "DiscordBot (url, version)": the plugins' source, the same for every panel.
                'User-Agent' => 'DiscordBot (https://github.com/plugged-host/plugins, 1.0) ' . $this->bot,
                'X-Audit-Log-Reason' => $reason !== null && $reason !== '' ? rawurlencode(mb_substr($reason, 0, 450)) : null,
            ]))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15);

            $url = self::BASE . '/' . ltrim($path, '/');
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }

            try {
                $response = $body === null
                    ? $request->send($method, $url)
                    : $request->send($method, $url, ['json' => $body]);
            } catch (ConnectionException $e) {
                if ($attempt < 2) {
                    usleep(500000);

                    continue;
                }

                throw new DiscordException(0, null, 'Discord could not be reached: ' . $e->getMessage());
            }

            if ($response->status() === 429 && $attempt < 3) {
                $wait = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 1);

                if ($wait <= 10) {
                    usleep((int) (max(0.05, $wait) * 1_000_000));

                    continue;
                }
            }

            if ($response->status() >= 500 && $attempt < 2) {
                usleep(500000);

                continue;
            }

            if (!$response->successful()) {
                throw DiscordException::from($response->status(), (array) $response->json());
            }

            return $response;
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws DiscordException
     */
    public function json(string $method, string $path, ?array $body = null, ?string $reason = null, array $query = []): array
    {
        return (array) $this->call($method, $path, $body, $reason, $query)->json();
    }

    // --- messages ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createMessage(string $channelId, array $payload): array
    {
        return $this->json('POST', "channels/{$channelId}/messages", $payload);
    }

    /**
     * A message with one file attached (multipart: payload_json + files[0]).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws DiscordException
     */
    public function createMessageWithFile(string $channelId, array $payload, string $filename, string $bytes): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bot ' . $this->token,
                'User-Agent' => 'DiscordBot (https://github.com/plugged-host/plugins, 1.0) ' . $this->bot,
            ])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(30)
                ->attach('files[0]', $bytes, $filename)
                ->post(self::BASE . "/channels/{$channelId}/messages", ['payload_json' => (string) json_encode($payload + ['attachments' => [['id' => 0, 'filename' => $filename]]])]);
        } catch (ConnectionException $e) {
            throw new DiscordException(0, null, 'Discord could not be reached: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            throw DiscordException::from($response->status(), (array) $response->json());
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(string $channelId, string $messageId, array $payload): array
    {
        return $this->json('PATCH', "channels/{$channelId}/messages/{$messageId}", $payload);
    }

    public function typing(string $channelId): void
    {
        try {
            $this->call('POST', "channels/{$channelId}/typing");
        } catch (DiscordException) {
            // Only a courtesy.
        }
    }

    public function dmChannel(string $userId): string
    {
        return $this->dmChannels[$userId] ??= (string) $this->json('POST', 'users/@me/channels', ['recipient_id' => $userId])['id'];
    }

    /**
     * A DM, if the user accepts them. Never throws: a closed DM must not stop the action it
     * tells them about.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendDm(string $userId, array $payload): bool
    {
        try {
            $this->createMessage($this->dmChannel($userId), $payload);

            return true;
        } catch (DiscordException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function interactionCallback(string $interactionId, string $token, array $payload): void
    {
        $this->call('POST', "interactions/{$interactionId}/{$token}/callback", $payload);
    }

    // --- the server ----------------------------------------------------------

    /**
     * @return array<string, mixed>|null Null when they are not in the server.
     */
    public function member(string $guildId, string $userId): ?array
    {
        try {
            return $this->json('GET', "guilds/{$guildId}/members/{$userId}");
        } catch (DiscordException $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchMembers(string $guildId, string $query, int $limit = 5): array
    {
        return array_values($this->json('GET', "guilds/{$guildId}/members/search", null, null, ['query' => $query, 'limit' => $limit]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(string $userId): ?array
    {
        try {
            return $this->json('GET', "users/{$userId}");
        } catch (DiscordException $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The server's channels, cached for a minute.
     *
     * @return list<array<string, mixed>>
     */
    public function channels(string $guildId, bool $fresh = false): array
    {
        $key = "plugged-host-ai:{$this->bot}:channels:{$guildId}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, fn (): array => array_values($this->json('GET', "guilds/{$guildId}/channels")));
    }

    /**
     * The server's roles, cached for a minute.
     *
     * @return list<array<string, mixed>>
     */
    public function roles(string $guildId, bool $fresh = false): array
    {
        $key = "plugged-host-ai:{$this->bot}:roles:{$guildId}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, fn (): array => array_values($this->json('GET', "guilds/{$guildId}/roles")));
    }

    /**
     * @return array<string, mixed>
     */
    public function channel(string $channelId): array
    {
        return $this->json('GET', "channels/{$channelId}");
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function modifyMember(string $guildId, string $userId, array $changes, ?string $reason): void
    {
        $this->call('PATCH', "guilds/{$guildId}/members/{$userId}", $changes, $reason);
    }

    public function kick(string $guildId, string $userId, ?string $reason): void
    {
        $this->call('DELETE', "guilds/{$guildId}/members/{$userId}", null, $reason);
    }

    public function ban(string $guildId, string $userId, int $deleteMessageSeconds, ?string $reason): void
    {
        $this->call('PUT', "guilds/{$guildId}/bans/{$userId}", ['delete_message_seconds' => $deleteMessageSeconds], $reason);
    }

    public function unban(string $guildId, string $userId, ?string $reason): void
    {
        $this->call('DELETE', "guilds/{$guildId}/bans/{$userId}", null, $reason);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function modifyRole(string $guildId, string $roleId, array $changes, ?string $reason): array
    {
        $role = $this->json('PATCH', "guilds/{$guildId}/roles/{$roleId}", $changes, $reason);
        Cache::forget("plugged-host-ai:{$this->bot}:roles:{$guildId}");

        return $role;
    }

    public function addRole(string $guildId, string $userId, string $roleId, ?string $reason): void
    {
        $this->call('PUT', "guilds/{$guildId}/members/{$userId}/roles/{$roleId}", null, $reason);
    }

    public function removeRole(string $guildId, string $userId, string $roleId, ?string $reason): void
    {
        $this->call('DELETE', "guilds/{$guildId}/members/{$userId}/roles/{$roleId}", null, $reason);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function messages(string $channelId, array $query): array
    {
        return array_values($this->json('GET', "channels/{$channelId}/messages", null, null, $query));
    }

    /**
     * @return array<string, mixed>|null Null when it does not exist (any more).
     */
    public function message(string $channelId, string $messageId): ?array
    {
        try {
            return $this->json('GET', "channels/{$channelId}/messages/{$messageId}");
        } catch (DiscordException $e) {
            if ($e->isNotFound()) {
                return null;
            }

            throw $e;
        }
    }

    public function pin(string $channelId, string $messageId, bool $pinned, ?string $reason): void
    {
        $this->call($pinned ? 'PUT' : 'DELETE', "channels/{$channelId}/messages/pins/{$messageId}", null, $reason);
    }

    /**
     * The server's bans, up to $max of them.
     *
     * @return list<array<string, mixed>>
     */
    public function bans(string $guildId, int $max = 2000): array
    {
        $bans = [];
        $after = null;

        while (count($bans) < $max) {
            $page = array_values($this->json('GET', "guilds/{$guildId}/bans", null, null, array_filter(['limit' => 1000, 'after' => $after])));
            array_push($bans, ...$page);

            if (count($page) < 1000) {
                break;
            }

            $after = (string) end($page)['user']['id'];
        }

        return array_slice($bans, 0, $max);
    }

    /**
     * @param  list<string>  $messageIds  1 to 100 ids, none older than 14 days.
     */
    public function deleteMessages(string $channelId, array $messageIds, ?string $reason): void
    {
        if (count($messageIds) === 1) {
            $this->call('DELETE', "channels/{$channelId}/messages/{$messageIds[0]}", null, $reason);

            return;
        }

        $this->call('POST', "channels/{$channelId}/messages/bulk-delete", ['messages' => array_values($messageIds)], $reason);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function modifyChannel(string $channelId, array $changes, ?string $reason): void
    {
        $this->call('PATCH', "channels/{$channelId}", $changes, $reason);
    }

    public function setPermissionOverwrite(string $channelId, string $overwriteId, int $type, string $allow, string $deny, ?string $reason): void
    {
        $this->call('PUT', "channels/{$channelId}/permissions/{$overwriteId}", ['type' => $type, 'allow' => $allow, 'deny' => $deny], $reason);
    }
}
