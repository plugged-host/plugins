<?php

namespace PluggedHost\Storage\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Storage\Services\StorageSetup;

/**
 * Prints the one command that installs (or upgrades) the agent on a storage box.
 */
class StorageSetupCommand extends Command
{
    protected $signature = 'plugged-host:storage-setup';

    protected $description = 'Print the command that sets a storage box up (one-time code, valid for an hour)';

    public function handle(StorageSetup $setup): int
    {
        $this->line('Run this on the storage box, as root:');
        $this->newLine();
        $this->line('  ' . $setup->command($setup->issue()));
        $this->newLine();
        $this->line('It installs or upgrades the agent, asks a few questions, and sends its mirror key and address back here.');

        return self::SUCCESS;
    }
}
