<?php

namespace PluggedHost\Ai\Chat;

use stdClass;

/**
 * OpenAI-compatible chat.completions (Groq). Copied from noprofit.space's neo driver
 * (App\Services\Neo\Drivers\OpenAiChatDriver): the chat shape rux and neo work in is this one,
 * so it mostly cleans up provider quirks on the way back (Qwen <think> leaks, Hermes-style inline
 * <tool_call> text).
 */
final class OpenAiTranslator
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function toBody(array $messages, array $tools, string $toolChoice, string $model, int $maxTokens): array
    {
        $body = [
            'model' => $model,
            'messages' => array_map(static function (array $message): array {
                $role = (string) ($message['role'] ?? 'user');

                if ($role === 'tool') {
                    $content = (string) ($message['content'] ?? '');
                    if (!empty($message['is_error'])) {
                        $content = 'ERROR: ' . $content;
                    }

                    return ['role' => 'tool', 'tool_call_id' => (string) ($message['tool_call_id'] ?? ''), 'content' => $content === '' ? '(no output)' : $content];
                }

                if ($role === 'assistant') {
                    $out = ['role' => 'assistant', 'content' => $message['content'] ?? null];

                    if (!empty($message['tool_calls'])) {
                        $out['tool_calls'] = array_map(static fn (array $call): array => [
                            'id' => (string) ($call['id'] ?? ''),
                            'type' => 'function',
                            'function' => ['name' => (string) ($call['function']['name'] ?? ''), 'arguments' => (string) ($call['function']['arguments'] ?? '{}')],
                        ], array_values($message['tool_calls']));
                    }

                    return $out;
                }

                return ['role' => $role, 'content' => (string) ($message['content'] ?? '')];
            }, $messages),
            'max_completion_tokens' => $maxTokens,
            'stream' => false,
        ];

        if ($tools !== []) {
            // Through JSON once, so empty objects ("properties": {}) stay objects.
            $body['tools'] = json_decode((string) json_encode($tools), false);
            $body['tool_choice'] = $toolChoice;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>|null Null when it is not a chat.completions answer.
     */
    public function fromResponse(string $raw): ?array
    {
        $json = json_decode($raw, true);
        $choice = is_array($json) ? ($json['choices'][0] ?? null) : null;

        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            return null;
        }

        $message = $choice['message'];
        $content = is_string($message['content'] ?? null) ? trim((string) preg_replace('/<think>.*?(<\/think>|$)/s', '', $message['content'])) : '';
        $content = $content === '' ? null : $content;

        $seen = [];
        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $name = $call['function']['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $arguments = $call['function']['arguments'] ?? '{}';
            if (!is_string($arguments)) {
                $arguments = (string) json_encode($arguments === [] ? new stdClass : $arguments);
            }

            $toolCalls[] = ['id' => self::id($call['id'] ?? null, $seen), 'type' => 'function', 'function' => ['name' => $name, 'arguments' => trim($arguments) === '' ? '{}' : $arguments]];
        }

        // Hermes-style <tool_call>{...}</tool_call> written as text instead of a real call.
        if ($toolCalls === [] && $content !== null && str_contains($content, '<tool_call>')) {
            $content = trim((string) preg_replace_callback('/<tool_call>\s*(\{.*?\})\s*<\/tool_call>/s', static function (array $m) use (&$toolCalls, &$seen): string {
                $decoded = json_decode($m[1], true);
                if (!is_array($decoded) || !is_string($decoded['name'] ?? null)) {
                    return $m[0];
                }

                $arguments = $decoded['arguments'] ?? [];
                $toolCalls[] = ['id' => self::id(null, $seen), 'type' => 'function', 'function' => [
                    'name' => $decoded['name'],
                    'arguments' => is_string($arguments) ? $arguments : (string) json_encode($arguments === [] ? new stdClass : $arguments),
                ]];

                return '';
            }, $content)) ?: null;
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return [
            'message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls],
            'finish_reason' => $toolCalls !== [] ? 'tool_calls' : match ($choice['finish_reason'] ?? 'stop') {
                'length' => 'length',
                'content_filter' => 'content_filter',
                default => 'stop',
            },
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? ceil(strlen($raw) / 4)),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function id(mixed $id, array &$seen): string
    {
        $id = substr(is_string($id) ? (string) preg_replace('/[^A-Za-z0-9_-]/', '', $id) : '', 0, 64);

        if ($id === '' || isset($seen[$id])) {
            $id = 'call_' . bin2hex(random_bytes(12));
        }

        $seen[$id] = true;

        return $id;
    }
}
