<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\MinecraftVersionCatalog;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use PluggedHost\Servers\Services\GameCatalog;

final class SwitchSoftwareTool extends MinecraftTool
{
    public function name(): string
    {
        return 'switch_server_software';
    }

    public function description(): string
    {
        return 'Change the Minecraft software (purpur = Paper/Spigot plugins, fabric, forge, neoforge) and/or version. The server is stopped and reinstalled: the world and configs are kept, but installed plugins/mods stay and may no longer be compatible.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'software' => ['type' => 'string', 'enum' => ['purpur', 'fabric', 'forge', 'neoforge']],
                'version' => ['type' => 'string', 'description' => '"latest" or an exact version like "1.21.4".'],
            ],
            'required' => ['software', 'version'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Destructive;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::SettingsReinstall];
    }

    public function summarize(array $arguments): string
    {
        return 'Switch to ' . (string) ($arguments['software'] ?? '?') . ' ' . (string) ($arguments['version'] ?? '') . ' (reinstall)';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $this->software($context);
        $software = (string) ($arguments['software'] ?? '');
        $version = trim((string) ($arguments['version'] ?? ''));

        if (app(GameCatalog::class)->type($server->egg, $software) === null) {
            throw new InvalidArgumentException("This server cannot run {$software}.");
        }

        if ($version !== MinecraftVersionCatalog::LATEST && !app(MinecraftVersionCatalog::class)->supports($software, $version)) {
            throw new InvalidArgumentException("{$software} has no Minecraft {$version}; check minecraft_info for installable versions.");
        }

        $switcher = app(ServerSoftwareSwitcher::class);
        $from = $switcher->loader($server) . ' ' . $switcher->version($server);
        $switcher->apply($server, $software, $version);

        $this->activity($context, 'software')->property('from', $from)->property('to', "{$software} {$version}")->log();

        return ToolResult::ok("Switching from {$from} to {$software} {$version}: the server is reinstalling now (a few minutes). Other tools will say the server is installing until it finishes.", "Reinstalling as {$software} {$version}");
    }
}
