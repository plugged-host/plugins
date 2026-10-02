<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;

final class ReadFileTool extends BaseServerTool
{
    private const MAX_LINES = 400;

    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return 'Read a text file on the server with line numbers (up to 400 lines per call; use start_line to page). Secrets such as passwords and tokens are masked as ••••.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path from the server root, e.g. "/server.properties".'],
                'start_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'First line to return (default 1).'],
                'max_lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LINES, 'description' => 'How many lines (default 400).'],
            ],
            'required' => ['path'],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileReadContent];
    }

    public function summarize(array $arguments): string
    {
        return 'Read ' . $this->path($arguments['path'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $path = $this->path($arguments['path'] ?? null);
        $content = FileSupport::readText($context, $path);

        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $start = max(1, (int) ($arguments['start_line'] ?? 1));
        $count = max(1, min(self::MAX_LINES, (int) ($arguments['max_lines'] ?? self::MAX_LINES)));
        $slice = array_slice($lines, $start - 1, $count);

        if ($slice === [] && $start > 1) {
            throw new InvalidArgumentException("{$path} has only " . count($lines) . ' lines.');
        }

        $width = strlen((string) ($start + count($slice)));
        $numbered = [];
        foreach ($slice as $i => $line) {
            $numbered[] = str_pad((string) ($start + $i), $width, ' ', STR_PAD_LEFT) . '| ' . $line;
        }

        $this->activity($context, 'file.read')->property('file', $path)->log();

        $header = "{$path} (lines {$start}-" . ($start + count($slice) - 1) . ' of ' . count($lines) . ')';

        return ToolResult::ok($this->limitOutput($header . "\n" . $this->redact(implode("\n", $numbered))), 'Read ' . $path);
    }
}
