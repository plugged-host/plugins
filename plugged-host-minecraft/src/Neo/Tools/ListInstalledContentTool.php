<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ModDetectionService;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;

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
            $detection = app(ModDetectionService::class);
            // Up to three batches here; anything left stays "not identified yet".
            for ($i = 0; $i < 3 && $detection->identifyPending($context->server, 6) > 0; $i++) {
                //
            }

            $lines = array_map(static function (array $row): string {
                $facts = array_filter([
                    $row['version'] ? 'v' . $row['version'] : null,
                    $row['platform'] ? "{$row['platform']} " . ($row['confidence'] === 'name' ? '(by name)' : '') : null,
                    $row['disabled'] ? 'disabled' : null,
                    $row['update'] ? "update available: {$row['update']['number']}" : null,
                    $row['missing'] !== [] ? 'MISSING dependencies: ' . implode(', ', $row['missing']) : null,
                    $row['pending'] ? 'not identified yet' : null,
                ]);

                return "- {$row['file']}: {$row['name']}" . ($facts !== [] ? ' [' . implode('; ', array_map('trim', $facts)) . ']' : '');
            }, $detection->list($context->server));
        } else {
            $entries = (array) $context->files()->getDirectory('/' . $directory);
            $jars = array_filter($entries, static fn ($entry): bool => is_array($entry) && !($entry['directory'] ?? false) && preg_match('/\.jar(\.disabled)?$/i', (string) ($entry['name'] ?? '')) === 1);
            $lines = array_map(fn (array $entry): string => '- ' . $entry['name'] . ' (' . $this->human((int) ($entry['size'] ?? 0)) . ')' . (str_ends_with((string) $entry['name'], '.disabled') ? ' [disabled]' : ''), array_values($jars));
        }

        return ToolResult::ok($lines === [] ? "Nothing installed in /{$directory}." : "/{$directory}:\n" . implode("\n", $lines), count($lines) . ' installed');
    }
}
