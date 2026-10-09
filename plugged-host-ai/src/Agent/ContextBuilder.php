<?php

namespace PluggedHost\Ai\Agent;

use Illuminate\Support\Collection;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Models\NeoMessage;
use PluggedHost\Ai\Support\ImageInput;
use PluggedHost\Ai\Support\MessageContent;
use PluggedHost\Ai\Tools\ToolContext;

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
        $budget = max(4000, (int) config('plugged-host-ai.context_tokens', 16000));
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

        foreach (array_reverse($turns) as $age => $turn) {
            // The exchange before this one keeps its images, for a follow-up about them.
            $compact = array_map(fn (array $message): array => $this->stub($message, keepImages: $age === 0), $turn);
            $cost = $this->estimate($compact);

            if ($used + $cost > $budget) {
                break;
            }

            array_unshift($included, $compact);
            $used += $cost;
        }

        $messages = $this->withImages([$system, ...array_merge(...$included)]);

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
            NeoMessage::USER => self::user($row),
            NeoMessage::ASSISTANT => array_filter([
                'role' => 'assistant',
                'content' => $row->content,
                'tool_calls' => $row->tool_calls ?: null,
            ], static fn (mixed $value): bool => $value !== null) + ['content' => null],
            NeoMessage::TOOL => [
                'role' => 'tool',
                'tool_call_id' => (string) $row->tool_call_id,
                // Tags inside the output are neutralised, so the text cannot close its own fence.
                'content' => '<tool_output tool="' . $row->tool_name . '" untrusted="true">' . "\n" . (string) preg_replace('#<(/?)tool_output#i', '‹$1tool_output', (string) $row->content) . "\n</tool_output>",
                'is_error' => in_array($row->status, [NeoMessage::FAILED, NeoMessage::DENIED, NeoMessage::SKIPPED], true),
                '_size' => strlen((string) $row->content),
            ],
            default => null,
        };
    }

    /**
     * A user message; images it came with are listed in _images until withImages() reads them.
     *
     * @return array<string, mixed>
     */
    private static function user(NeoMessage $row): array
    {
        $paths = array_values(array_filter(array_column((array) (((array) $row->meta)['images'] ?? []), 'path'), 'is_string'));

        if ($paths === []) {
            return ['role' => 'user', 'content' => (string) $row->content];
        }

        $note = '(They attached ' . (count($paths) === 1 ? 'an image' : count($paths) . ' images') . '.)';

        return ['role' => 'user', 'content' => trim((string) $row->content . "\n" . $note), '_images' => $paths];
    }

    /**
     * Read the images still listed in the messages into image parts, newest first, at most
     * ImageInput::MAX_IMAGES; ones that are gone or over the limit stay a note.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function withImages(array $messages): array
    {
        $left = ImageInput::MAX_IMAGES;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (!isset($messages[$i]['_images'])) {
                continue;
            }

            $images = [];
            foreach ($messages[$i]['_images'] as $path) {
                if ($left > 0 && ($image = ChatImages::dataUrl($path)) !== null) {
                    $images[] = $image;
                    $left--;
                }
            }

            unset($messages[$i]['_images']);
            $messages[$i]['content'] = MessageContent::with((string) $messages[$i]['content'], $images);
        }

        return $messages;
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
    private function stub(array $message, bool $keepImages = false): array
    {
        $size = $message['_size'] ?? null;
        unset($message['_size']);

        if (!$keepImages) {
            unset($message['_images']);
        }

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
     * Rough token estimate (≈3 characters per token for mixed code and prose, ≈1600 an image).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private function estimate(array $messages): int
    {
        $images = array_sum(array_map(static fn (array $message): int => count($message['_images'] ?? []), $messages));

        return (int) ceil(strlen((string) json_encode($messages)) / 3) + 1600 * $images;
    }
}
