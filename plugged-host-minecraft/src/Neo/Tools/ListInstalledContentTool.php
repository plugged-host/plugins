<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ModDetectionService;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;

final class ListInstalledContentTool extends MinecraftTool
{
    public function name(): string
    {
        return 'list_installed_content';
    }

    public function description(): string
    {
        return 'List the plugin/mod jars installed on this server (disabled ones end in .jar.disabled). Set identify to match them to Modrinth/CurseForge projects (slower).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'identify' => ['type' => 'boolean', 'description' => 'Look up which project each jar is (slower).'],
            ],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileRead];
    }

    public function summarize(array $arguments): string
    {
        return 'List installed plugins/mods';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $directory = $this->contentDirectory($context);

        if (!empty($arguments['identify'])) {
            $rows = app(ModDetectionService::class)->list($context->server);
            $lines = array_map(static fn (array $row): string => '- ' . $row['file'] . ($row['disabled'] ? ' [disabled]' : '') . (isset($row['title']) ? " = {$row['title']} ({$row['platform']} {$row['project_id']})" : ''), $rows);
        } else {
            $entries = (array) $context->files()->getDirectory('/' . $directory);
            $jars = array_filter($entries, static fn ($entry): bool => is_array($entry) && !($entry['directory'] ?? false) && preg_match('/\.jar(\.disabled)?$/i', (string) ($entry['name'] ?? '')) === 1);
            $lines = array_map(fn (array $entry): string => '- ' . $entry['name'] . ' (' . $this->human((int) ($entry['size'] ?? 0)) . ')' . (str_ends_with((string) $entry['name'], '.disabled') ? ' [disabled]' : ''), array_values($jars));
        }

        return ToolResult::ok($lines === [] ? "Nothing installed in /{$directory}." : "/{$directory}:\n" . implode("\n", $lines), count($lines) . ' installed');
    }
}
