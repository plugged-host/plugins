<?php

namespace PluggedHost\Neo\Tools;

use PluggedHost\Neo\Agent\ServerGuard;
use PluggedHost\Neo\Tools\App\ListServersTool;
use PluggedHost\Neo\Tools\Server as ServerTools;

/**
 * All tools neo knows, and which of them a given user may be offered in a given place.
 *
 * Other plugins add their own through register() (by class name, from a service provider's
 * boot()), so game-specific tools live with the game's plugin rather than here.
 */
final class NeoToolRegistry
{
    /**
     * @var list<class-string<Tool>>
     */
    private const BUILT_IN = [
        ServerTools\ServerStatusTool::class,
        ServerTools\ConsoleReadTool::class,
        ServerTools\ListFilesTool::class,
        ServerTools\ReadFileTool::class,
        ServerTools\SearchFilesTool::class,
        ServerTools\SendCommandTool::class,
        ServerTools\PowerTool::class,
        ServerTools\EditFileTool::class,
        ServerTools\WriteFileTool::class,
        ServerTools\CreateDirectoryTool::class,
        ServerTools\RenameFileTool::class,
        ServerTools\CompressFilesTool::class,
        ServerTools\DecompressFileTool::class,
        ServerTools\DeleteFilesTool::class,
        ServerTools\GetStartupTool::class,
        ServerTools\SetStartupVariableTool::class,
        ServerTools\SetDockerImageTool::class,
        ServerTools\ReinstallServerTool::class,
        ServerTools\ListBackupsTool::class,
        ServerTools\CreateBackupTool::class,
        ServerTools\RestoreBackupTool::class,
        ServerTools\DeleteBackupTool::class,
        ListServersTool::class,
    ];

    /**
     * @var list<class-string<Tool>>
     */
    private static array $registered = [];

    /**
     * @var array<string, Tool>|null
     */
    private ?array $tools = null;

    /**
     * @param  class-string<Tool>  $class
     */
    public static function register(string $class): void
    {
        if (is_subclass_of($class, Tool::class) && !in_array($class, self::$registered, true)) {
            self::$registered[] = $class;
        }
    }

    /**
     * @return array<string, Tool>
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $this->tools = [];
        foreach ([...self::BUILT_IN, ...self::$registered] as $class) {
            $tool = app($class);
            $this->tools[$tool->name()] ??= $tool;
        }

        return $this->tools;
    }

    public function find(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Tools the model may be offered here: right place, and the user holds at least one of
     * the permissions the tool can be used with.
     *
     * @return array<string, Tool>
     */
    public function available(ToolContext $context): array
    {
        return array_filter($this->all(), function (Tool $tool) use ($context): bool {
            if (!$this->inScope($tool, $context) || !$tool->availableFor($context)) {
                return false;
            }

            $permissions = $tool->scope() === Tool::SCOPE_SERVER ? $tool->offeredWith() : [];

            return $permissions === [] || collect($permissions)->contains(fn ($permission): bool => $context->user->can($permission, $context->server));
        });
    }

    /**
     * Whether this exact call is allowed right now (checked again just before it runs).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function permitted(Tool $tool, ToolContext $context, array $arguments): bool
    {
        if (!$this->inScope($tool, $context)) {
            return false;
        }

        if ($tool->scope() !== Tool::SCOPE_SERVER) {
            return true;
        }

        foreach ($tool->requires($arguments) as $permission) {
            if (!$context->user->can($permission, $context->server)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, Tool>  $tools
     * @return list<array<string, mixed>>
     */
    public function definitions(array $tools): array
    {
        return array_values(array_map(static fn (Tool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
        ], $tools));
    }

    private function inScope(Tool $tool, ToolContext $context): bool
    {
        if ($tool->scope() === Tool::SCOPE_ANY) {
            return true;
        }

        if ($tool->scope() === Tool::SCOPE_APP) {
            return $context->server === null;
        }

        return $context->server !== null && ServerGuard::canUse($context->user, $context->server);
    }
}
