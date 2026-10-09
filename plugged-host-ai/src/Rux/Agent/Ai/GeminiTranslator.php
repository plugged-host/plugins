<?php

namespace PluggedHost\Ai\Rux\Agent\Ai;

use PluggedHost\Ai\Support\MessageContent;
use stdClass;

/**
 * Translation between rux's chat shape (OpenAI style) and the Gemini API's generateContent
 * (Google AI Studio: Gemini and Gemma models). Copied from noprofit.space's neo driver
 * (App\Services\Neo\Drivers\GeminiTranslator).
 *
 * Gemini rules this has to satisfy: the system prompt is a top-level systemInstruction; the
 * assistant role is "model"; every functionCall is answered by a functionResponse (which needs
 * the function's name) in the next user turn; empty objects stay objects. Gemini 3 models reject
 * a functionCall sent back without its thoughtSignature, so signatures are handed out with the
 * answer and asked for again on the way in.
 */
final class GeminiTranslator
{
    /**
     * Google's documented stand-in for a function call whose signature is unknown. Gemma models
     * answer it with HTTP 500 and do not need signatures at all, so they never get it.
     */
    public const SKIP_SIGNATURE = 'skip_thought_signature_validator';

    /**
     * {base}/models/{model}:generateContent. The base is whatever comes before /models/ in the
     * endpoint (…/v1beta when only the host is given); a query string (?key=…) is dropped, the
     * key always goes in a header.
     */
    public function requestUrl(string $endpoint, string $model): string
    {
        $parts = parse_url($endpoint) ?: [];
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        if (($at = strpos($path . '/', '/models/')) !== false) {
            $path = substr($path, 0, $at);
        }

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($path === '' ? '/v1beta' : $path)
            . '/models/' . self::modelId($model) . ':generateContent';
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  callable(string): ?string  $signatureFor  The signature a tool call id came back with.
     * @return array<string, mixed>
     */
    public function toBody(array $messages, array $tools, string $toolChoice, string $model, callable $signatureFor): array
    {
        $system = [];
        $contents = [];
        $names = [];
        $needsSignatures = !str_starts_with(strtolower(self::modelId($model)), 'gemma');

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
                $id = (string) ($message['tool_call_id'] ?? '');
                $content = (string) ($message['content'] ?? '');
                $content = $content === '' ? '(no output)' : $content;

                $this->append($contents, 'user', [['functionResponse' => array_filter([
                    'id' => $id,
                    'name' => $names[$id] ?? (string) ($message['name'] ?? 'tool'),
                    'response' => [empty($message['is_error']) ? 'output' : 'error' => $content],
                ], static fn (mixed $value): bool => $value !== '')]]);

                continue;
            }

            if ($role === 'assistant') {
                $parts = [];
                $text = trim((string) ($message['content'] ?? ''));
                if ($text !== '') {
                    $parts[] = ['text' => $text];
                }

                $signed = false;
                $firstCall = null;

                foreach ((array) ($message['tool_calls'] ?? []) as $call) {
                    $id = (string) ($call['id'] ?? '');
                    $name = (string) ($call['function']['name'] ?? '');
                    $names[$id] = $name;

                    $part = ['functionCall' => array_filter([
                        'id' => $id,
                        'name' => $name,
                        'args' => $this->decodeArguments($call['function']['arguments'] ?? '{}'),
                    ], static fn (mixed $value): bool => $value !== '')];

                    $signature = $id === '' ? null : $signatureFor($id);
                    if (is_string($signature) && $signature !== '') {
                        $part['thoughtSignature'] = $signature;
                        $signed = true;
                    }

                    $firstCall ??= count($parts);
                    $parts[] = $part;
                }

                // Gemini only checks the first function call of a turn.
                if ($needsSignatures && !$signed && $firstCall !== null) {
                    $parts[$firstCall]['thoughtSignature'] = self::SKIP_SIGNATURE;
                }

                if ($parts !== []) {
                    $this->append($contents, 'model', $parts);
                }

                continue;
            }

            $parts = array_values(array_filter(array_map(static fn (array $part): ?array => match (true) {
                $part['type'] === 'image' => ['inlineData' => ['mimeType' => $part['mime'], 'data' => $part['data']]],
                trim($part['text']) !== '' => ['text' => $part['text']],
                default => null,
            }, MessageContent::parts($message['content'] ?? ''))));

            if ($parts !== []) {
                $this->append($contents, 'user', $parts);
            }
        }

        if ($contents === [] || $contents[0]['role'] !== 'user') {
            array_unshift($contents, ['role' => 'user', 'parts' => [['text' => '(conversation continues)']]]);
        }

        if ($contents[array_key_last($contents)]['role'] === 'model') {
            $contents[] = ['role' => 'user', 'parts' => [['text' => '(continue)']]];
        }

        $body = ['contents' => $contents];

