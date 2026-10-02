<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use App\Services\Servers\StartupCommandService;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use stdClass;

final class GetStartupTool extends BaseServerTool
{
    public function name(): string
    {
        return 'get_startup';
    }

    public function description(): string
    {
        return 'The server\'s startup command, its docker image (and the images it may switch to), and the startup variables the user can see, with whether each is editable and its validation rules.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::StartupRead];
    }

    public function summarize(array $arguments): string
    {
        return 'Read startup settings';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $images = (array) ($server->egg->docker_images ?? []);

        $lines = [
            'startup: ' . app(StartupCommandService::class)->handle($server),
            'docker_image: ' . $server->image . (in_array($server->image, $images, true) ? '' : ' (set by an administrator, cannot be changed)'),
            'available_images: ' . implode(', ', array_map(static fn ($label, $image) => "{$label} = {$image}", array_keys($images), $images)),
            'variables:',
        ];

        foreach ($server->variables()->where('user_viewable', true)->get() as $variable) {
            $value = $variable->server_value ?? $variable->default_value;
            $lines[] = "- {$variable->env_variable} ({$variable->name}) = \"" . $this->redact((string) $value) . '"' . ($variable->user_editable ? '' : ' [read-only]') . ' rules: ' . (is_array($variable->rules) ? implode('|', $variable->rules) : (string) $variable->rules);
        }

        return ToolResult::ok($this->limitOutput(implode("\n", $lines)), 'Read startup settings');
    }
}
