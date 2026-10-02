<?php

namespace PluggedHost\Minecraft\Neo\Tools;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Minecraft\Neo\MinecraftTool;
use PluggedHost\Minecraft\Services\GeyserService;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class InstallGeyserTool extends MinecraftTool
{
    public function name(): string
    {
        return 'install_geyser';
    }

    public function description(): string
    {
        return 'Install or update Geyser + Floodgate so Bedrock (phone/console) players can join. Supported on purpur, fabric and neoforge (not forge). Opens a Bedrock port and restarts the server.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function offeredWith(): array
    {
        return [SubuserPermission::FileCreate];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileCreate, SubuserPermission::ControlRestart];
    }

    public function summarize(array $arguments): string
    {
        return 'Install Geyser + Floodgate (restarts the server)';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $this->software($context);
        $result = app(GeyserService::class)->install($context->server, $context->user->id);

        if (!$result['ok']) {
            throw new InvalidArgumentException(($result['reason'] ?? '') === 'unsupported_loader'
                ? 'Geyser is not available for this server software (it works on purpur, fabric and neoforge).'
                : 'Geyser could not be downloaded right now.');
        }

        $this->activity($context, 'geyser')->log();

        return ToolResult::ok((($result['updated'] ?? false) ? 'Updated' : 'Installed') . ' Geyser and Floodgate and restarted the server. Bedrock players connect to the server address on the Bedrock port shown in the panel\'s network tab.', 'Geyser installed');
    }
}
