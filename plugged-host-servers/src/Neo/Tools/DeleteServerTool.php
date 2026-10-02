<?php

namespace PluggedHost\Servers\Neo\Tools;

use App\Facades\Activity;
use App\Services\Servers\ServerDeletionService;
use Filament\Facades\Filament;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;
use PluggedHost\Servers\Services\ServerProvisioner;
use Throwable;

/**
 * Owner-only and always confirmed: deleting destroys the server's files, databases and
 * backups for good.
 */
final class DeleteServerTool extends BaseServerTool
{
    public function name(): string
    {
        return 'delete_server';
    }

    public function description(): string
    {
        return 'Permanently delete this server with all its files, databases and panel backups (subdomain deposits are refunded). Irreversible. Only when the user explicitly asks; pass the server\'s exact name to confirm. The user always approves this.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['confirm_name' => ['type' => 'string', 'description' => 'The server\'s exact current name.']],
            'required' => ['confirm_name'],
        ];
    }

    public function availableFor(ToolContext $context): bool
    {
        return $context->server !== null
            && (int) $context->server->owner_id === (int) $context->user->id
            && (bool) config('plugged-host-servers.user-creatable-servers.can_users_delete_servers');
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Critical;
    }

    public function requires(array $arguments): array
    {
        return [];
    }

    public function summarize(array $arguments): string
    {
        return 'DELETE this server permanently';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;

        if (!$this->availableFor($context)) {
            throw new InvalidArgumentException('Only the server owner can delete it.');
        }

        if (trim((string) ($arguments['confirm_name'] ?? '')) !== $server->name) {
            throw new InvalidArgumentException("confirm_name must be the server's exact name (\"{$server->name}\").");
        }

        $name = $server->name;
        $id = $server->uuid_short;

        app(ServerDeletionService::class)->withForce()->handle($server);
        app(ServerProvisioner::class)->forget($context->user);

        Activity::event('user:neo.server.delete')
            ->actor($context->user)
            ->property(['name' => $name, 'server' => $id, 'conversation' => $context->conversation->uuid])
            ->log();

        try {
            $home = Filament::getPanel('app')->getUrl();
        } catch (Throwable) {
            $home = url('/');
        }

        return ToolResult::redirect("Deleted the server \"{$name}\" ({$id}). The user is being taken back to their server list.", $home);
    }
}
