<?php

namespace PluggedHost\Rux\Tools\Moderation;

use InvalidArgumentException;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Support\RuxTime;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;

final class BanMemberTool extends ModerationTool
{
    public function name(): string
    {
        return 'ban_member';
    }

    public function description(): string
    {
        return 'Ban someone, for good or for a while (rux lifts it when the time is up), optionally deleting their recent messages. Works for people who already left (by id or mention).';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty('The person: <@mention>, user id, or a member\'s name.'),
            'reason' => $this->reasonProperty(),
            'duration' => ['type' => 'string', 'description' => 'How long, like "24h" or "7d". Leave out for a permanent ban.'],
            'delete_message_hours' => ['type' => 'integer', 'description' => 'Delete their messages from the last N hours (0 to 168). Default 0.'],
        ], ['member']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $input = $this->required($arguments, 'member');

        try {
            $target = $this->resolver->member($context->guildId, $input);
            $inServer = true;
        } catch (InvalidArgumentException $e) {
            if (preg_match('/^<@!?\d{17,20}>$|^\d{17,20}$/', trim($input)) !== 1) {
                throw $e;
            }

            $target = $this->resolver->user($input);
            $inServer = false;
        }

        // Also for people who are not in the server: a noprofit.space admin or staff member who
        // left must not be bannable by id.
        $this->guard($context, $target);

        $duration = $this->string($arguments, 'duration');
        $seconds = $duration !== null && !in_array(strtolower($duration), ['permanent', 'forever', '0'], true) ? RuxTime::duration($duration) : null;
        $case = $this->moderation->ban($context, $target, $this->string($arguments, 'reason'), max(0, min(168, $this->int($arguments, 'delete_message_hours') ?? 0)), $inServer, $seconds);

        return ToolResult::ok($this->caseResult($case, array_filter(['was_in_server' => $inServer, 'until' => $case->expires_at !== null ? RuxTime::human($case->expires_at) : 'permanent'])));
    }
}
