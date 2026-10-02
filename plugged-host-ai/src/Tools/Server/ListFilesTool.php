<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;

final class ListFilesTool extends BaseServerTool
{
    private const MAX_ENTRIES = 200;

    public function name(): string
    {
        return 'list_files';
    }

    public function description(): string
    {
        return 'List a directory on the server (folders first, with sizes and last-modified dates). Paths start at the server root, e.g. "/" or "/plugins".';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Directory to list, default "/".'],
            ],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileRead];
    }

    public function summarize(array $arguments): string
    {
        return 'List ' . $this->path($arguments['path'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $path = $this->path($arguments['path'] ?? null);
        $entries = (array) $context->files()->getDirectory($path);

        usort($entries, static fn (array $a, array $b): int => [!($a['directory'] ?? false), (string) ($a['name'] ?? '')] <=> [!($b['directory'] ?? false), (string) ($b['name'] ?? '')]);

        $lines = [];
        foreach (array_slice($entries, 0, self::MAX_ENTRIES) as $entry) {
            $name = (string) ($entry['name'] ?? '?');
            $modified = substr((string) ($entry['modified'] ?? ''), 0, 16);
            $lines[] = ($entry['directory'] ?? false)
                ? "[dir]  {$name}/  {$modified}"
                : "[file] {$name}  " . $this->human((int) ($entry['size'] ?? 0)) . "  {$modified}";
        }

        if (count($entries) > self::MAX_ENTRIES) {
            $lines[] = '…and ' . (count($entries) - self::MAX_ENTRIES) . ' more entries';
        }

        return ToolResult::ok($lines === [] ? "{$path} is empty." : "{$path}:\n" . implode("\n", $lines), 'Listed ' . $path);
    }
}
