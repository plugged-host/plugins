<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class RenameFileTool extends BaseServerTool
{
    public function name(): string
    {
        return 'rename_file';
    }

    public function description(): string
    {
        return 'Rename or move a file or folder.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Current path.'],
                'to' => ['type' => 'string', 'description' => 'New path.'],
            ],
            'required' => ['from', 'to'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileUpdate];
    }

    public function summarize(array $arguments): string
    {
        return 'Move ' . $this->path($arguments['from'] ?? null) . ' → ' . $this->path($arguments['to'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $from = $this->path($arguments['from'] ?? null);
        $to = $this->path($arguments['to'] ?? null);

        if ($from === '/' || $to === '/') {
            throw new InvalidArgumentException('Give both the current and the new path.');
        }

        $context->files()->renameFiles('/', [['from' => ltrim($from, '/'), 'to' => ltrim($to, '/')]]);
        $this->activity($context, 'file.rename')->property('file', $from)->property('to', $to)->log();

        return ToolResult::ok("Moved {$from} to {$to}.", 'Moved ' . $from);
    }
}
