<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use Carbon\CarbonImmutable;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxText;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class ReadMessagesTool extends ModerationTool
{
    /** Characters of message text per answer, so it fits in rux's tool output. */
    private const TEXT_BUDGET = 5000;

    public function name(): string
    {
        return 'read_messages';
    }

    public function description(): string
    {
        return 'Read messages: the latest in a channel (optionally only one member\'s), or one message by its link. Gives each message\'s id, time, author and text (embeds too): to find a message to delete, see what someone said, or read the rules posted in the server.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'channel' => ['type' => 'string', 'description' => 'Channel (#name, <#id> or id). Leave out for the channel the admin is talking in.'],
            'from' => $this->memberProperty('Only this person\'s messages (optional).'),
            'message' => ['type' => 'string', 'description' => 'Just this message: its link, or its id (with channel).'],
            'count' => ['type' => 'integer', 'description' => 'How many of the latest messages, 1 to 50. Default 15.'],
        ]);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $channelInput = $this->string($arguments, 'channel');

        if (($messageInput = $this->string($arguments, 'message')) !== null) {
            $found = $this->resolver->message($context->guildId, $messageInput, $channelInput ?? ($context->direct ? '' : $context->channelId));
            $this->guardVisible($context, $found['channel']);

            return ToolResult::ok([
                'channel' => '<#' . $found['channel']['id'] . '>',
                'times_in' => RuxConfig::timezone(),
                'messages' => [$this->line($found['message'], 4000)],
            ]);
        }

        $channel = $this->messageChannel($context, $channelInput);
        $this->guardVisible($context, $channel);

        $count = max(1, min(50, $this->int($arguments, 'count') ?? 15));
        $fromInput = $this->string($arguments, 'from');
        $from = $fromInput !== null ? $this->memberOrUser($context, $fromInput)['id'] : null;

        // One person's messages are looked for in the last 500; otherwise one page is enough.
        $limit = $from === null ? min(100, $count + 1) : 100;
        $pages = $from === null ? 1 : 5;
        $picked = [];
        $before = null;

        for ($page = 0; $page < $pages && count($picked) < $count; $page++) {
            $messages = $this->discord->messages((string) $channel['id'], array_filter(['limit' => $limit, 'before' => $before]));

            foreach ($messages as $message) {
                $before = (string) $message['id'];

                if ($before === $context->sourceMessageId || ($from !== null && (string) ($message['author']['id'] ?? '') !== $from)) {
                    continue;
                }

                $picked[] = $message;

                if (count($picked) >= $count) {
                    break;
                }
            }

            if (count($messages) < $limit) {
                break;
            }
        }

        $each = max(120, min(1500, intdiv(self::TEXT_BUDGET, max(1, count($picked)))));

        return ToolResult::ok(array_filter([
            'channel' => '<#' . $channel['id'] . '>',
            'times_in' => RuxConfig::timezone(),
            'messages' => array_map(fn (array $message): string => $this->line($message, $each), array_reverse($picked)),
            'note' => $picked === [] ? ($from !== null ? "No messages from <@{$from}> among the last 500." : 'The channel has no messages.') : null,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * One message, oldest first in the answer: "id · time · author: text".
     *
     * @param  array<string, mixed>  $message
     */
    private function line(array $message, int $limit): string
    {
        $author = (array) ($message['author'] ?? []);
        $name = (string) ($author['global_name'] ?? $author['username'] ?? '?');
        $text = RuxText::messageText($message);
        $attachments = count((array) ($message['attachments'] ?? []));
        $time = CarbonImmutable::parse((string) $message['timestamp'])->setTimezone(RuxConfig::timezone())->format('D j M H:i');

        return $message['id'] . ' · ' . $time
            . ' · <@' . ($author['id'] ?? '?') . '> (' . RuxText::untrusted($name, 40) . (($author['bot'] ?? false) ? ', a bot' : '') . ')'
            . (isset($message['message_reference']['message_id']) ? ', replying to ' . $message['message_reference']['message_id'] : '')
            . (($message['pinned'] ?? false) ? ', pinned' : '')
            . ': ' . ($text !== '' ? RuxText::untrusted($text, $limit) : '(no text)')
            . ($attachments > 0 ? " [{$attachments} attachment" . ($attachments > 1 ? 's' : '') . ']' : '');
    }
}
