<?php

namespace PluggedHost\Minecraft\Neo;

use InvalidArgumentException;
use PluggedHost\Minecraft\Services\ContentInstallService;
use PluggedHost\Minecraft\Services\ContentSearchService;
use PluggedHost\Minecraft\Services\ModDetectionService;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use Throwable;

/**
 * Base for the Minecraft tools this plugin contributes to plugged-host-neo (registered from
 * MinecraftPluginProvider only when neo is installed). They are offered only on servers whose
 * egg is a Minecraft variant.
 */
abstract class MinecraftTool extends BaseServerTool
{
    public function availableFor(ToolContext $context): bool
    {
        try {
            return $context->server !== null && app(ContentSearchService::class)->software($context->server) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    protected function software(ToolContext $context): string
    {
        $software = app(ContentSearchService::class)->software($context->server);

        if ($software === null) {
            throw new InvalidArgumentException('This is not a Minecraft server.');
        }

        return $software;
    }

    protected function contentDirectory(ToolContext $context): string
    {
        return app(ModDetectionService::class)->directory($context->server);
    }

    protected function source(array $arguments): string
    {
        $source = $arguments['source'] ?? 'modrinth';

        return in_array($source, ContentInstallService::SOURCES, true) ? $source : 'modrinth';
    }

    /**
     * A bare jar name inside the content directory, never a path.
     */
    protected function jarName(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')
            || !preg_match('/\.jar(\.disabled)?$/i', $name)) {
            throw new InvalidArgumentException("\"{$name}\" is not a jar file name from list_installed_content.");
        }

        return $name;
    }
}
