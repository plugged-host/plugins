<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class EditMessageTool extends ModerationTool
{
    public function name(): string
    {
        return 'edit_message';
    }

    public function description(): string
    {
        return 'Change the text of a message rux posted (fix an announcement), and/or pin or unpin any message.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'message' => ['type' => 'string', 'description' => 'The message: its link, or its id (with channel).'],
            'channel' => ['type' => 'string', 'description' => 'The channel, when the message is given by id. Leave out for the channel the admin is talking in.'],
            'content' => ['type' => 'string', 'description' => 'The new text, replacing the old (rux\'s own messages only), up to 2000 characters.'],
            'pinned' => ['type' => 'boolean', 'description' => 'true pins the message, false unpins it.'],
        ], ['message']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $found = $this->resolver->message($context->guildId, $this->required($arguments, 'message'), $this->string($arguments, 'channel') ?? ($context->direct ? '' : $context->channelId));
        $channelId = (string) $found['channel']['id'];
        $messageId = (string) $found['message']['id'];
        $done = [];

        if (($content = $this->string($arguments, 'content')) !== null) {
            if ((string) ($found['message']['author']['id'] ?? '') !== RuxConfig::applicationId()) {
                throw new InvalidArgumentException('rux can only edit its own messages. It can delete this one (delete_messages) or post a new one.');
            }

            if (RuxGiveaway::query()->where('message_id', $messageId)->exists()) {
                throw new InvalidArgumentException('That is a giveaway post: change the giveaway with edit_giveaway instead.');
            }

            if (mb_strlen($content) > 2000) {
                throw new InvalidArgumentException('The message is longer than Discord\'s 2000 characters. Shorten it.');
            }

            $this->discord->editMessage($channelId, $messageId, ['content' => $content, 'allowed_mentions' => ['parse' => ['users']]]);
            $done['edited'] = true;
        }

        if (array_key_exists('pinned', $arguments)) {
            $pinned = $this->bool($arguments, 'pinned');
            $this->discord->pin($channelId, $messageId, $pinned, $context->auditReason(null));
            $done['pinned'] = $pinned;
        }

        if ($done === []) {
            throw new InvalidArgumentException('Say the new text (content), or whether to pin or unpin it.');
        }

        return ToolResult::ok(['message' => "https://discord.com/channels/{$context->guildId}/{$channelId}/{$messageId}"] + $done);
    }
}
