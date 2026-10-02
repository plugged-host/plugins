<?php

namespace PluggedHost\Ai\Tools;

use App\Facades\Activity;
use App\Services\Activity\ActivityLogService;
use InvalidArgumentException;
use PluggedHost\Ai\Support\SecretRedactor;

/**
 * Shared plumbing for the server tools: path handling, argument reading, output caps,
 * redaction and the activity log.
 */
abstract class BaseServerTool implements Tool
{
    public function scope(): string
    {
        return self::SCOPE_SERVER;
    }

    public function availableFor(ToolContext $context): bool
    {
        return true;
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Read;
    }

    public function offeredWith(): array
    {
        return $this->requires([]);
    }

    /**
     * Normalise a model-supplied path to "/a/b". Wings confines every path to the server's
     * own volume anyway; rejecting ".." and NUL here keeps the intent unambiguous.
     */
    protected function path(mixed $value, string $default = '/'): string
    {
        $path = is_string($value) && trim($value) !== '' ? trim($value) : $default;

        if (str_contains($path, "\0") || strlen($path) > 1024) {
            throw new InvalidArgumentException('Invalid path.');
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new InvalidArgumentException('Paths may not contain "..". Use a path from the server root, like /plugins/config.yml.');
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    protected function string(array $arguments, string $key, int $max, bool $required = true): string
    {
        $value = $arguments[$key] ?? null;

        if (!is_string($value) || ($required && $value === '')) {
            if (!$required && $value === null) {
                return '';
            }

            throw new InvalidArgumentException("\"{$key}\" must be a non-empty string.");
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("\"{$key}\" is too long (max {$max} characters).");
        }

        return $value;
    }

    protected function limitOutput(string $text): string
    {
        $max = max(1000, (int) config('plugged-host-ai.tool_output_chars', 12000));

        return mb_strlen($text) > $max
            ? mb_substr($text, 0, $max) . "\n…[truncated, " . (mb_strlen($text) - $max) . ' more characters]'
            : $text;
    }

    protected function redact(string $text): string
    {
        return SecretRedactor::redact($text);
    }

    /**
     * An activity entry attributed to the user, marked as done by neo.
     */
    protected function activity(ToolContext $context, string $event): ActivityLogService
    {
        return Activity::event('server:neo.' . $event)
            ->subject($context->server)
            ->actor($context->user)
            ->property('conversation', $context->conversation->uuid);
    }

    protected function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
