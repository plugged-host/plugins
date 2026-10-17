<?php

namespace PluggedHost\Ai\Chat;

use PluggedHost\Ai\Support\MessageContent;
use stdClass;

/**
 * Translation between the chat shape rux and neo work in (OpenAI style: system/user/assistant/tool
 * messages, tool_calls with JSON-string arguments) and the Anthropic Messages API that
 * justwoker speaks. Copied from noprofit.space's neo driver (App\Services\Neo\Drivers\
 * AnthropicTranslator), which is known to work against justwoker.
 *
 * Anthropic rules this has to satisfy: system prompt is top-level; roles alternate and start
 * with user; every tool_use is answered by tool_result blocks at the start of the next user
 * message; no trailing assistant message; empty objects stay objects.
 */
final class AnthropicTranslator
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function toBody(array $messages, array $tools, string $toolChoice, string $model, int $maxTokens): array
    {
        $system = [];
        $out = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'system') {
                $text = trim((string) ($message['content'] ?? ''));
                if ($text !== '') {
                    $system[] = $text;
                }

                continue;
            }

            if ($role === 'tool') {
                $content = (string) ($message['content'] ?? '');
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content' => $content === '' ? '(no output)' : $content,
                ];

                if (!empty($message['is_error'])) {
                    $block['is_error'] = true;
                }

                $this->append($out, 'user', [$block]);

                continue;
            }

            if ($role === 'assistant') {
                $blocks = [];
                $text = trim((string) ($message['content'] ?? ''));
                if ($text !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $text];
                }

                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => (string) ($call['id'] ?? ''),
                        'name' => (string) ($call['function']['name'] ?? ''),
                        'input' => $this->decodeArguments($call['function']['arguments'] ?? '{}'),
                    ];
                }

                if ($blocks !== []) {
                    $this->append($out, 'assistant', $blocks);
                }

                continue;
            }

            $blocks = array_values(array_filter(array_map(static fn (array $part): ?array => match (true) {
                $part['type'] === 'image' => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part['mime'], 'data' => $part['data']]],
                trim($part['text']) !== '' => ['type' => 'text', 'text' => $part['text']],
                default => null,
            }, MessageContent::parts($message['content'] ?? ''))));

            if ($blocks !== []) {
                $this->append($out, 'user', $blocks);
            }
        }

        if ($out === [] || $out[0]['role'] !== 'user') {
            array_unshift($out, ['role' => 'user', 'content' => [['type' => 'text', 'text' => '(conversation continues)']]]);
        }

        if ($out[array_key_last($out)]['role'] === 'assistant') {
            $out[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '(continue)']]];
        }

        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $out];

        if ($system !== []) {
            $body['system'] = implode("\n\n", $system);
        }

        if ($tools !== []) {
            $body['tools'] = array_map(fn (array $tool): array => $this->tool($tool), $tools);
            $body['tool_choice'] = ['type' => $toolChoice === 'none' ? 'none' : 'auto'];
        }

        return $body;
    }

    /**
     * The answer in the chat shape: ['message' => [content, tool_calls], 'finish_reason',
     * 'usage' => [prompt_tokens, completion_tokens]], or null when it is not a Messages answer.
     *
     * @return array<string, mixed>|null
     */
    public function fromResponse(string $raw): ?array
    {
        $json = json_decode($raw, false);

        if (!$json instanceof stdClass || ($json->type ?? null) === 'error' || !is_array($json->content ?? null)) {
            return null;
        }

        $text = '';
        $toolCalls = [];
        $seen = [];

        foreach ($json->content as $block) {
            if (!$block instanceof stdClass) {
                continue;
            }

            if (($block->type ?? null) === 'text') {
                $text .= (string) ($block->text ?? '');
            } elseif (($block->type ?? null) === 'tool_use' && is_string($block->name ?? null)) {
                $input = $block->input ?? null;
                $toolCalls[] = [
                    'id' => $this->toolCallId($block->id ?? null, $seen),
                    'type' => 'function',
                    'function' => [
                        'name' => $block->name,
                        'arguments' => (string) json_encode($input instanceof stdClass ? $input : new stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
        }

        $usage = $json->usage ?? new stdClass;
        $text = trim($text);

        return [
            'message' => ['role' => 'assistant', 'content' => $text === '' ? null : $text, 'tool_calls' => $toolCalls],
            'finish_reason' => $toolCalls !== [] ? 'tool_calls' : match ($json->stop_reason ?? null) {
                'max_tokens', 'model_context_window_exceeded' => 'length',
                'refusal' => 'content_filter',
                default => 'stop',
            },
            'usage' => [
                'prompt_tokens' => (int) ($usage->input_tokens ?? 0) + (int) ($usage->cache_creation_input_tokens ?? 0) + (int) ($usage->cache_read_input_tokens ?? 0),
                'completion_tokens' => (int) ($usage->output_tokens ?? 0),
            ],
        ];
    }

    /**
     * Append blocks, merging into the previous message when the role repeats. In a merged user
     * message, tool_result blocks must come first.
     *
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @param  list<array<string, mixed>>  $blocks
     */
    private function append(array &$messages, string $role, array $blocks): void
    {
        $last = array_key_last($messages);

        if ($last !== null && $messages[$last]['role'] === $role) {
            $merged = array_merge($messages[$last]['content'], $blocks);

            if ($role === 'user') {
                usort($merged, static fn (array $a, array $b): int => ($a['type'] === 'tool_result' ? 0 : 1) <=> ($b['type'] === 'tool_result' ? 0 : 1));
            }

            $messages[$last]['content'] = $merged;

            return;
        }

        $messages[] = ['role' => $role, 'content' => $blocks];
    }

    private function decodeArguments(mixed $arguments): stdClass
    {
        $decoded = is_string($arguments) ? json_decode($arguments, false) : null;

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }

    /**
     * A tool definition as Anthropic wants it. The schema goes through JSON once so empty
     * objects ("properties": {}) stay objects instead of becoming lists.
     *
     * @param  array<string, mixed>  $tool
     * @return array{name: string, description: string, input_schema: object}
     */
    private function tool(array $tool): array
    {
        $function = (array) ($tool['function'] ?? $tool);
        $schema = json_decode((string) json_encode($function['parameters'] ?? new stdClass), false);

        return [
            'name' => (string) ($function['name'] ?? ''),
            'description' => (string) ($function['description'] ?? ''),
            'input_schema' => $schema instanceof stdClass ? $schema : (object) ['type' => 'object', 'properties' => new stdClass],
        ];
    }

    /**
     * Tool-call ids must be unique and match ^[A-Za-z0-9_-]{1,64}$.
     *
     * @param  array<string, true>  $seen
     */
    private function toolCallId(mixed $id, array &$seen): string
    {
        $id = is_string($id) ? (string) preg_replace('/[^A-Za-z0-9_-]/', '', $id) : '';
        $id = substr($id, 0, 64);

        if ($id === '' || isset($seen[$id])) {
            $id = 'call_' . bin2hex(random_bytes(12));
        }

        $seen[$id] = true;

        return $id;
    }
}
