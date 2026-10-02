<?php

namespace PluggedHost\Ai\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class SendDmTool extends ModerationTool
{
    public function name(): string
    {
        return 'send_dm';
    }

    public function description(): string
    {
        return 'Send a member a direct message as rux. It fails only if they turned off DMs from server members.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty(),
            'content' => ['type' => 'string', 'description' => 'The message, Discord markdown, up to 2000 characters.'],
        ], ['member', 'content']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $content = $this->required($arguments, 'content');

        if (mb_strlen($content) > 2000) {
            throw new InvalidArgumentException('The message is longer than Discord\'s 2000 characters. Shorten it.');
        }

        $sent = $this->discord->sendDm($target['id'], ['content' => $content, 'allowed_mentions' => ['parse' => []]]);

        return $sent
            ? ToolResult::ok(['sent' => true, 'to' => "<@{$target['id']}>"])
            : ToolResult::error("<@{$target['id']}> does not accept DMs from rux (they turned off DMs from server members).");
    }
}
