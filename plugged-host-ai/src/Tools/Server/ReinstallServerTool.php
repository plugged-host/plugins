<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use App\Services\Servers\ReinstallServerService;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;
use stdClass;

final class ReinstallServerTool extends BaseServerTool
{
    public function name(): string
    {
        return 'reinstall_server';
    }

    public function description(): string
    {
        return 'Stop the server and run its install script again (re-downloads the server software). Files are normally kept, but the install script may overwrite some. Suggest a backup first.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Destructive;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::SettingsReinstall];
    }

    public function summarize(array $arguments): string
    {
        return 'Reinstall the server';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        app(ReinstallServerService::class)->handle($context->server);
        $this->activity($context, 'reinstall')->log();

        return ToolResult::ok('The server is reinstalling now; it takes a few minutes. Other tools will report that it is installing until it finishes.', 'Reinstalling');
    }
}