        if ($system !== []) {
            $body['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        if ($tools !== []) {
            // Through JSON once, so empty objects ("properties": {}) stay objects.
            $decoded = json_decode((string) json_encode($tools), false);

            $body['tools'] = [['functionDeclarations' => array_map(fn (object $tool): array => $this->tool($tool), (array) $decoded)]];
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => match ($toolChoice) {
                'none' => 'NONE',
                'required' => 'ANY',
                default => 'AUTO',
            }]];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>|null Null when it is not a usable generateContent answer.
     *                                   'signatures' holds the thought signature of each tool call
     *                                   that came with one, by id.
     */
    public function fromResponse(string $raw): ?array
    {
        $json = json_decode($raw, false);

        if (!$json instanceof stdClass) {
            return null;
        }

        $candidate = is_array($json->candidates ?? null) ? ($json->candidates[0] ?? null) : null;

        if (!$candidate instanceof stdClass && !isset($json->promptFeedback->blockReason)) {
            return null;
        }

        $finishReason = $candidate instanceof stdClass ? (string) ($candidate->finishReason ?? 'STOP') : 'SAFETY';

        if (in_array($finishReason, ['MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL'], true)) {
            return null;
        }

        $text = '';
        $toolCalls = [];
        $signatures = [];
        $parts = $candidate instanceof stdClass ? ($candidate->content->parts ?? []) : [];

        foreach (is_array($parts) ? $parts : [] as $part) {
            // Thought summaries come back even unasked; they are not part of the answer.
            if (!$part instanceof stdClass || !empty($part->thought)) {
                continue;
            }

            if (is_string($part->text ?? null)) {
                $text .= $part->text;
            }

            $call = $part->functionCall ?? null;
            if ($call instanceof stdClass && is_string($call->name ?? null) && $call->name !== '') {
                // Gemini's own ids (call_123456) can repeat across turns; ours never do.
                $id = 'call_' . bin2hex(random_bytes(12));
                $args = $call->args ?? null;

                $toolCalls[] = ['id' => $id, 'type' => 'function', 'function' => [
                    'name' => $call->name,
                    'arguments' => (string) json_encode($args instanceof stdClass ? $args : new stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]];

                if (is_string($part->thoughtSignature ?? null) && $part->thoughtSignature !== '') {
                    $signatures[$id] = $part->thoughtSignature;
                }
            }
        }

        $usage = $json->usageMetadata ?? null;
        $text = trim($text);

        return [
            'message' => ['role' => 'assistant', 'content' => $text === '' ? null : $text, 'tool_calls' => $toolCalls],
            'finish_reason' => $toolCalls !== [] ? 'tool_calls' : match ($finishReason) {
                'MAX_TOKENS' => 'length',
                'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' => 'content_filter',
                default => 'stop',
            },
            'usage' => [
                'prompt_tokens' => $usage instanceof stdClass
                    ? (int) ($usage->promptTokenCount ?? 0) + (int) ($usage->toolUsePromptTokenCount ?? 0)
                    : (int) ceil(strlen($raw) / 4),
                // Thinking is billed as output.
                'completion_tokens' => $usage instanceof stdClass ? (int) ($usage->candidatesTokenCount ?? 0) + (int) ($usage->thoughtsTokenCount ?? 0) : 0,
            ],
            'signatures' => $signatures,
        ];
    }

    /**
     * The bare model id: "models/gemini-3-flash-preview" and "gemma-4-31b-it:generateContent"
     * both name a model the URL already says how to call.
     */
    public static function modelId(string $model): string
    {
        return (string) preg_replace(['#^models/#', '#:[A-Za-z]+$#'], '', trim($model));
    }

    /**
     * Append parts, merging into the previous turn when the role repeats. In a merged user turn,
     * function responses come first.
     *
     * @param  list<array{role: string, parts: list<array<string, mixed>>}>  $contents
     * @param  list<array<string, mixed>>  $parts
     */
    private function append(array &$contents, string $role, array $parts): void
    {
        $last = array_key_last($contents);

        if ($last !== null && $contents[$last]['role'] === $role) {
            $merged = array_merge($contents[$last]['parts'], $parts);

            if ($role === 'user') {
                usort($merged, static fn (array $a, array $b): int => (isset($a['functionResponse']) ? 0 : 1) <=> (isset($b['functionResponse']) ? 0 : 1));
            }

            $contents[$last]['parts'] = $merged;

            return;
        }

        $contents[] = ['role' => $role, 'parts' => $parts];
    }

    private function decodeArguments(mixed $arguments): stdClass
    {
        if ($arguments instanceof stdClass) {
            return $arguments;
        }

        $decoded = is_string($arguments) ? json_decode($arguments, false) : null;

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }

    /**
     * Parameters go over as plain JSON Schema (parametersJsonSchema).
     *
     * @return array{name: string, description: string, parametersJsonSchema: object}
     */
    private function tool(object $tool): array
    {
        $function = $tool->function ?? $tool;
        $schema = $function->parameters ?? null;

        return [
            'name' => (string) ($function->name ?? ''),
            'description' => (string) ($function->description ?? ''),
            'parametersJsonSchema' => $schema instanceof stdClass ? $schema : (object) ['type' => 'object', 'properties' => new stdClass],
        ];
    }
}
