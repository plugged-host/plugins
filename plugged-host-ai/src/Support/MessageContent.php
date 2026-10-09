<?php

namespace PluggedHost\Ai\Support;

/**
 * A chat message's content in the canonical shape neo's gateway and rux's providers take: a
 * string, or for a user message with images, OpenAI-style parts ({type: text, text},
 * {type: image_url, image_url: {url: data:…}}). Mirrors noprofit.space's
 * App\Services\Neo\MessageContent, which takes the same shape on the gateway side.
 */
final class MessageContent
{
    /** What a model that cannot see images gets in place of one. */
    public const IMAGE_PLACEHOLDER = '[An image was attached here, but the model answering now cannot see images.]';

    /**
     * The images (data URLs, ImageInput) followed by the text; plain text without images.
     *
     * @param  list<string>  $images
     * @return string|list<array<string, mixed>>
     */
    public static function with(string $text, array $images): string|array
    {
        if ($images === []) {
            return $text;
        }

        $parts = array_map(static fn (string $url): array => ['type' => 'image_url', 'image_url' => ['url' => $url]], array_values($images));

        if (trim($text) !== '') {
            $parts[] = ['type' => 'text', 'text' => $text];
        }

        return $parts;
    }

    /**
     * @return list<array{type: 'text', text: string}|array{type: 'image', mime: string, data: string}>
     */
    public static function parts(mixed $content): array
    {
        if (!is_array($content)) {
            $text = (string) $content;

            return $text === '' ? [] : [['type' => 'text', 'text' => $text]];
        }

        $parts = [];

        foreach ($content as $part) {
            $type = is_array($part) ? ($part['type'] ?? null) : null;

            if ($type === 'text' && is_string($part['text'] ?? null) && $part['text'] !== '') {
                $parts[] = ['type' => 'text', 'text' => $part['text']];
            } elseif ($type === 'image_url' && preg_match('#^data:(image/[a-z]+);base64,([A-Za-z0-9+/]+={0,2})$#', (string) ($part['image_url']['url'] ?? ''), $match) === 1) {
                $parts[] = ['type' => 'image', 'mime' => $match[1], 'data' => $match[2]];
            }
        }

        return $parts;
    }

    /**
     * The text parts joined, images left out.
     */
    public static function text(mixed $content): string
    {
        return implode("\n\n", array_column(array_filter(self::parts($content), static fn (array $part): bool => $part['type'] === 'text'), 'text'));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public static function hasImages(array $messages): bool
    {
        foreach ($messages as $message) {
            if (is_array($message['content'] ?? null) && in_array('image', array_column(self::parts($message['content']), 'type'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The messages for a model that cannot see images: plain text, with a note where each was.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function withoutImages(array $messages): array
    {
        return array_map(static fn (array $message): array => is_array($message['content'] ?? null)
            ? ['content' => implode("\n\n", array_map(
                static fn (array $part): string => $part['type'] === 'text' ? $part['text'] : self::IMAGE_PLACEHOLDER,
                self::parts($message['content']),
            ))] + $message
            : $message, $messages);
    }
}
