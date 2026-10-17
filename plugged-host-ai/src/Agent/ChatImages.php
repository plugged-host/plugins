<?php

namespace PluggedHost\Ai\Agent;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Support\ImageInput;

/**
 * Images attached in the panel chat. Shrunk once on upload (ImageInput) and kept as JPEGs on the
 * private disk under neo-images/{conversation}/, so the model and the chat both read the small
 * copy. They go with their conversation: deleting a chat or the whole history removes them, and
 * the prune sweeps folders whose conversation is gone.
 */
final class ChatImages
{
    private const DIR = 'neo-images';

    /**
     * @return array{path: string}|null Null when the file is not an image neo can read.
     */
    public static function store(NeoConversation $conversation, UploadedFile $file): ?array
    {
        $bytes = $file->getSize() <= ImageInput::MAX_BYTES ? @file_get_contents($file->getRealPath()) : false;
        $jpeg = is_string($bytes) ? ImageInput::jpeg($bytes) : null;

        if ($jpeg === null) {
            return null;
        }

        $path = self::DIR . '/' . $conversation->id . '/' . Str::uuid() . '.jpg';

        return self::disk()->put($path, $jpeg) ? ['path' => $path] : null;
    }

    /**
     * A stored image's path when it is one of ours and still there.
     */
    public static function existing(mixed $path): ?string
    {
        return is_string($path) && preg_match('#^' . self::DIR . '/\d+/[0-9a-f-]{36}\.jpg$#', $path) === 1 && self::disk()->exists($path) ? $path : null;
    }

    /**
     * A stored image as the model takes it; null when it is gone.
     */
    public static function dataUrl(mixed $path): ?string
    {
        $bytes = ($path = self::existing($path)) === null ? null : self::disk()->get($path);

        return is_string($bytes) ? 'data:image/jpeg;base64,' . base64_encode($bytes) : null;
    }

    /**
     * @param  iterable<int>  $conversationIds
     */
    public static function forget(iterable $conversationIds): void
    {
        foreach ($conversationIds as $id) {
            self::disk()->deleteDirectory(self::DIR . '/' . (int) $id);
        }
    }

    /**
     * Remove the folders of conversations that no longer exist (bulk deletes fire no model
     * events). Returns how many went.
     */
    public static function sweep(): int
    {
        $ids = collect(self::disk()->directories(self::DIR))->map(fn (string $dir): int => (int) basename($dir))->filter()->values();
        $gone = $ids->diff(NeoConversation::query()->whereIn('id', $ids)->pluck('id'));

        self::forget($gone);

        return $gone->count();
    }

    private static function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
