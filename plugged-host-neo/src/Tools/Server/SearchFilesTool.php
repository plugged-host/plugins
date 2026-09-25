<?php

namespace PluggedHost\Neo\Tools\Server;

use App\Enums\SubuserPermission;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PluggedHost\Neo\Tools\BaseServerTool;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;

final class SearchFilesTool extends BaseServerTool
{
    private const MAX_RESULTS = 100;

    public function name(): string
    {
        return 'search_files';
    }

    public function description(): string
    {
        return 'Find files and folders whose name contains a pattern (at least 3 characters), searching below a directory.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'Part of a file or folder name, e.g. "latest.log" or ".yml".'],
                'directory' => ['type' => 'string', 'description' => 'Where to search, default "/".'],
            ],
            'required' => ['pattern'],
        ];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileRead];
    }

    public function summarize(array $arguments): string
    {
        return 'Search for "' . mb_substr((string) ($arguments['pattern'] ?? ''), 0, 40) . '"';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $pattern = trim($this->string($arguments, 'pattern', 100));
        if (mb_strlen($pattern) < 3) {
            throw new InvalidArgumentException('The pattern must be at least 3 characters.');
        }

        $directory = $this->path($arguments['directory'] ?? null);
        $server = $context->server;

        // Not DaemonFileRepository::search(): that waits up to two minutes, far past what one
        // chat step may take.
        $response = Http::daemon($server->node)->timeout(15)->get("/api/servers/{$server->uuid}/files/search", [
            'pattern' => $pattern,
            'directory' => $directory,
        ]);

        if (!$response->successful()) {
            return ToolResult::error("Search failed (HTTP {$response->status()}).");
        }

        $results = [];
        foreach (array_slice((array) $response->json(), 0, self::MAX_RESULTS) as $entry) {
            if (is_array($entry)) {
                $results[] = (($entry['directory'] ?? false) ? '[dir]  ' : '[file] ') . (string) ($entry['name'] ?? '?');
            }
        }

        return ToolResult::ok($results === [] ? 'No matches.' : implode("\n", $results), count($results) . ' matches');
    }
}
