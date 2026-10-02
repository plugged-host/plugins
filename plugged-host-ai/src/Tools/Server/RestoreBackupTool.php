<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\ServerState;
use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DownloadLinkService;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

/**
 * Same checks and sequence as the client API's restore.
 */
final class RestoreBackupTool extends BaseServerTool
{
    public function name(): string
    {
        return 'restore_backup';
    }

    public function description(): string
    {
        return 'Restore a backup (id from list_backups) over the server\'s files. With delete_existing, every current file is removed first. The user always approves this.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'backup' => ['type' => 'string', 'description' => 'Backup id.'],
                'delete_existing' => ['type' => 'boolean', 'description' => 'Delete all current files before restoring.'],
            ],
            'required' => ['backup'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Critical;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::BackupRestore];
    }

    public function summarize(array $arguments): string
    {
        return 'Restore backup ' . (string) ($arguments['backup'] ?? '?') . (!empty($arguments['delete_existing']) ? ' (deleting all current files first)' : '');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $backup = $server->backups()->where('uuid', (string) ($arguments['backup'] ?? ''))->first();

        if ($backup === null) {
            throw new InvalidArgumentException('No such backup on this server; use list_backups.');
        }

        if ($server->status !== null) {
            throw new InvalidArgumentException('The server is busy (installing or restoring) and cannot restore right now.');
        }

        if (!$backup->is_successful || $backup->completed_at === null) {
            throw new InvalidArgumentException('That backup is not complete or failed, so it cannot be restored.');
        }

        $truncate = !empty($arguments['delete_existing']);
        $url = app(DownloadLinkService::class)->handle($backup, $context->user);
        $server->update(['status' => ServerState::RestoringBackup]);
        app(DaemonBackupRepository::class)->setServer($server)->restore($backup, $url, $truncate);

        $this->activity($context, 'backup.restore')->property('name', $backup->name)->property('truncate', $truncate)->log();

        return ToolResult::ok("Restoring \"{$backup->name}\" now" . ($truncate ? ' (existing files deleted first)' : '') . '. The server is unavailable until it finishes.', 'Restoring backup');
    }
}
