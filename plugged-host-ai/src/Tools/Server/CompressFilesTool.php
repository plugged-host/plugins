<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class CompressFilesTool extends BaseServerTool
{
    public function name(): string
    {
        return 'compress_files';
    }

    public function description(): string
    {
        return 'Pack files or folders from one directory into a .tar.gz archive in that directory (useful as a quick copy before a risky change).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'directory' => ['type' => 'string', 'description' => 'Directory the files are in, default "/".'],
                'names' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50, 'description' => 'File or folder names inside that directory.'],
            ],
            'required' => ['names'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileArchive];
    }

    public function summarize(array $arguments): string
    {
        return 'Archive ' . count((array) ($arguments['names'] ?? [])) . ' item(s) in ' . $this->path($arguments['directory'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $directory = $this->path($arguments['directory'] ?? null);
        $names = array_values(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? trim($name, "/ \t") : '', (array) ($arguments['names'] ?? [])),
            static fn (string $name): bool => $name !== '' && !str_contains($name, '..'),
        ));

        if ($names === [] || count($names) > 50) {
            throw new InvalidArgumentException('Give between 1 and 50 names from the directory.');
        }

        $archive = $context->files()->compressFiles($directory, $names, null, 'tar.gz');
        $name = (string) ($archive['name'] ?? 'archive');
        $this->activity($context, 'file.compress')->property('file', $directory . '/' . $name)->log();

        return ToolResult::ok('Created ' . rtrim($directory, '/') . '/' . $name, 'Created ' . $name);
    }
}
