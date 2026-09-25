<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use PluggedHost\Neo\Services\ConsoleHistory;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;

final class ConsoleReadTool extends BaseServerTool
{
    public function name(): string
    {
        return 'console_read';
    }

    public function description(): string
    {
        return 'Read the most recent lines of the server console (up to 100). Use it to diagnose crashes, errors and startup problems.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ConsoleHistory::MAX_LINES, 'description' => 'How many recent lines to read (default 80).'],
            ],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::WebsocketConnect];
    }

    public function summarize(array $arguments): string
    {
        return 'Read the console';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $lines = (int) ($arguments['lines'] ?? config('plugged-host-neo.console_lines', 80));
        $result = app(ConsoleHistory::class)->tail($context->server, $lines);

        if (!$result['ok']) {
            return ToolResult::error('Could not read the console: ' . $result['error']);
        }

        if ($result['lines'] === []) {
            return ToolResult::ok('The console is empty (the server may not have been started since the node restarted).', 'Console is empty');
        }

        $text = implode("\n", $result['lines']);
        $max = max(1000, (int) config('plugged-host-neo.tool_output_chars', 12000));

        // Keep the end: the newest lines are the ones that matter.
        if (mb_strlen($text) > $max) {
            $text = "…[earlier lines truncated]\n" . mb_substr($text, -$max);
        }

        return ToolResult::ok($text, 'Read ' . count($result['lines']) . ' console lines');
    }
}
