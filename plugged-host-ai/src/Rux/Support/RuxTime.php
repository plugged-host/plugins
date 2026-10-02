<?php

namespace PluggedHost\Ai\Rux\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Times and durations as rux reads and writes them: everything in RuxConfig::timezone()
 * (Europe/Paris) unless a string carries its own offset.
 */
final class RuxTime
{
    /**
     * "2026-10-04 17:00", "2026-10-04T17:00", an ISO-8601 time with an offset, or a duration
     * from now ("2h", "1d12h", "in 30m").
     */
    public static function parse(string $input, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= RuxConfig::now();
        $value = trim($input);

        if ($value === '') {
            throw new InvalidArgumentException('No time given.');
        }

        $relative = preg_replace('/^in\s+/i', '', $value);
        if (($seconds = self::tryDuration((string) $relative)) !== null) {
            return $now->addSeconds($seconds);
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})(:\d{2})?$/', $value, $m) === 1) {
            $local = sprintf('%s %02d:%s', $m[1], (int) $m[2], $m[3]);

            try {
                $time = CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, RuxConfig::timezone());
            } catch (Throwable) {
                $time = null;
            }

            // createFromFormat rolls 2026-02-31 over into March instead of failing.
            if (!$time instanceof CarbonImmutable || $time->format('Y-m-d H:i') !== $local) {
                throw new InvalidArgumentException("\"{$input}\" is not a real date and time. Use YYYY-MM-DD HH:MM.");
            }

            return $time;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
            return CarbonImmutable::parse($value)->setTimezone(RuxConfig::timezone());
        }

        throw new InvalidArgumentException("Cannot read the time \"{$input}\". Use YYYY-MM-DD HH:MM ({$now->tzName} time) or a duration like 2h.");
    }

    /**
     * Seconds in "90s", "10m", "2h", "1d12h", "1w"; a bare number is minutes.
     */
    public static function duration(string $input): int
    {
        $seconds = self::tryDuration(trim($input));

        if ($seconds === null) {
            throw new InvalidArgumentException("Cannot read the duration \"{$input}\". Use something like 10m, 2h or 7d.");
        }

        return $seconds;
    }

    private static function tryDuration(string $value): ?int
    {
        $value = strtolower(str_replace(' ', '', $value));

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value * 60;
        }

        if ($value === '' || preg_match('/^(\d+(w|d|h|m|s|min|mins|hr|hrs|sec|secs))+$/', $value) !== 1) {
            return null;
        }

        preg_match_all('/(\d+)(w|d|hrs|hr|h|mins|min|m|secs|sec|s)/', $value, $parts, PREG_SET_ORDER);

        $seconds = 0;
        foreach ($parts as [, $amount, $unit]) {
            $seconds += (int) $amount * match ($unit) {
                'w' => 604800,
                'd' => 86400,
                'h', 'hr', 'hrs' => 3600,
                'm', 'min', 'mins' => 60,
                default => 1,
            };
        }

        return $seconds;
    }

    /**
     * "Sun 4 Oct 2026, 17:00 Paris time" for rux's own replies; Discord timestamps (<t:…>) are
     * used wherever members read a time, so each of them sees it in their own zone.
     */
    public static function human(CarbonImmutable|\DateTimeInterface $time): string
    {
        $local = CarbonImmutable::instance($time)->setTimezone(RuxConfig::timezone());
        $city = str_contains(RuxConfig::timezone(), '/') ? str_replace('_', ' ', substr(strrchr(RuxConfig::timezone(), '/'), 1)) : RuxConfig::timezone();

        return $local->format('D j M Y, H:i') . " {$city} time";
    }

    public static function humanDuration(int $seconds): string
    {
        return CarbonImmutable::now()->addSeconds($seconds)->diffForHumans(CarbonImmutable::now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]);
    }

    public static function discord(\DateTimeInterface $time, string $style = 'f'): string
    {
        return '<t:' . $time->getTimestamp() . ':' . $style . '>';
    }
}
