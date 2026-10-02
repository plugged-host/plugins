<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Support\SecretRedactor;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class WriteFileTool extends BaseServerTool
{
    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Create a file or replace its whole content. For small changes to an existing file prefer edit_file. Never write the •••• mask back: masked secrets must be changed by the user.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'File path from the server root.'],
                'content' => ['type' => 'string', 'description' => 'The complete new file content.'],
            ],
            'required' => ['path', 'content'],
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
        $size = strlen((string) ($arguments['content'] ?? ''));

        return 'Write ' . $this->path($arguments['path'] ?? null) . ' (' . $this->human($size) . ')';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $path = $this->path($arguments['path'] ?? null);
        $content = $arguments['content'] ?? null;

        if ($path === '/' || !is_string($content)) {
            throw new InvalidArgumentException('Give a file path and the content as a string.');
        }

        if (strlen($content) > (int) config('plugged-host-ai.max_write_bytes', 262144)) {
            throw new InvalidArgumentException('That content is too large to write in one go.');
        }

        if (SecretRedactor::containsMask($content)) {
            throw new InvalidArgumentException('The content contains the •••• secret mask. Masked values cannot be written back; ask the user to change secrets themselves.');
        }

        $context->files()->putContent($path, $content);
        $this->activity($context, 'file.write')->property('file', $path)->log();

        return ToolResult::ok("Wrote {$path} (" . strlen($content) . ' bytes).', 'Wrote ' . $path);
    }
}
