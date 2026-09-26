<?php

namespace PluggedHost\Neo\Tools\App;

use App\Models\Server;
use PluggedHost\Neo\Tools\Tool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use stdClass;
use Throwable;

/**
 * The only tool outside a server: which servers the user has, so neo can point them to the
 * right one (server tools are only available on a server's own pages).
 */
final class ListServersTool implements Tool
{
    public function name(): string
    {
        return 'list_servers';
    }

    public function description(): string
    {
        return 'List the servers this user owns or has access to, with their game, node and whether they are running. To work on a server, the user has to open neo from that server\'s pages.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function scope(): string
    {
        return self::SCOPE_APP;
    }

    public function availableFor(ToolContext $context): bool
    {
        return true;
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Read;
    }

    public function offeredWith(): array
    {
        return [];
    }

    public function requires(array $arguments): array
    {
        return [];
    }

    public function summarize(array $arguments): string
    {
        return 'List your servers';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $user = $context->user;
        $servers = Server::query()
            ->where('owner_id', $user->id)
            ->orWhereHas('subusers', fn ($query) => $query->where('user_id', $user->id))
            ->with(['egg', 'node'])
            ->limit(25)
            ->get();

        if ($servers->isEmpty()) {
            return ToolResult::ok('This user has no servers yet.', 'No servers');
        }

        $lines = $servers->map(function (Server $server) use ($user): string {
            try {
                $state = $server->retrieveStatus()->value;
            } catch (Throwable) {
                $state = 'unknown';
            }

            $role = (int) $server->owner_id === (int) $user->id ? 'owner' : 'subuser';

            return "- {$server->name} (id {$server->uuid_short}, {$role}): " . ($server->egg?->name ?? '?') . ' on ' . ($server->node?->name ?? '?') . ", {$state}";
        });

        return ToolResult::ok($lines->implode("\n"), 'Listed ' . $servers->count() . ' servers');
    }
}
