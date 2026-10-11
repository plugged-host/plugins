<?php

namespace PluggedHost\Storage\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Storage\Services\StorageSetup;
use PluggedHost\Storage\Support\StorageBox;

/**
 * Prints the one command that installs (or upgrades) the agent on the storage box, or with
 * --backup on the backup server.
 */
class StorageSetupCommand extends Command
{
    protected $signature = 'plugged-host:storage-setup {--backup : Set the backup server up instead of the storage box}';

    protected $description = 'Print the command that sets the storage box (or --backup: the backup server) up (one-time code, valid for an hour)';

    public function handle(StorageSetup $setup): int
    {
        $box = $this->option('backup') ? StorageBox::BACKUP : StorageBox::STORAGE;

        $this->line('Run this on the ' . StorageBox::named($box)->label() . ', as root:');
        $this->newLine();
        $this->line('  ' . $setup->command($setup->issue($box)));
        $this->newLine();
        $this->line('It installs or upgrades the agent, asks a few questions, and sends its mirror key and address back here.');

        return self::SUCCESS;
    }
}
