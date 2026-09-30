<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;

final class SetDockerImageTool extends BaseServerTool
{
    public function name(): string
    {
        return 'set_docker_image';
    }

    public function description(): string
    {
        return 'Switch the server\'s docker image (e.g. a different Java or Node version) to one of the images listed by get_startup. Applies on the next start.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['image' => ['type' => 'string', 'description' => 'Image name or its label from get_startup.']],
            'required' => ['image'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::StartupDockerImage];
    }

    public function summarize(array $arguments): string
    {
        return 'Switch docker image to ' . (string) ($arguments['image'] ?? '?');
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $images = (array) ($server->egg->docker_images ?? []);
        $wanted = $this->string($arguments, 'image', 255);
        $image = $images[$wanted] ?? (in_array($wanted, $images, true) ? $wanted : null);

        if (!in_array($server->image, $images, true)) {
            throw new InvalidArgumentException('This server\'s image was set by an administrator and cannot be changed.');
        }

        if ($image === null) {
            throw new InvalidArgumentException('That image is not offered for this server; use one from get_startup.');
        }

        $old = $server->image;
        $server->forceFill(['image' => $image])->saveOrFail();
        $this->activity($context, 'startup.image')->property('old', $old)->property('new', $image)->log();

        return ToolResult::ok("Docker image changed from {$old} to {$image}. It applies on the next start.", 'Image set to ' . $image);
    }
}
