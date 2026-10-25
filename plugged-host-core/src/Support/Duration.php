<?php

namespace PluggedHost\Core\Support;

/**
 * Durations an admin types into a settings field: "7d", "36h", a bare number (days) or "0".
 * Settings keep hours in .env, so parse() turns the field into hours and format() turns hours
 * back into the shortest way to write them. Used by the delete-after timers of limbo and abuse.
 */
final class Duration
{
    /** What the settings fields accept: a whole number with an optional d or h. */
    public const RULE = 'regex:/^\s*\d{1,5}\s*[dDhH]?\s*$/';

    /** Hours in "7d" / "36h" / "7" (days) / "0", or null when the text is not a duration. */
    public static function parse(?string $text): ?int
    {
        if ($text === null || preg_match('/^\s*(\d{1,5})\s*([dDhH]?)\s*$/', $text, $match) !== 1) {
            return null;
        }

        $number = (int) $match[1];

        return strtolower($match[2]) === 'h' ? $number : $number * 24;
    }

    /** "7d" for 168, "36h" for 36, "0" for 0. */
    public static function format(int $hours): string
    {
        if ($hours <= 0) {
            return '0';
        }

        return $hours % 24 === 0 ? ($hours / 24) . 'd' : $hours . 'h';
    }
}
