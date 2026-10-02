<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class DeleteFilesTool extends BaseServerTool
{
    private const MAX = 20;

    public function name(): string
    {
        return 'delete_files';
    }

    public function description(): string
    {
        return 'Permanently delete files or folders (up to 20). There is no undo; only use it when the user clearly wants these gone.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => self::MAX],
            ],
            'required' => ['paths'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Destructive;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileDelete];
    }

    public function summarize(array $arguments): string
    {
        $paths = array_map(fn (mixed $path): string => $this->path($path), array_slice((array) ($arguments['paths'] ?? []), 0, 3));
        $more = count((array) ($arguments['paths'] ?? [])) - count($paths);

        return 'Delete ' . implode(', ', $paths) . ($more > 0 ? " and {$more} more" : '');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $paths = array_values(array_unique(array_map(fn (mixed $path): string => $this->path($path), (array) ($arguments['paths'] ?? []))));

        if ($paths === [] || count($paths) > self::MAX || in_array('/', $paths, true)) {
            throw new InvalidArgumentException('Give between 1 and ' . self::MAX . ' paths, and never the server root.');
        }

        $context->files()->deleteFiles('/', array_map(static fn (string $path): string => ltrim($path, '/'), $paths));
        $this->activity($context, 'file.delete')->property('files', implode(', ', $paths))->log();

        return ToolResult::ok('Deleted: ' . implode(', ', $paths), 'Deleted ' . count($paths) . ' item(s)');
    }
}
