<?php

namespace PluggedHost\Servers\Services\Transfers;

/**
 * Reads the lines wings' source node sends as "transfer logs" while it pushes a server.
 *
 * Wings has no progress API; the source only prints, every 5 seconds while the push runs,
 * `Uploading [=====     ] 1.2 GiB / 3.4 GiB` (uncompressed bytes archived so far, against
 * the directory size it measured), plus a line per backup and one when it is done. These are
 * the strings of wings server/transfer/{source,archive}.go (v1.0.0-beta29); an unknown line
 * is returned as a plain message, never as an error, so a wording change degrades to "no
 * live numbers" and not to a broken transfer.
 */
final class TransferLog
{
    public const UPLOAD = 'upload';

    public const BACKUPS_START = 'backups_start';

    public const BACKUP_DONE = 'backup_done';

    public const STREAMED = 'streamed';

    public const CANCELED = 'canceled';

    public const MESSAGE = 'message';

    private const UNIT = '(B|KiB|MiB|GiB|TiB|PiB|EiB)';

    /** Strip the colour codes and the "<date> [Transfer System] [Source Node]:" prefix. */
    public static function clean(string $line): string
    {
        $line = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $line);
        $line = (string) preg_replace('/^.*?\[Transfer System\]\s*\[[^\]]*\]:\s*/', '', $line);

        return trim($line);
    }

    /**
     * @return array{type: string, done?: int, total?: int, count?: int, index?: int, text?: string}
     */
    public static function parse(string $line): array
    {
        $text = self::clean($line);

        if (preg_match('/Uploading \[[= ]*\]\s*([\d.]+) ' . self::UNIT . ' \/ ([\d.]+) ' . self::UNIT . '/', $text, $m)) {
            return ['type' => self::UPLOAD, 'done' => self::bytes($m[1], $m[2]), 'total' => self::bytes($m[3], $m[4])];
        }

        if (preg_match('/Backup (\d+)\/(\d+) completed/', $text, $m)) {
            return ['type' => self::BACKUP_DONE, 'index' => (int) $m[1], 'count' => (int) $m[2]];
        }

        if (preg_match('/(?:Streaming|Starting transfer of) (\d+) backup files/', $text, $m)) {
            return ['type' => self::BACKUPS_START, 'count' => (int) $m[1]];
        }

        if (str_contains($text, 'Finished streaming archive and backups')) {
            return ['type' => self::STREAMED];
        }

        if (preg_match('/^Canceled\.?$/', $text)) {
            return ['type' => self::CANCELED];
        }

        return ['type' => self::MESSAGE, 'text' => $text];
    }

    /** wings' system.FormatBytes back to a byte count (it prints one decimal, base 1024). */
    public static function bytes(string $number, string $unit): int
    {
        $power = array_search($unit, ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'], true);

        return (int) round((float) $number * (1024 ** (int) $power));
    }
}
