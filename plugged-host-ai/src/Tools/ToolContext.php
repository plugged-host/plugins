<?php

namespace PluggedHost\Ai\Tools;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use PluggedHost\Ai\Models\NeoConversation;

/**
 * Who a tool acts for and on what. Always an explicit user: tools also run from the CLI
 * command, where nobody is authenticated.
 */
final class ToolContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?Server $server,
        public readonly NeoConversation $conversation,
    ) {}

    public function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }
}
