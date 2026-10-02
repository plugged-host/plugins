<?php

namespace PluggedHost\Ai\Rux\Discord;

use InvalidArgumentException;

/**
 * Turns what an admin wrote ("<@123>", "123", "@ann", "Ann", "#giveaways", "🎉│giveaways",
 * "@Moderators") into the Discord object it means, or says why it cannot.
 */
final class Resolver
{
    /** Text channels a message can be posted in: text, announcement. */
    public const POSTABLE = [0, 5];

    public function __construct(private readonly RuxDiscord $discord) {}

    /**
     * A member of the server.
     *
     * @return array{id: string, name: string, member: array<string, mixed>}
     */
    public function member(string $guildId, string $input): array
    {
        $id = $this->snowflake($input, '@!?');

        if ($id !== null) {
            $member = $this->discord->member($guildId, $id)
                ?? throw new InvalidArgumentException("<@{$id}> is not in the server.");

            return ['id' => $id, 'name' => self::memberName($member), 'member' => $member];
        }

        $query = ltrim(trim($input), '@');
        if ($query === '') {
            throw new InvalidArgumentException('No member given.');
        }

        $found = $this->discord->searchMembers($guildId, $query, 10);
        $needle = mb_strtolower($query);

        $exact = array_values(array_filter($found, static function (array $member) use ($needle): bool {
            return in_array($needle, array_map('mb_strtolower', array_filter([
                $member['user']['username'] ?? null,
                $member['user']['global_name'] ?? null,
                $member['nick'] ?? null,
            ])), true);
        }));

        $pick = count($exact) === 1 ? $exact[0] : (count($found) === 1 ? $found[0] : null);

        if ($pick !== null) {
            return ['id' => (string) $pick['user']['id'], 'name' => self::memberName($pick), 'member' => $pick];
        }

        if ($found === []) {
            throw new InvalidArgumentException("No member's name starts with \"{$query}\". Ask for their @mention or id.");
        }

        $list = implode(', ', array_map(static fn (array $m): string => self::memberName($m) . ' (<@' . $m['user']['id'] . '>)', array_slice($found, 0, 8)));

        throw new InvalidArgumentException("Several members match \"{$query}\": {$list}. Ask which one is meant.");
    }

    /**
     * Any Discord user, in the server or not (for unbans): only by mention or id.
     *
     * @return array{id: string, name: string}
     */
    public function user(string $input): array
    {
        $id = $this->snowflake($input, '@!?')
            ?? throw new InvalidArgumentException('Give a user id or <@mention> for someone who is not in the server.');

        $user = $this->discord->user($id) ?? throw new InvalidArgumentException("There is no Discord user {$id}.");

        return ['id' => $id, 'name' => (string) ($user['global_name'] ?? $user['username'] ?? $id)];
    }

    /**
     * A channel of the server, by mention, id or name.
     *
     * @param  list<int>|null  $types  Allowed channel types (null = any).
     * @return array<string, mixed>
     */
    public function channel(string $guildId, string $input, ?array $types = self::POSTABLE): array
    {
        $channels = $this->discord->channels($guildId);
        $id = $this->snowflake($input, '#');

        $matches = $id !== null
            ? array_values(array_filter($channels, static fn (array $c): bool => (string) $c['id'] === $id))
            : $this->byName($channels, ltrim(trim($input), '#'));

        if ($id !== null && $matches === []) {
            $matches = array_values(array_filter($this->discord->channels($guildId, fresh: true), static fn (array $c): bool => (string) $c['id'] === $id));
        }

        if ($types !== null) {
            $matches = array_values(array_filter($matches, static fn (array $c): bool => in_array((int) ($c['type'] ?? -1), $types, true)));
        }

        return match (count($matches)) {
            1 => $matches[0],
            0 => throw new InvalidArgumentException("There is no text channel \"{$input}\" in the server. Use server_overview to see the channels."),
            default => throw new InvalidArgumentException("Several channels match \"{$input}\": " . implode(', ', array_map(static fn (array $c): string => '<#' . $c['id'] . '>', array_slice($matches, 0, 8))) . '. Ask which one is meant.'),
        };
    }

    /**
     * A role of the server, by mention, id or name.
     *
     * @return array<string, mixed>
     */
    public function role(string $guildId, string $input): array
    {
        $roles = $this->discord->roles($guildId);
        $id = $this->snowflake($input, '@&');

        $matches = $id !== null
            ? array_values(array_filter($roles, static fn (array $r): bool => (string) $r['id'] === $id))
            : $this->byName($roles, ltrim(trim($input), '@'));

        return match (count($matches)) {
            1 => $matches[0],
            0 => throw new InvalidArgumentException("There is no role \"{$input}\". Use server_overview to see the roles."),
            default => throw new InvalidArgumentException("Several roles match \"{$input}\": " . implode(', ', array_map(static fn (array $r): string => $r['name'] . ' (' . $r['id'] . ')', array_slice($matches, 0, 8))) . '. Ask which one is meant.'),
        };
    }

    /**
     * @param  array<string, mixed>  $member
     */
    public static function memberName(array $member): string
    {
        return (string) ($member['nick'] ?? $member['user']['global_name'] ?? $member['user']['username'] ?? $member['user']['id'] ?? 'unknown');
    }

    private function snowflake(string $input, string $prefix): ?string
    {
        $value = trim($input);

        if (preg_match('/^<' . $prefix . '(\d{17,20})>$/', $value, $m) === 1 || preg_match('/^(\d{17,20})$/', $value, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Exact name first, then the same name without emoji, separators and case, then a unique
     * partial match.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function byName(array $items, string $name): array
    {
        $exact = array_values(array_filter($items, static fn (array $i): bool => mb_strtolower((string) ($i['name'] ?? '')) === mb_strtolower($name)));
        if ($exact !== []) {
            return $exact;
        }

        $plain = self::plain($name);
        if ($plain === '') {
            return [];
        }

        $loose = array_values(array_filter($items, static fn (array $i): bool => self::plain((string) ($i['name'] ?? '')) === $plain));
        if ($loose !== []) {
            return $loose;
        }

        return array_values(array_filter($items, static fn (array $i): bool => str_contains(self::plain((string) ($i['name'] ?? '')), $plain)));
    }

    private static function plain(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }
}
