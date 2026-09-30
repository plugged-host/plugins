<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use Illuminate\Support\Str;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ContentInstallService;
use PluggedHost\Minecraft\Services\ContentSearchService;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;

final class SearchContentTool extends MinecraftTool
{
    public function name(): string
    {
        return 'search_content';
    }

    public function description(): string
    {
        return 'Search Modrinth (default), CurseForge or SpigotMC for plugins, mods or modpacks that fit this server\'s software and Minecraft version. SpigotMC is for plugins on Paper/Purpur servers only, cannot filter by version, and only its latest builds install; prefer Modrinth when a project is on both. Returns project ids to use with content_versions and install_content (with the same source).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to look for, e.g. "EssentialsX" or "world edit".'],
                'kind' => ['type' => 'string', 'enum' => ['plugin', 'mod', 'modpack'], 'description' => 'Defaults to what the server takes.'],
                'source' => ['type' => 'string', 'enum' => ContentInstallService::SOURCES],
                'any_version' => ['type' => 'boolean', 'description' => 'Search all Minecraft versions instead of only the server\'s.'],
                'page' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['query'],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileRead];
    }

    public function summarize(array $arguments): string
    {
        return 'Search content for "' . Str::limit((string) ($arguments['query'] ?? ''), 40) . '"';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $this->software($context);

        $result = app(ContentSearchService::class)->search($context->server, [
            'q' => $this->string($arguments, 'query', 100),
            'kind' => $arguments['kind'] ?? null,
            'source' => $this->source($arguments),
            'game_version' => !empty($arguments['any_version']) ? '' : null,
            'page' => (int) ($arguments['page'] ?? 1),
        ]);

        if ($result['hits'] === []) {
            return ToolResult::ok("No results on {$result['source']} for this server (software {$result['software']}, version " . ($result['game_version'] ?: 'any') . ').', 'No results');
        }

        $lines = ["{$result['source']} results (software {$result['software']}, version " . ($result['game_version'] ?: 'any') . ", page {$result['page']}/{$result['pages']}):"];
        foreach (array_slice($result['hits'], 0, 10) as $hit) {
            $side = ($hit['server_side'] ?? '') === 'unsupported' ? ' [CLIENT-ONLY: not for servers]' : '';
            $side .= ($hit['installable'] ?? true) === false ? ' [cannot be installed automatically: premium or hosted elsewhere]' : '';
            $side .= ($hit['tested_versions'] ?? []) !== [] ? ' [tested on ' . implode(', ', array_slice($hit['tested_versions'], -3)) . ']' : '';
            $lines[] = "- {$hit['title']} (id {$hit['id']}, " . ($hit['kind'] ?? $result['kind'] ?? '?') . ', ' . number_format((int) ($hit['downloads'] ?? 0)) . " downloads){$side}: " . Str::limit((string) ($hit['description'] ?? ''), 120);
        }

        return ToolResult::ok(implode("\n", $lines), count($result['hits']) . ' results');
    }
}
