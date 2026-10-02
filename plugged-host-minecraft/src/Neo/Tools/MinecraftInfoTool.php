<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ContentSearchService;
use PluggedHost\Minecraft\Services\CurseForgeCatalog;
use PluggedHost\Minecraft\Services\GeyserService;
use PluggedHost\Minecraft\Services\MinecraftVersionCatalog;
use PluggedHost\Minecraft\Services\ModrinthCatalog;
use PluggedHost\Minecraft\Services\ServerSoftwareSwitcher;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Servers\Services\GameCatalog;
use stdClass;
use Throwable;

final class MinecraftInfoTool extends MinecraftTool
{
    public function name(): string
    {
        return 'minecraft_info';
    }

    public function description(): string
    {
        return 'Minecraft details for this server: software family (purpur covers Paper/Spigot/Bukkit plugins; fabric/forge/neoforge use mods), Minecraft version, installable versions, what content it takes, and Geyser (Bedrock) status. Check this before searching or installing content.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileRead];
    }

    public function summarize(array $arguments): string
    {
        return 'Check Minecraft setup';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $software = $this->software($context);
        $switcher = app(ServerSoftwareSwitcher::class);
        $label = (string) (app(GameCatalog::class)->type($server->egg, $software)['label'] ?? $software);
        $versions = app(MinecraftVersionCatalog::class)->versions($software);

        try {
            $geyser = app(GeyserService::class)->status($server);
        } catch (Throwable) {
            $geyser = 'unknown';
        }

        $lines = [
            "software: {$software} ({$label})",
            'minecraft_version: ' . ($switcher->version($server) === MinecraftVersionCatalog::LATEST ? 'latest (' . ($versions[0] ?? '?') . ')' : $switcher->version($server)),
            'resolved_game_version: ' . (app(ContentSearchService::class)->gameVersion($server) ?? '?'),
            'content_types: ' . implode(', ', app(ModrinthCatalog::class)->projectTypes($software)),
            'content_directory: /' . $this->contentDirectory($context),
            'curseforge_available: ' . (app(CurseForgeCatalog::class)->configured() ? 'yes' : 'no (Modrinth only)'),
            'geyser: ' . $geyser . ' (install = not installed, update = installed, unavailable = not supported on this software)',
            'installable_versions (newest first): ' . implode(', ', array_slice($versions, 0, 15)),
            'switchable_software: purpur, fabric, forge, neoforge (switching reinstalls the server; the world is kept)',
        ];

        return ToolResult::ok(implode("\n", $lines), "{$label} {$lines[1]}");
    }
}
