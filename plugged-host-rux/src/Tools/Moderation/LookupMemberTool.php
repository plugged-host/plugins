<?php

namespace PluggedHost\Rux\Tools\Moderation;

use Carbon\CarbonImmutable;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Rux\Models\RuxCase;
use PluggedHost\Rux\Noprofit\NoprofitUnavailable;
use PluggedHost\Rux\Noprofit\RuxNoprofit;
use PluggedHost\Rux\Models\RuxGiveawayEntry;
use PluggedHost\Rux\Support\RuxTime;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\ToolResult;
use Throwable;

final class LookupMemberTool extends ModerationTool
{
    public function name(): string
    {
        return 'lookup_member';
    }

    public function description(): string
    {
        return 'Who a member is: Discord profile, roles, join date, timeout, their noprofit.space and plugged.host accounts, giveaway wins and past moderation cases. Also finds members by name.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema(['member' => $this->memberProperty()], ['member']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $target = $this->resolver->member($context->guildId, $this->required($arguments, 'member'));
        $member = $target['member'];
        $roles = collect($this->discord->roles($context->guildId))->keyBy('id');
        $timeoutUntil = $member['communication_disabled_until'] ?? null;
        $createdAt = CarbonImmutable::createFromTimestampMs((int) (((int) $target['id'] >> 22) + 1420070400000));

        try {
            $noprofit = app(RuxNoprofit::class)->account($target['id'], 4);
            $noprofitInfo = $noprofit === null ? 'not linked (cannot enter giveaways)' : ['account_id' => $noprofit['account_id'], 'name' => $noprofit['name'], 'admin' => $noprofit['is_admin']];
        } catch (NoprofitUnavailable) {
            $noprofitInfo = 'unknown (noprofit.space did not answer)';
        }

        $link = DiscordLink::query()->where('discord_id', $target['id'])->whereNotNull('user_id')->with('user')->first();
        $account = null;
        if ($link?->user !== null) {
            $user = $link->user;
            $account = ['panel_username' => $user->username, 'servers' => $user->servers()->count()];

            try {
                $credits = class_exists(\PluggedHost\Economy\Models\UserCredits::class)
                    ? \PluggedHost\Economy\Models\UserCredits::query()->where('user_id', $user->id)->value('credits')
                    : null;
                $account['credits'] = $credits !== null ? (int) $credits : null;
            } catch (Throwable) {
            }
        }

        $cases = RuxCase::query()->where('target_id', $target['id'])->latest('id')->limit(8)->get();

        return ToolResult::ok(array_filter([
            'member' => "<@{$target['id']}>",
            'id' => $target['id'],
            'username' => $member['user']['username'] ?? null,
            'display_name' => $target['name'],
            'bot' => (bool) ($member['user']['bot'] ?? false) ?: null,
            'account_created' => RuxTime::human($createdAt),
            'joined_server' => isset($member['joined_at']) ? RuxTime::human(CarbonImmutable::parse($member['joined_at'])) : null,
            'roles' => collect((array) ($member['roles'] ?? []))->map(fn ($id): string => (string) ($roles[$id]['name'] ?? $id))->values()->all(),
            'timed_out_until' => $timeoutUntil !== null && CarbonImmutable::parse($timeoutUntil)->isFuture() ? RuxTime::human(CarbonImmutable::parse($timeoutUntil)) : null,
            'noprofit_account' => $noprofitInfo,
            'plugged_host_panel_account' => $account,
            'warnings' => $this->moderation->warningCount($target['id']),
            'rux_moderator' => $this->access->moderatorRole(array_values(array_map('strval', (array) ($member['roles'] ?? [])))) !== null ?: null,
            'giveaway_wins' => RuxGiveawayEntry::query()->where('discord_id', $target['id'])->whereNotNull('won_at')->count(),
            'cases' => $cases->map(fn (RuxCase $c): string => "#{$c->id} {$c->action}" . ($c->automatic ? ' (automatic)' : '') . ($c->revoked_at ? ' (cleared)' : '') . ' ' . RuxTime::human($c->created_at) . ($c->reason ? ": {$c->reason}" : ''))->all(),
        ], static fn ($value): bool => $value !== null));
    }
}
