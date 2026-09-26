<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\ContentInstallService;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class InstallContentTool extends MinecraftTool
{
    public function name(): string
    {
        return 'install_content';
    }

    public function description(): string
    {
        return 'Install a plugin or mod from Modrinth/CurseForge onto this server (into plugins/ or mods/). Picks the newest stable build for the server unless a version id is given. Not for modpacks (use install_modpack). Restart the server afterwards for it to load.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => ['type' => 'string', 'description' => 'Project id from search_content.'],
                'version' => ['type' => 'string', 'description' => 'Optional version id from content_versions.'],
                'source' => ['type' => 'string', 'enum' => ['modrinth', 'curseforge']],
                'name' => ['type' => 'string', 'description' => 'Project name, for the approval card.'],
            ],
            'required' => ['project'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileCreate];
    }

    public function summarize(array $arguments): string
    {
        return 'Install ' . (string) ($arguments['name'] ?? $arguments['project'] ?? 'content');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $this->software($context);
        $project = $this->string($arguments, 'project', 100);
        $version = isset($arguments['version']) ? (string) $arguments['version'] : null;

        $result = app(ContentInstallService::class)->install($context->server, $project, $version, $this->source($arguments));

        if (!$result['ok']) {
            throw new InvalidArgumentException(match ($result['error'] ?? '') {
                'modpack' => 'That project is a modpack; use install_modpack instead.',
                'no_compatible_version' => 'No build of this project fits this server\'s software/version (check content_versions or minecraft_info).',
                'curseforge_not_configured' => 'CurseForge is not available on this panel; use Modrinth.',
                'no_file' => 'That build has no downloadable server file (CurseForge sometimes blocks third-party downloads).',
                'pull_busy' => 'The node is busy with other downloads for this server; try again in a moment.',
                default => 'The download could not be started on the node.',
            });
        }

        $this->activity($context, 'content.install')
            ->property('file', "{$result['directory']}/{$result['file']}")
            ->log();

        $notes = [];
        if ($result['loose'] ?? false) {
            $notes[] = 'No build lists this exact Minecraft version, so the newest build was used; it may not load.';
        }
        if (($result['dependencies'] ?? []) !== []) {
            $notes[] = 'It requires these other projects (install them too): ' . implode(', ', $result['dependencies']) . '.';
        }

        $version = $result['version'] ?? [];

        return ToolResult::ok(trim(
            "Downloading {$result['file']} (" . ($version['number'] ?? $version['name'] ?? '?') . ") into /{$result['directory']}. The node downloads it in the background; it loads after a restart.\n" . implode("\n", $notes),
        ), 'Installing ' . $result['file']);
    }
}
