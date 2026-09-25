<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class DecompressFileTool extends BaseServerTool
{
    public function name(): string
    {
        return 'decompress_file';
    }

    public function description(): string
    {
        return 'Extract an archive (zip, tar.gz, …) into the folder it is in. Existing files with the same names are overwritten.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'description' => 'Archive path from the server root.']],
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
        return 'Extract ' . $this->path($arguments['path'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $path = $this->path($arguments['path'] ?? null);
        $context->files()->decompressFile(dirname($path), basename($path));
        $this->activity($context, 'file.decompress')->property('file', $path)->log();

        return ToolResult::ok("Extracted {$path}.", 'Extracted ' . $path);
    }
}
