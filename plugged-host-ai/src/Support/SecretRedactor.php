<?php

namespace PluggedHost\Ai\Support;

/**
 * Masks secrets in console output and file contents before they reach an AI provider:
 * key=value / key: value / "key": "value" entries whose key looks secret (passwords, RCON,
 * tokens, API keys, webhooks), JWTs, Discord bot tokens and webhook URLs, and common API key
 * prefixes. On by default; the trade-off is that neo cannot edit a value it cannot see.
 */
final class SecretRedactor
{
    public const MASK = '••••';

    private const SECRET_KEY = '[\w.\-]*(?:password|passwd|secret|token|api[_\-]?key|apikey|rcon[_.\-]?pass|private[_\-]?key|webhook|credential|license[_\-]?key)[\w.\-]*';

    public static function enabled(): bool
    {
        return (bool) config('plugged-host-ai.redact_secrets', true);
    }

    public static function redact(string $text): string
    {
        if (!self::enabled() || $text === '') {
            return $text;
        }

        $patterns = [
            // "key": "value" (JSON)
            '/("' . self::SECRET_KEY . '"\s*:\s*")([^"]+)(")/i' => '$1' . self::MASK . '$3',
            // key=value / key: value (properties, yaml, toml, env)
            '/^(\s*' . self::SECRET_KEY . '\s*[=:]\s*)(["\']?)([^\s"\'#]+)(\2)/im' => '$1$2' . self::MASK . '$4',
            // Discord webhook URLs: keep the id, drop the token.
            '#(discord(?:app)?\.com/api/webhooks/\d+/)[\w\-]+#i' => '$1' . self::MASK,
            // JWTs
            '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\b/' => self::MASK,
            // Discord bot tokens
            '/\b[MNO][A-Za-z\d_\-]{23,27}\.[\w\-]{6}\.[\w\-]{27,40}\b/' => self::MASK,
            // Well-known API key prefixes
            '/\b(?:sk-[A-Za-z0-9_\-]{16,}|gsk_[A-Za-z0-9]{16,}|ghp_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9\-]{10,})\b/' => self::MASK,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    public static function containsMask(string $text): bool
    {
        return str_contains($text, self::MASK);
    }
}
