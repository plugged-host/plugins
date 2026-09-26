<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use App\Exceptions\Service\Backup\BackupLockedException;
use App\Services\Backups\DeleteBackupService;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class DeleteBackupTool extends BaseServerTool
{
    public function name(): string
    {
        return 'delete_backup';
    }

    public function description(): string
    {
        return 'Delete a panel backup (id from list_backups). Locked backups cannot be deleted.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['backup' => ['type' => 'string']],
            'required' => ['backup'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Destructive;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::BackupDelete];
    }

    public function summarize(array $arguments): string
    {
        return 'Delete backup ' . (string) ($arguments['backup'] ?? '?');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $backup = $context->server->backups()->where('uuid', (string) ($arguments['backup'] ?? ''))->first();

        if ($backup === null) {
            throw new InvalidArgumentException('No such backup on this server; use list_backups.');
        }

        try {
            app(DeleteBackupService::class)->handle($backup);
        } catch (BackupLockedException) {
            throw new InvalidArgumentException('That backup is locked; unlock it in the panel first.');
        }

        $this->activity($context, 'backup.delete')->property('name', $backup->name)->log();

        return ToolResult::ok("Deleted backup \"{$backup->name}\".", 'Deleted backup');
    }
}
