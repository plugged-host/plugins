<?php

namespace PluggedHost\Ai\Tools\Any;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Ai\Services\Mclogs;
use PluggedHost\Ai\Support\SecretRedactor;
use PluggedHost\Ai\Tools\Tool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;
use RuntimeException;

/**
 * Read a log pasted on mclo.gs: the problems its analysis detected (with suggested fixes) and
 * the log's error entries. This is what "Ask neo to fix it" above the console starts with, and
 * it also works for any mclo.gs link a user shares in the chat. It only reads a public paste,
 * so it needs no server permission.
 */
final class ReadMclogsTool implements Tool
{
    private const MAX_PROBLEMS = 10;

    private const MAX_ENTRIES = 8;

    private const MAX_ENTRY_LINES = 14;

    private const MAX_LINE_CHARS = 400;

    public function name(): string
    {
        return 'read_mclogs';
    }

    public function description(): string
    {
        return 'Read a log shared on mclo.gs (by link or id): the problems mclo.gs detected with suggested fixes, detected versions, and the log\'s error entries. The log is a snapshot from when it was uploaded; check the current console and files before changing anything.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'log' => ['type' => 'string', 'description' => 'The mclo.gs link (https://mclo.gs/abc123) or just its id.'],
            ],
            'required' => ['log'],
        ];
    }

    public function scope(): string
    {
        return self::SCOPE_ANY;
    }

    public function availableFor(ToolContext $context): bool
    {
        return true;
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Read;
    }

    public function offeredWith(): array
    {
        return [];
    }

    public function requires(array $arguments): array
    {
        return [];
    }

    public function summarize(array $arguments): string
    {
        $id = is_string($arguments['log'] ?? null) ? Mclogs::idFrom($arguments['log']) : null;

        return $id === null ? 'Read a mclo.gs log' : "Read the mclo.gs log {$id}";
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $id = is_string($arguments['log'] ?? null) ? Mclogs::idFrom($arguments['log']) : null;
        if ($id === null) {
            throw new InvalidArgumentException('"log" must be a mclo.gs link like https://mclo.gs/abc123 or its id.');
        }

        $mclogs = app(Mclogs::class);

        try {
            $log = $mclogs->insights($id);
            $errors = $mclogs->entries($id, $log['size']);
            $level = 'error';

            if ($errors === [] && $log['problems'] === []) {
                $errors = $mclogs->entries($id, $log['size'], Mclogs::LEVEL_WARNING);
                $level = 'warning';
            }
        } catch (ConnectionException) {
            return ToolResult::error('mclo.gs could not be reached. Read the console instead.');
        } catch (RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        $out = [];
        $out[] = "mclo.gs log {$id}" . ($log['title'] ? " ({$log['title']})" : '') . ": {$log['lines']} lines, {$log['errors']} error entries. Link: {$log['url']}";

        $info = array_filter(array_map(static fn (array $item): string => trim((string) ($item['message'] ?? '')), $log['information']));
        if ($info !== []) {
            $out[] = 'Detected: ' . implode('; ', array_slice($info, 0, 8));
        }

        $out[] = '';
        if ($log['problems'] === []) {
            $out[] = 'mclo.gs\'s analysis found no known problem.';
        } else {
            $out[] = 'Problems found by mclo.gs\'s analysis (' . count($log['problems']) . '):';

            foreach (array_slice($log['problems'], 0, self::MAX_PROBLEMS) as $i => $problem) {
                $out[] = ($i + 1) . '. ' . $this->line((string) ($problem['message'] ?? '')) . ' (seen ' . max(1, (int) ($problem['counter'] ?? 1)) . '×)';
                array_push($out, ...$this->quote((array) ($problem['entry']['lines'] ?? []), '   '));

                $fixes = array_filter(array_map(fn (mixed $fix): string => is_array($fix) ? $this->line((string) ($fix['message'] ?? '')) : '', (array) ($problem['solutions'] ?? [])));
                if ($fixes !== []) {
                    $out[] = '   Suggested fixes: ' . implode(' | ', $fixes);
                }
            }
        }

        // Entries a problem above already quotes are not repeated.
        $quoted = [];
        foreach ($log['problems'] as $problem) {
            foreach ((array) ($problem['entry']['lines'] ?? []) as $line) {
                $quoted[(int) (is_array($line) ? ($line['number'] ?? 0) : 0)] = true;
            }
        }
        $errors = array_values(array_filter($errors, static fn (array $entry): bool => !isset($quoted[$entry['lines'][0]['number'] ?? -1])));

        if ($errors !== []) {
            $shown = array_slice($errors, -self::MAX_ENTRIES);
            $out[] = '';
            $out[] = ($log['problems'] === [] ? ucfirst($level) . ' entries' : 'Other ' . $level . ' entries') . ' in the log (' . (count($shown) < count($errors) ? 'last ' . count($shown) . ' of ' . count($errors) : count($errors)) . '):';

            foreach ($shown as $entry) {
                array_push($out, ...$this->quote($entry['lines'], '  '));
                $out[] = '';
            }
        } elseif ($log['errors'] > 0 && $log['problems'] === []) {
            $out[] = 'The error entries could not be listed (the log is too large). Read the console or logs/latest.log instead.';
        }

        $text = SecretRedactor::redact(implode("\n", $out));
        $max = max(2000, (int) config('plugged-host-ai.tool_output_chars', 12000));

        return ToolResult::ok(
            mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n…[truncated]" : $text,
            $log['problems'] === [] ? "{$log['errors']} error entries, no known problem" : count($log['problems']) . ' problem(s) found',
        );
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return list<string>
     */
    private function quote(array $lines, string $indent): array
    {
        $quoted = [];
        $lines = array_values(array_filter($lines, 'is_array'));

        foreach (array_slice($lines, 0, self::MAX_ENTRY_LINES) as $line) {
            $quoted[] = $indent . 'line ' . (int) ($line['number'] ?? 0) . ': ' . $this->line((string) ($line['content'] ?? ''));
        }

        if (count($lines) > self::MAX_ENTRY_LINES) {
            $quoted[] = $indent . '… ' . (count($lines) - self::MAX_ENTRY_LINES) . ' more lines';
        }

        return $quoted;
    }

    private function line(string $text): string
    {
        $text = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace("\t", '  ', $text));

        return Str::limit(trim($text), self::MAX_LINE_CHARS);
    }
}
