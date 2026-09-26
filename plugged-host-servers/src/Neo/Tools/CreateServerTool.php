<?php

namespace PluggedHost\Servers\Neo\Tools;

use App\Filament\Server\Pages\Console;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use PluggedHost\Servers\Exceptions\ProvisioningException;
use PluggedHost\Servers\Neo\AccountTool;
use PluggedHost\Servers\Services\ServerProvisioner;
use Throwable;

final class CreateServerTool extends AccountTool
{
    public function name(): string
    {
        return 'create_server';
    }

    public function description(): string
    {
        return 'Create a new server for the user from their free allowance. Needs a game id and location code from list_create_options, and either a plan id or cpu (%), memory (MB) and disk (MB). New servers install and then start by themselves; Minecraft servers then open a setup wizard for software/version.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'game' => ['type' => 'string', 'description' => 'Game id.'],
                'location' => ['type' => 'string', 'description' => 'Location code, e.g. "fr" or "premium:de".'],
                'plan' => ['type' => 'string', 'description' => 'Plan id (instead of cpu/memory/disk).'],
                'cpu' => ['type' => 'integer'],
                'memory' => ['type' => 'integer'],
                'disk' => ['type' => 'integer'],
            ],
            'required' => ['name', 'game', 'location'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function summarize(array $arguments): string
    {
        $size = isset($arguments['plan']) ? 'plan ' . $arguments['plan'] : ($arguments['cpu'] ?? '?') . '% / ' . ($arguments['memory'] ?? '?') . ' MB / ' . ($arguments['disk'] ?? '?') . ' MB';

        return 'Create server "' . ($arguments['name'] ?? '?') . '" in ' . ($arguments['location'] ?? '?') . " ({$size})";
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        try {
            $server = app(ServerProvisioner::class)->create($context->user, [
                'name' => (string) ($arguments['name'] ?? ''),
                'game' => $arguments['game'] ?? null,
                'location' => $arguments['location'] ?? null,
                'plan' => $arguments['plan'] ?? null,
                'cpu' => $arguments['cpu'] ?? 0,
                'memory' => $arguments['memory'] ?? 0,
                'disk' => $arguments['disk'] ?? 0,
            ]);
        } catch (ProvisioningException $e) {
            throw $this->explain($e);
        }

        $this->activity($context, 'server.create')->subject($server)->property('name', $server->name)->log();

        try {
            $url = Console::getUrl(panel: 'server', tenant: $server);
        } catch (Throwable) {
            $url = null;
        }

        return ToolResult::ok(
            "Created \"{$server->name}\" (id {$server->uuid_short}): {$server->cpu}% CPU, {$server->memory} MB RAM, {$server->disk} MB disk. It is installing now and starts on its own when done." . ($url ? " Console: {$url}" : ''),
            'Created ' . $server->name,
        );
    }
}
