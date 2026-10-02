<?php

namespace PluggedHost\Ai\Rux\Tools;

/**
 * What a tool tells the model.
 */
final class ToolResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $content,
    ) {}

    /**
     * @param  string|array<string, mixed>  $content  Arrays are sent as JSON.
     */
    public static function ok(string|array $content): self
    {
        return new self(true, is_array($content) ? (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $content);
    }

    public static function error(string $message): self
    {
        return new self(false, $message);
    }
}
