<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ContentInstallService;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;

final class ContentVersionsTool extends MinecraftTool
{
    public function name(): string
    {
        return 'content_versions';
    }

    public function description(): string
    {
        return 'List the builds of a plugin/mod project that fit this server (newest first). install_content picks the newest stable release by itself; use this only when a specific version is needed.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => ['type' => 'string', 'description' => 'Project id from search_content.'],
                'source' => ['type' => 'string', 'enum' => ContentInstallService::SOURCES],
                'kind' => ['type' => 'string', 'enum' => ['plugin', 'mod', 'modpack'], 'description' => 'Use "modpack" for modpacks (their builds are not limited to the server\'s current loader).'],
            ],
            'required' => ['project'],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileRead];
    }

    public function summarize(array $arguments): string
    {
        return 'List versions of ' . (string) ($arguments['project'] ?? '?');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $this->software($context);
        $kind = is_string($arguments['kind'] ?? null) ? $arguments['kind'] : null;
        $result = app(ContentInstallService::class)->versions($context->server, $this->string($arguments, 'project', 100), $this->source($arguments), $kind);

        if ($result['versions'] === []) {
            return ToolResult::ok('No builds of this project fit this server\'s software.', 'No compatible builds');
        }

        $lines = $result['loose'] ? ['No build lists this exact Minecraft version; these are the closest builds (may still work):'] : [];
        foreach (array_slice($result['versions'], 0, 12) as $version) {
            $lines[] = "- version id {$version['id']}: " . ($version['number'] ?? '') . ' ' . ($version['name'] ?? '') . " [{$version['type']}] for " . implode(', ', array_slice((array) ($version['game_versions'] ?? []), -4)) . ' (' . substr((string) ($version['date'] ?? ''), 0, 10) . ')';
        }

        return ToolResult::ok(implode("\n", $lines), count($result['versions']) . ' builds');
    }
}
