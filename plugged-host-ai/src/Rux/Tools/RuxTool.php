<?php

namespace PluggedHost\Ai\Rux\Tools;

/**
 * One thing rux can do. Arguments arrive as the model wrote them; a tool validates its own and
 * throws InvalidArgumentException with a message the model can act on.
 */
abstract class RuxTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * Who may use it: RuxAccess::ADMIN (default) or RuxAccess::MODERATOR (admins too).
     */
    public function level(): string
    {
        return \PluggedHost\Ai\Rux\Support\RuxAccess::ADMIN;
    }

    /**
     * Whether the model is offered this tool for this order at all (on top of level()).
     */
    public function offeredIn(RuxContext $context): bool
    {
        return true;
    }

    /**
     * JSON schema of the arguments.
     *
     * @return array<string, mixed>
     */
    abstract public function parameters(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    abstract public function execute(RuxContext $context, array $arguments): ToolResult;

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function string(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function int(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function bool(array $arguments, string $key, bool $default = false): bool
    {
        $value = $arguments[$key] ?? null;

        return is_bool($value) ? $value : (is_string($value) ? in_array(strtolower($value), ['true', '1', 'yes'], true) : $default);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function required(array $arguments, string $key): string
    {
        return $this->string($arguments, $key) ?? throw new \InvalidArgumentException("{$key} is required.");
    }
}
