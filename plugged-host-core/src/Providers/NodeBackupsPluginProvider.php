<?php

namespace PluggedHost\Core\Providers;

use App\Services\Backups\InitiateBackupService;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Services\NodeAwareInitiateBackupService;

/**
 * Turns panel backups off on the nodes in plugged-host-core.backups.disabled_nodes
 * (Support\NodeBackups).
 *
 * Every caller resolves InitiateBackupService from the container (BackupResource's create
 * action, BackupController, TaskServiceProvider's lazy singleton, neo's CreateBackupTool), and
 * plugins register inside AppServiceProvider::register(), so this binding wins for all of them.
 * bind(), not singleton(): the service keeps per-backup state (locked, ignored files).
 */
class NodeBackupsPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InitiateBackupService::class, NodeAwareInitiateBackupService::class);
    }
}
