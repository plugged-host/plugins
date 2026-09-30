<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Exceptions\Repository\FileNotEditableException;
use App\Exceptions\Http\Server\FileSizeTooLargeException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\ToolContext;

/**
 * Reading a whole text file safely: Wings' contents endpoint downloads the entire file before
 * the size header is checked, so the size is taken from the parent directory listing first.
 */
final class FileSupport
{
    public static function readText(ToolContext $context, string $path): string
    {
        $max = max(1024, (int) config('plugged-host-neo.max_read_bytes', 1048576));
        $entry = self::stat($context, $path);

        if ($entry === null) {
            throw new InvalidArgumentException("{$path} does not exist.");
        }

        if ($entry['directory'] ?? false) {
            throw new InvalidArgumentException("{$path} is a directory; use list_files.");
        }

        if ((int) ($entry['size'] ?? 0) > $max) {
            throw new InvalidArgumentException("{$path} is too large to read here (" . (int) $entry['size'] . ' bytes).');
        }

        try {
            $content = (string) $context->files()->getContent($path, $max);
        } catch (FileNotFoundException) {
            throw new InvalidArgumentException("{$path} does not exist.");
        } catch (FileNotEditableException) {
            throw new InvalidArgumentException("{$path} is not a text file.");
        } catch (FileSizeTooLargeException) {
            throw new InvalidArgumentException("{$path} is too large to read here.");
        }

        if (str_contains($content, "\0")) {
            throw new InvalidArgumentException("{$path} is a binary file.");
        }

        return function_exists('convert_to_utf8') ? convert_to_utf8($content) : $content;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function stat(ToolContext $context, string $path): ?array
    {
        $parent = dirname($path);
        $name = basename($path);

        foreach ((array) $context->files()->getDirectory($parent === '.' ? '/' : $parent) as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return (array) $entry;
            }
        }

        return null;
    }
}
