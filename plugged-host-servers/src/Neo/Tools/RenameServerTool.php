<?php

namespace PluggedHost\Servers\Neo\Tools;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use PluggedHost\Servers\Services\ServerProvisioner;

final class RenameServerTool extends BaseServerTool
{
    public function name(): string
    {
        return 'rename_server';
    }

    public function description(): string
    {
        return 'Rename this server (the name shown in the panel).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::SettingsRename];
    }

    public function summarize(array $arguments): string
    {
        return 'Rename to "' . (string) ($arguments['name'] ?? '?') . '"';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $name = trim($this->string($arguments, 'name', ServerProvisioner::NAME_MAX));
        if ($name === '') {
            throw new InvalidArgumentException('Give a new name.');
        }

        $old = $context->server->name;
        $context->server->update(['name' => $name]);
        $this->activity($context, 'rename')->property('old', $old)->property('new', $name)->log();

        return ToolResult::ok("Renamed \"{$old}\" to \"{$name}\".", 'Renamed to ' . $name);
    }
}
