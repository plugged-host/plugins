<?php

namespace PluggedHost\Ai\Tools;

use App\Enums\SubuserPermission;

/**
 * One action neo can take. Other plugins can add their own through
 * NeoToolRegistry::register(), which keeps game-specific tools in the game's plugin.
 */
interface Tool
{
    public const SCOPE_SERVER = 'server';

    public const SCOPE_APP = 'app';

    // Account-level tools (create a server, account overview) usable from any chat.
    public const SCOPE_ANY = 'any';

    /**
     * Unique, [A-Za-z0-9_-]{1,64}.
     */
    public function name(): string;

    /**
     * What the model is told the tool does. Plain English; the model reads it on every turn.
     */
    public function description(): string;

    /**
     * JSON Schema of the arguments. Use `new \stdClass` for an empty `properties` object.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    public function scope(): string;

    /**
     * Whether the tool makes sense here at all (e.g. only Minecraft servers get the Minecraft
     * tools). Checked before offering it, after the scope check.
     */
    public function availableFor(ToolContext $context): bool;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function risk(array $arguments): ToolRisk;

    /**
     * The permissions of which the user needs at least one for the tool to be offered at all.
     *
     * @return list<SubuserPermission>
     */
    public function offeredWith(): array;

    /**
     * The permissions this exact call needs. Checked again right before it runs, because
     * permissions can change between proposal and approval.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<SubuserPermission>
     */
    public function requires(array $arguments): array;

    /**
     * One line for the chat and the approval card, e.g. "Edit server.properties".
     *
     * @param  array<string, mixed>  $arguments
     */
    public function summarize(array $arguments): string;

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws \InvalidArgumentException For bad arguments; the message is shown to the model.
     */
    public function execute(ToolContext $context, array $arguments): ToolResult;
}
