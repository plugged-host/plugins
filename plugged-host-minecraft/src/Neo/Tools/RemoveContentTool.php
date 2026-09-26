<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class RemoveContentTool extends MinecraftTool
{
    public function name(): string
    {
        return 'remove_content';
    }

    public function description(): string
    {
        return 'Uninstall plugins/mods by deleting their jars (names from list_installed_content). Their config folders are left alone. Consider toggle_content to only disable one.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'files' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20, 'description' => 'Jar file names, e.g. ["EssentialsX-2.21.0.jar"].'],
            ],
            'required' => ['files'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Destructive;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileDelete];
    }

    public function summarize(array $arguments): string
    {
        return 'Uninstall ' . implode(', ', array_slice(array_map('strval', (array) ($arguments['files'] ?? [])), 0, 3));
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $files = array_values(array_unique(array_map(fn ($file): string => $this->jarName($file), (array) ($arguments['files'] ?? []))));

        if ($files === [] || count($files) > 20) {
            throw new InvalidArgumentException('Give between 1 and 20 jar names.');
        }

        $directory = $this->contentDirectory($context);
        $context->files()->deleteFiles('/' . $directory, $files);
        cache()->forget("server:{$context->server->id}:installed_files:{$directory}");

        $this->activity($context, 'content.remove')->property('files', implode(', ', $files))->log();

        return ToolResult::ok('Removed from /' . $directory . ': ' . implode(', ', $files) . '. Restart the server to unload them.', 'Removed ' . count($files) . ' jar(s)');
    }
}
