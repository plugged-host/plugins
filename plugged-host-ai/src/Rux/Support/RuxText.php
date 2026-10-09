<?php

namespace PluggedHost\Ai\Rux\Support;

use Illuminate\Support\Str;

/**
 * Text members wrote, made safe to hand to the model inside a tool output: mentions, channels
 * and custom emoji become plain text, and the angle brackets left are swapped for look-alikes,
 * so the text cannot close the <tool_output> fence it sits in or open a fake one.
 */
final class RuxText
{
    public static function untrusted(string $text, int $limit = 300): string
    {
        $text = (string) preg_replace(
            ['/<@!?(\d{17,20})>/', '/<@&(\d{17,20})>/', '/<#(\d{17,20})>/', '/<a?:(\w{2,32}):\d{17,20}>/', '/<t:(-?\d{1,13})(?::[tTdDfFR])?>/'],
            ['@$1', '@&$1', '#$1', ':$1:', 't:$1'],
            $text,
        );

        return Str::limit(str_replace(['<', '>'], ['‹', '›'], trim($text)), max(20, $limit), '…');
    }

    /**
     * What a Discord message says: its text, then the text of its embeds (rules and
     * announcements are often posted as embeds).
     *
     * @param  array<string, mixed>  $message
     */
    public static function messageText(array $message): string
    {
        $parts = [(string) ($message['content'] ?? '')];

        foreach ((array) ($message['embeds'] ?? []) as $embed) {
            if (!is_array($embed)) {
                continue;
            }

            $parts[] = implode("\n", array_filter([
                isset($embed['title']) ? '[embed] ' . $embed['title'] : '[embed]',
                $embed['description'] ?? null,
                ...array_map(static fn ($field): string => is_array($field) ? ($field['name'] ?? '') . ': ' . ($field['value'] ?? '') : '', (array) ($embed['fields'] ?? [])),
            ], static fn ($part): bool => is_string($part) && trim($part) !== ''));
        }

        return trim(implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }
}
