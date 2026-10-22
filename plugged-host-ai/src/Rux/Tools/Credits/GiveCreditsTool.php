<?php

namespace PluggedHost\Ai\Rux\Tools\Credits;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Tools\Moderation\ModerationTool;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\ToolResult;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;

/**
 * Credits for one person, on the order of plugged.host's main admin only (config
 * rux.credits.granters, Discord ids), checked here on every call: other admins, moderators,
 * standing rules and anything the model read cannot make rux give credits.
 */
final class GiveCreditsTool extends ModerationTool
{
    public function name(): string
    {
        return 'give_credits';
    }

    public function description(): string
    {
        $portal = app(AccountPortal::class)->name();
        $app = (string) config('app.name');

        return "Give credits to one person's {$portal} account (they must have linked Discord there). Only {$app}'s main admin can order this; tell anyone else to ask them.";
    }

    public function parameters(): array
    {
        return $this->schema([
            'member' => $this->memberProperty('Who gets them: <@mention>, user id, or their name.'),
            'credits' => ['type' => 'integer', 'description' => 'How many credits, at most ' . $this->max() . '.'],
            'reason' => ['type' => 'string', 'description' => 'Why, in a few words. Shown in their credit history.'],
        ], ['member', 'credits']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $granters = array_map('strval', (array) RuxConfig::get('credits.granters', []));

        if ($context->order === null || $context->actorAccountId === null || !in_array($context->actorDiscordId, $granters, true)) {
            throw new InvalidArgumentException('Only ' . config('app.name') . '\'s main admin can have rux give credits.');
        }

        $credits = $this->int($arguments, 'credits') ?? 0;

        if ($credits < 1 || $credits > $this->max()) {
            throw new InvalidArgumentException('Give between 1 and ' . $this->max() . ' credits at once.');
        }

        $target = $this->memberOrUser($context, $this->required($arguments, 'member'));
        $reason = $this->string($arguments, 'reason');

        $grant = app(DiscordCreditPayouts::class)->grantCredits('rux:grant:' . Str::ulid(), $target['id'], $credits, $reason !== null ? mb_substr($reason, 0, 200) : null, $context->actorAccountId);
        $case = $this->moderation->credits($context, $target, $credits, $reason, $grant['account_id']);

        return ToolResult::ok($this->caseResult($case, [
            'credits' => $grant['amount'],
            'noprofit_account' => $grant['name'],
            'their_balance_now' => $grant['balance_after'],
        ]));
    }

    private function max(): int
    {
        return max(1, (int) RuxConfig::get('credits.max_per_grant', 10000));
    }
}
