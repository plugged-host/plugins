<?php

namespace PluggedHost\Ai\Discord;

use RuntimeException;

/**
 * Discord answered with an error. The message is written for the model (and so for the admin):
 * what Discord said, and what usually causes it.
 */
final class DiscordException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $discordCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function from(int $status, array $body): self
    {
        $code = isset($body['code']) ? (int) $body['code'] : null;
        $said = trim((string) ($body['message'] ?? 'HTTP ' . $status));

        $hint = match (true) {
            $code === 50013 => ' rux is missing a permission there, or its role is not above the member\'s highest role.',
            $code === 50001 => ' rux cannot see that channel.',
            $code === 50007 => ' They do not accept DMs from rux.',
            $code === 50034 => ' Messages older than 14 days cannot be bulk deleted.',
            $code === 10007 => ' That member is not in the server.',
            $code === 10013 => ' There is no such Discord user.',
            $code === 10026 => ' That user is not banned.',
            $code === 10003 => ' There is no such channel.',
            $code === 10011 => ' There is no such role.',
            $code === 10008 => ' That message no longer exists.',
            $status === 401 => ' The rux bot token is invalid.',
            default => '',
        };

        return new self($status, $code, "Discord refused: {$said}" . ($code !== null ? " ({$code})." : '.') . $hint);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
