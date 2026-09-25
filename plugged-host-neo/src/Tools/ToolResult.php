<?php

namespace PluggedHost\Neo\Tools;

final class ToolResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $content,
        public readonly ?string $display = null,
        public readonly ?string $redirect = null,
    ) {}

    /**
     * @param  string  $content  What the model reads.
     * @param  string|null  $display  A short line for the chat UI (defaults to the tool summary).
     */
    public static function ok(string $content, ?string $display = null): self
    {
        return new self(true, $content, $display);
    }

    public static function error(string $message): self
    {
        return new self(false, $message, $message);
    }

    /**
     * The action was handed to another part of the panel (start queue, ad waiting room,
     * hibernation restore) and the user must be taken there.
     */
    public static function redirect(string $content, string $url): self
    {
        return new self(true, $content, $content, $url);
    }
}
