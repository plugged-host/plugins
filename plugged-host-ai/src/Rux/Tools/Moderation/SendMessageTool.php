<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class SendMessageTool extends ModerationTool
{
    public function name(): string
    {
        return 'send_message';
    }

    public function description(): string
    {
        return 'Post a message as rux in a channel (announcements, rules, replies). Use the admin\'s wording when they give it.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'channel' => ['type' => 'string', 'description' => 'Channel (#name, <#id> or id).'],
            'content' => ['type' => 'string', 'description' => 'The message, Discord markdown, up to 2000 characters.'],
            'ping_everyone' => ['type' => 'boolean', 'description' => 'Let @everyone/@here in the text actually ping. Only when the admin asked for a ping.'],
        ], ['channel', 'content']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $channel = $this->resolver->channel($context->guildId, $this->required($arguments, 'channel'));
        $content = $this->required($arguments, 'content');

        if (mb_strlen($content) > 2000) {
            throw new InvalidArgumentException('The message is longer than Discord\'s 2000 characters. Shorten it.');
        }

        $parse = $this->bool($arguments, 'ping_everyone') ? ['users', 'roles', 'everyone'] : ['users'];
        $message = $this->discord->createMessage((string) $channel['id'], ['content' => $content, 'allowed_mentions' => ['parse' => $parse]]);

        return ToolResult::ok([
            'posted' => true,
            'channel' => '<#' . $channel['id'] . '>',
            'link' => "https://discord.com/channels/{$context->guildId}/{$channel['id']}/{$message['id']}",
        ]);
    }
}
