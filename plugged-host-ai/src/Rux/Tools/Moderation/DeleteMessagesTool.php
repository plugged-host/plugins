<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class DeleteMessagesTool extends ModerationTool
{
    private const MAX = 25;

    public function name(): string
    {
        return 'delete_messages';
    }

    public function description(): string
    {
        return 'Delete particular messages, of any age: by link, or by id (read_messages shows ids; a replied-to message\'s id is in the order). For "the last N messages" use purge_messages.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'messages' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Message links or ids, up to 25.'],
            'channel' => ['type' => 'string', 'description' => 'The channel of messages given by id (#name, <#id> or id). Leave out for the channel the admin is talking in.'],
            'reason' => $this->reasonProperty(),
        ], ['messages']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $refs = array_values(array_unique(array_filter(
            array_map(static fn ($ref): string => is_scalar($ref) ? trim((string) $ref) : '', (array) ($arguments['messages'] ?? [])),
            static fn (string $ref): bool => $ref !== '',
        )));

        if ($refs === []) {
            throw new InvalidArgumentException('Say which messages: links, or ids with their channel (read_messages finds them).');
        }

        if (count($refs) > self::MAX) {
            throw new InvalidArgumentException('At most ' . self::MAX . ' messages at a time. purge_messages deletes the latest N in one go.');
        }

        $channelInput = $this->string($arguments, 'channel') ?? ($context->direct ? '' : $context->channelId);
        $byChannel = [];
        $skipped = [];

        foreach ($refs as $ref) {
            try {
                $found = $this->resolver->message($context->guildId, $ref, $channelInput);
            } catch (InvalidArgumentException $e) {
                $skipped[] = "{$ref}: {$e->getMessage()}";

                continue;
            }

            $channel = $found['channel'];
            $message = $found['message'];
            $this->guardChannel($context, $channel);
            $this->guardVisible($context, $channel);

            if (!$context->isAdmin() && $this->isProtected($context, (string) ($message['author']['id'] ?? ''))) {
                $skipped[] = "{$message['id']}: written by staff, an admin or this moderator, so a moderator cannot delete it";

                continue;
            }

            $byChannel[(string) $channel['id']]['channel'] = $channel;
            $byChannel[(string) $channel['id']]['messages'][(string) $message['id']] = $message;
        }

        if ($byChannel === []) {
            throw new InvalidArgumentException('Nothing was deleted. ' . implode(' ', $skipped));
        }

        $deleted = 0;
        $cases = [];

        foreach ($byChannel as $group) {
            $result = $this->moderation->deleteMessages($context, $group['channel'], array_values($group['messages']), $this->string($arguments, 'reason'));
            $deleted += $result['deleted'];

            foreach ($result['cases'] as $case) {
                $cases[] = "#{$case->id} (<@{$case->target_id}>)";
            }
        }

        return ToolResult::ok(array_filter([
            'deleted' => $deleted,
            'channels' => array_map(static fn (string $id): string => "<#{$id}>", array_keys($byChannel)),
            'cases' => $cases,
            'skipped' => $skipped ?: null,
        ], static fn ($value): bool => $value !== null));
    }
}
