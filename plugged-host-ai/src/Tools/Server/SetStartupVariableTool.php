<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use App\Models\ServerVariable;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

/**
 * Same rules as the client API's startup variable update: only variables the egg marks
 * user-viewable and user-editable, validated against the egg's own rules.
 */
final class SetStartupVariableTool extends BaseServerTool
{
    public function name(): string
    {
        return 'set_startup_variable';
    }

    public function description(): string
    {
        return 'Change one editable startup variable (names from get_startup), e.g. a version or jar name. Applies on the next start.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'The env variable name, e.g. SERVER_JARFILE.'],
                'value' => ['type' => 'string'],
            ],
            'required' => ['key', 'value'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::StartupUpdate];
    }

    public function summarize(array $arguments): string
    {
        return 'Set ' . (string) ($arguments['key'] ?? '?') . ' = "' . mb_substr((string) ($arguments['value'] ?? ''), 0, 60) . '"';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $server = $context->server;
        $key = $this->string($arguments, 'key', 191);
        $value = (string) ($arguments['value'] ?? '');

        $variable = $server->variables()->where('env_variable', $key)->first();

        if ($variable === null || !$variable->user_viewable) {
            throw new InvalidArgumentException("There is no startup variable {$key}.");
        }

        if (!$variable->user_editable) {
            throw new InvalidArgumentException("{$key} is read-only.");
        }

        $validator = Validator::make(['value' => $value], ['value' => $variable->rules]);
        if ($validator->fails()) {
            throw new InvalidArgumentException("Invalid value for {$key}: " . implode(' ', $validator->errors()->all()));
        }

        $old = (string) ($variable->server_value ?? $variable->default_value);

        ServerVariable::query()->updateOrCreate(
            ['server_id' => $server->id, 'variable_id' => $variable->id],
            ['variable_value' => $value],
        );

        $this->activity($context, 'startup.variable')->property('variable', $key)->property('new', $value)->log();

        return ToolResult::ok("Set {$key} from \"{$old}\" to \"{$value}\". It applies on the next start.", "Set {$key}");
    }
}
