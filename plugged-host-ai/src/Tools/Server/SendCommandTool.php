<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Services\ConsoleHistory;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class SendCommandTool extends BaseServerTool
{
    public function name(): string
    {
        return 'send_command';
    }

    public function description(): string
    {
        return 'Run one command in the server console (without a leading slash for Minecraft), then return the console lines that followed. The server must be running.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'A single console command, e.g. "list" or "say Restarting in 5 minutes".'],
            ],
            'required' => ['command'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::ControlConsole];
    }

    public function summarize(array $arguments): string
    {
        return 'Run: ' . mb_substr((string) ($arguments['command'] ?? ''), 0, 80);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $command = trim($this->string($arguments, 'command', 512));
        if (preg_match('/[\r\n\x00]/', $command) === 1) {
            throw new InvalidArgumentException('Send one command at a time, without line breaks.');
        }

        $server = $context->server;
        $state = $server->retrieveStatus();
        if (!in_array($state, [ContainerStatus::Running, ContainerStatus::Starting], true)) {
            return ToolResult::error("The server is {$state->value}; start it before sending console commands.");
        }

        $response = $server->send($command);
        if ($response->getStatusCode() >= 400) {
            return ToolResult::error("The node refused the command (HTTP {$response->getStatusCode()}).");
        }

        $this->activity($context, 'command')->property('command', $command)->log();

        usleep(1_500_000);
        $tail = app(ConsoleHistory::class)->tail($server, 30);
        $output = $tail['ok'] ? implode("\n", $tail['lines']) : '(console output unavailable)';

        return ToolResult::ok($this->limitOutput("Sent: {$command}\nConsole afterwards:\n{$output}"), 'Ran: ' . mb_substr($command, 0, 60));
    }
}
