<?php

namespace PluggedHost\Neo\Agent;

use Illuminate\Support\Collection;
use PluggedHost\Neo\Models\NeoConversation;
use PluggedHost\Neo\Models\NeoMessage;
use PluggedHost\Neo\Tools\ToolContext;

/**
 * Turns the stored transcript into the canonical messages for one model call, within a token
 * budget (every call re-sends the context and is charged for it).
 *
 * The current exchange is kept; older exchanges are added newest-first while they fit, with
 * their tool outputs cut down to a stub. An assistant message is never separated from its
 * tool results: every provider rejects a tool call without an answer.
 */
final class ContextBuilder
{
    private const KEEP_FULL_RECENT_TOOLS = 3;

    public function __construct(private readonly SystemPromptBuilder $prompt) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function build(NeoConversation $conversation, ToolContext $context, bool $finalAnswer): array
    {
        $budget = max(4000, (int) config('plugged-host-neo.context_tokens', 16000));
        $system = ['role' => 'system', 'content' => $this->prompt->build($context)];

        $turns = $this->turns(
            $conversation->messages()->where('role', '!=', NeoMessage::NOTICE)->get(),
        );

        if ($turns === []) {
            return [$system];
        }

        $current = $this->compactCurrent(array_pop($turns), $budget - $this->estimate([$system]));
        $included = [$current];
        $used = $this->estimate([$system]) + $this->estimate($current);

        foreach (array_reverse($turns) as $turn) {
            $compact = array_map(fn (array $message): array => $this->stub($message), $turn);
            $cost = $this->estimate($compact);

            if ($used + $cost > $budget) {
                break;
            }

            array_unshift($included, $compact);
            $used += $cost;
        }

        $messages = [$system, ...array_merge(...$included)];

        if ($finalAnswer) {
            $messages[] = [
                'role' => 'system',
                'content' => 'You have used all the steps available for this message. Do not call any more tools: answer the user now, summarising what you found, what you changed and what is left to do.',
            ];
        }

        return $messages;
    }

    /**
     * Split rows into exchanges, each starting at a user message.
     *
     * @param  Collection<int, NeoMessage>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function turns(Collection $rows): array
    {
        $turns = [];
        $current = [];

        foreach ($rows as $row) {
            if ($row->role === NeoMessage::USER && $current !== []) {
                $turns[] = $current;
                $current = [];
            }

            $message = $this->canonical($row);
            if ($message !== null) {
                $current[] = $message;
            }
        }

        if ($current !== []) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function canonical(NeoMessage $row): ?array
    {
        return match ($row->role) {
            NeoMessage::USER => ['role' => 'user', 'content' => (string) $row->content],
            NeoMessage::ASSISTANT => array_filter([
                'role' => 'assistant',
                'content' => $row->content,
                'tool_calls' => $row->tool_calls ?: null,
            ], static fn (mixed $value): bool => $value !== null) + ['content' => null],
            NeoMessage::TOOL => [
                'role' => 'tool',
                'tool_call_id' => (string) $row->tool_call_id,
                'content' => '<tool_output tool="' . $row->tool_name . '" untrusted="true">' . "\n" . (string) $row->content . "\n</tool_output>",
                'is_error' => in_array($row->status, [NeoMessage::FAILED, NeoMessage::DENIED, NeoMessage::SKIPPED], true),
                '_size' => strlen((string) $row->content),
            ],
            default => null,
        };
    }

    /**
     * Keep the current exchange, but if a long tool loop outgrows the budget, stub all but the
     * most recent tool outputs.
     *
     * @param  list<array<string, mixed>>  $turn
     * @return list<array<string, mixed>>
     */
    private function compactCurrent(array $turn, int $budget): array
    {
        $turn = array_map(static fn (array $message): array => array_diff_key($message, ['_size' => true]), $turn);

        if ($this->estimate($turn) <= $budget) {
            return $turn;
        }

        $toolIndexes = array_keys(array_filter($turn, static fn (array $message): bool => $message['role'] === 'tool'));
        $older = array_slice($toolIndexes, 0, max(0, count($toolIndexes) - self::KEEP_FULL_RECENT_TOOLS));

        foreach ($older as $index) {
            $turn[$index] = $this->stub($turn[$index]);
        }

        return $turn;
    }

    /**
     * Replace a bulky old tool result or file payload with a note of what was there.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function stub(array $message): array
    {
        $size = $message['_size'] ?? null;
        unset($message['_size']);

        if ($message['role'] === 'tool' && strlen((string) $message['content']) > 600) {
            $message['content'] = '[earlier tool output omitted, ' . ($size ?? strlen((string) $message['content'])) . ' characters]';
        }

        if ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
            $message['tool_calls'] = array_map(static function (array $call): array {
                if (strlen((string) ($call['function']['arguments'] ?? '')) > 2000) {
                    $call['function']['arguments'] = '{"omitted":"large arguments from an earlier step"}';
                }

                return $call;
            }, $message['tool_calls']);
        }

        return $message;
    }

    /**
     * Rough token estimate (≈3 characters per token for mixed code and prose).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private function estimate(array $messages): int
    {
        return (int) ceil(strlen((string) json_encode($messages)) / 3);
    }
}
