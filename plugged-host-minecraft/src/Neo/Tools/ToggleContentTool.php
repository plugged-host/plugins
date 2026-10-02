<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class ToggleContentTool extends MinecraftTool
{
    public function name(): string
    {
        return 'toggle_content';
    }

    public function description(): string
    {
        return 'Disable a plugin/mod (renames it to .jar.disabled so it stops loading) or enable it again. Takes effect after a restart.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file' => ['type' => 'string', 'description' => 'Jar name from list_installed_content.'],
                'enabled' => ['type' => 'boolean'],
            ],
            'required' => ['file', 'enabled'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileUpdate];
    }

    public function summarize(array $arguments): string
    {
        return (!empty($arguments['enabled']) ? 'Enable ' : 'Disable ') . (string) ($arguments['file'] ?? '?');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $file = $this->jarName($arguments['file'] ?? null);
        $base = preg_replace('/\.disabled$/i', '', $file);
        [$from, $to] = !empty($arguments['enabled']) ? ["{$base}.disabled", $base] : [$base, "{$base}.disabled"];

        if ($from === $to) {
            return ToolResult::ok('Nothing to change.');
        }

        $directory = $this->contentDirectory($context);
        $context->files()->renameFiles('/' . $directory, [['from' => $from, 'to' => $to]]);
        cache()->forget("server:{$context->server->id}:installed_files:{$directory}");

        $this->activity($context, 'content.toggle')->property('file', "{$directory}/{$to}")->log();

        return ToolResult::ok("Renamed {$from} to {$to}. Restart the server to apply.", (!empty($arguments['enabled']) ? 'Enabled ' : 'Disabled ') . $base);
    }
}
