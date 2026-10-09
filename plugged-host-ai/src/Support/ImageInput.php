<?php

namespace PluggedHost\Ai\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Images users show neo and rux (screenshots), from Discord attachments and panel uploads,
 * turned into what the models take: at most four inline JPEGs no larger than 1568 px a side
 * (MessageContent::with). Discord files are only fetched from Discord's own CDN.
 */
final class ImageInput
{
    public const MAX_IMAGES = 4;

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private const MAX_SIDE = 1568;

    /** Decoding needs about 4 bytes a pixel; this keeps a panel request well inside 128 MB. */
    private const MAX_PIXELS = 12_000_000;

    private const DISCORD_HOSTS = ['cdn.discordapp.com', 'media.discordapp.net'];

    /**
     * A Discord message's image attachments, as kept in a stored message's meta.
     *
     * @param  array<string, mixed>  $message
     * @return list<array{url: string}>
     */
    public static function discordAttachments(array $message): array
    {
        $images = [];

        foreach ((array) ($message['attachments'] ?? []) as $file) {
            $type = strtolower(trim(explode(';', (string) ($file['content_type'] ?? ''))[0]));
            $url = (string) ($file['url'] ?? '');

            if (in_array($type, self::TYPES, true) && self::isDiscordUrl($url) && (int) ($file['size'] ?? 0) <= self::MAX_BYTES) {
                $images[] = ['url' => $url];
            }
        }

        return array_slice($images, 0, self::MAX_IMAGES);
    }

    /**
     * Download and shrink attachments from discordAttachments(). Discord's links expire after
     * about a day, so this runs when the message is answered; ones that are gone or are not
     * images are left out.
     *
     * @param  list<array{url?: string}>  $attachments
     * @return list<string> Data URLs.
     */
    public static function fromDiscord(array $attachments): array
    {
        $images = [];

        foreach (array_slice($attachments, 0, self::MAX_IMAGES) as $file) {
            $url = (string) ($file['url'] ?? '');

            if (!self::isDiscordUrl($url)) {
                continue;
            }

            try {
                $response = Http::withoutRedirecting()->connectTimeout(5)->timeout(15)->get($url);
            } catch (Throwable) {
                continue;
            }

            if ($response->successful() && strlen($response->body()) <= self::MAX_BYTES && ($image = self::dataUrl($response->body())) !== null) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * Image bytes as a JPEG data URL at most 1568 px a side; null when they are not a PNG,
     * JPEG, WebP or GIF (first frame) image of a sane size.
     */
    public static function dataUrl(string $bytes): ?string
    {
        $jpeg = self::jpeg($bytes);

        return $jpeg === null ? null : 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    /**
     * The same image as JPEG bytes (dataUrl()), for keeping on disk.
     */
    public static function jpeg(string $bytes): ?string
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false || !in_array($size['mime'] ?? '', self::TYPES, true) || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $scale = min(1, self::MAX_SIDE / max($size[0], $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));

        // Transparent areas turn white rather than JPEG's black.
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
        unset($source);

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }

    private static function isDiscordUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), self::DISCORD_HOSTS, true) && !isset($parts['port']) && !isset($parts['user']);
    }
}
