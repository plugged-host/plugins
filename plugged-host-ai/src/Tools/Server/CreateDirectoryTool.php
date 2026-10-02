<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class CreateDirectoryTool extends BaseServerTool
{
    public function name(): string
    {
        return 'create_directory';
    }

    public function description(): string
    {
        return 'Create a folder on the server.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Folder to create, e.g. "/plugins/MyPlugin".']],
            'required' => ['path'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileCreate];
    }

    public function summarize(array $arguments): string
    {
        return 'Create folder ' . $this->path($arguments['path'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $path = $this->path($arguments['path'] ?? null);
        if ($path === '/') {
            throw new InvalidArgumentException('Give the folder to create.');
        }

        $context->files()->createDirectory(basename($path), dirname($path));
        $this->activity($context, 'file.create-directory')->property('file', $path)->log();

        return ToolResult::ok("Created {$path}.", 'Created ' . $path);
    }
}
