<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Services\Backups\InitiateBackupService;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class CreateBackupTool extends BaseServerTool
{
    public function name(): string
    {
        return 'create_backup';
    }

    public function description(): string
    {
        return 'Start a panel backup of the server (runs in the background). Good before risky changes. Fails when the backup limit is reached; then an old backup must be deleted first.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'locked' => ['type' => 'boolean', 'description' => 'Protect it from deletion (needs backup delete permission).'],
            ],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::BackupCreate];
    }

    public function summarize(array $arguments): string
    {
        return 'Create backup' . (isset($arguments['name']) ? ' "' . $arguments['name'] . '"' : '');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $service = app(InitiateBackupService::class);

        if (!empty($arguments['locked']) && $context->user->can(SubuserPermission::BackupDelete, $server)) {
            $service->setIsLocked(true);
        }

        try {
            $backup = $service->handle($server, isset($arguments['name']) ? mb_substr((string) $arguments['name'], 0, 191) : null);
        } catch (TooManyBackupsException) {
            throw new InvalidArgumentException('The backup limit (' . (int) $server->backup_limit . ') is reached; delete an old backup first.');
        } catch (TooManyRequestsHttpException) {
            throw new InvalidArgumentException('Backups were started too recently; wait a few minutes and try again.');
        }

        $this->activity($context, 'backup.create')->property('name', $backup->name)->log();

        return ToolResult::ok("Backup \"{$backup->name}\" (id {$backup->uuid}) started; it finishes in the background.", 'Backup started');
    }
}
