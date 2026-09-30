<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use stdClass;

final class ListBackupsTool extends BaseServerTool
{
    public function name(): string
    {
        return 'list_backups';
    }

    public function description(): string
    {
        return 'List this server\'s panel backups (id, name, size, date, state, locked) and how many more can be made.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::BackupRead];
    }

    public function summarize(array $arguments): string
    {
        return 'List backups';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $backups = $server->backups()->latest()->limit(50)->get();

        $lines = ['limit: ' . (int) $server->backup_limit . ' backups, ' . $backups->count() . ' used'];
        foreach ($backups as $backup) {
            $state = $backup->completed_at === null ? 'in progress' : ($backup->is_successful ? 'ok' : 'failed');
            $lines[] = "- id {$backup->uuid}: \"{$backup->name}\", " . $this->human((int) $backup->bytes) . ', ' . $backup->created_at?->toDateTimeString() . ", {$state}" . ($backup->is_locked ? ', locked' : '');
        }

        return ToolResult::ok(implode("\n", $lines), $backups->count() . ' backups');
    }
}
