<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ContentSearchService;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class InstallModpackTool extends MinecraftTool
{
    public function name(): string
    {
        return 'install_modpack';
    }

    public function description(): string
    {
        return 'Install a modpack (from search_content with kind "modpack" and a version id from content_versions). The server is switched to the pack\'s loader and version and reinstalled, then the pack\'s files are placed; it runs in the background and the user gets a notification when done. Existing mods stay, so suggest removing old ones first.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => ['type' => 'string'],
                'version' => ['type' => 'string'],
                'source' => ['type' => 'string', 'enum' => ['modrinth', 'curseforge']],
                'name' => ['type' => 'string', 'description' => 'Modpack name, for the approval card.'],
            ],
            'required' => ['project', 'version'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Destructive;
    }

    public function offeredWith(): array
    {
        return [SubuserPermission::SettingsReinstall];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::SettingsReinstall, SubuserPermission::FileCreate];
    }

    public function summarize(array $arguments): string
    {
        return 'Install modpack ' . (string) ($arguments['name'] ?? $arguments['project'] ?? '?') . ' (reinstall)';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $this->software($context);
        $result = app(ContentSearchService::class)->queueModpack(
            $context->server,
            $this->string($arguments, 'project', 100),
            $this->string($arguments, 'version', 100),
            $this->source($arguments),
            $context->user->id,
        );

        if (!$result['ok']) {
            throw new InvalidArgumentException('The modpack could not be queued (' . ($result['error'] ?? 'error') . ').');
        }

        $this->activity($context, 'modpack')->property('project', (string) $arguments['project'])->log();

        return ToolResult::ok('The modpack install has started in the background: the server will be reinstalled to the pack\'s loader and version and the files placed. The user gets a notification when it is done.', 'Modpack install started');
    }
}
