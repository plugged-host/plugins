<?php

namespace PluggedHost\Rux\Moderation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PluggedHost\Rux\Discord\DiscordException;
use PluggedHost\Rux\Discord\DiscordRest;
use PluggedHost\Rux\Models\RuxCase;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxTime;
use PluggedHost\Rux\Tools\RuxContext;

/**
 * The moderation rux does on an admin's order. Every action is a RuxCase (who ordered it, on
 * whom, why), goes to the mod log channel when one is set, and carries the order in Discord's
 * audit log. The member is told by DM before a kick or ban, while rux can still reach them.
 */
class ModerationService
{
    public const MAX_TIMEOUT_SECONDS = 2419200;

    private const SEND_MESSAGES = 1 << 11;

    private const SEND_IN_THREADS = 1 << 38;

    private const CREATE_THREADS = (1 << 35) | (1 << 36);

    public function __construct(private readonly DiscordRest $discord) {}

    /**
     * A warning, then the step it reaches on the ladder (config warnings): with the defaults the
     * 3rd and 4th warning also time the member out for 24 hours, the 5th and every later one
     * bans them for 24 hours.
     *
     * @param  array{id: string, name: string}  $target
     * @return array{case: RuxCase, warnings: int, escalation: ?RuxCase}
     */
    public function warn(RuxContext $context, array $target, ?string $reason, ?int $ruleId = null, bool $automatic = false): array
    {
        $told = $this->notify($target['id'], 'notice.warn', $reason);
        $case = $this->record($context, 'warn', $target, $reason, meta: ['dm_delivered' => $told], automatic: $automatic || $ruleId !== null, ruleId: $ruleId);
        $warnings = $this->warningCount($target['id']);
        $escalation = null;

        $ladder = (array) config('plugged-host-rux.warnings', []);
        $banAt = (int) ($ladder['ban_at'] ?? 0);
        $timeoutAt = (int) ($ladder['timeout_at'] ?? 0);
        $why = RuxConfig::say('notice.warnings_reached', ['count' => $warnings]);

        try {
            if ($banAt > 0 && $warnings >= $banAt) {
                $seconds = RuxTime::duration((string) ($ladder['ban'] ?? '24h'));
                $escalation = $this->ban($context, $target, $why, 0, inServer: true, seconds: $seconds > 0 ? $seconds : null, automatic: true);
            } elseif ($timeoutAt > 0 && $warnings >= $timeoutAt) {
                $seconds = min(self::MAX_TIMEOUT_SECONDS, RuxTime::duration((string) ($ladder['timeout'] ?? '24h')));
                $escalation = $this->timeout($context, $target, $seconds, $why, automatic: true);
            }
        } catch (DiscordException $e) {
            Log::warning('[rux] warning step failed', ['case' => $case->id, 'warnings' => $warnings, 'error' => $e->getMessage()]);
            $case->update(['meta' => array_merge((array) $case->meta, ['step_failed' => $e->getMessage()])]);
        }

        return ['case' => $case, 'warnings' => $warnings, 'escalation' => $escalation];
    }

    public function warningCount(string $discordId): int
    {
        return RuxCase::query()->where('target_id', $discordId)->where('action', 'warn')->whereNull('revoked_at')->count();
    }

    /**
     * Take back a member's latest warnings (all of them when $count is null).
     */
    public function clearWarnings(RuxContext $context, array $target, ?int $count, ?string $reason): int
    {
        $query = RuxCase::query()->where('target_id', $target['id'])->where('action', 'warn')->whereNull('revoked_at')->latest('id');

        if ($count !== null) {
            $query->limit(max(1, $count));
        }

        $ids = $query->pluck('id');
        RuxCase::query()->whereIn('id', $ids)->update(['revoked_at' => now()]);

        if ($ids->isNotEmpty()) {
            $this->record($context, 'clear_warnings', $target, $reason, meta: ['cleared' => $ids->count(), 'left' => $this->warningCount($target['id'])]);
        }

        return $ids->count();
    }

    /**
     * @param  array{id: string, name: string}  $target
     */
    public function timeout(RuxContext $context, array $target, int $seconds, ?string $reason, bool $automatic = false, ?int $ruleId = null): RuxCase
    {
        if ($seconds < 1 || $seconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('A timeout lasts from 1 second to 28 days.');
        }

        $until = CarbonImmutable::now()->addSeconds($seconds);
        $this->discord->modifyMember($context->guildId, $target['id'], ['communication_disabled_until' => $until->toIso8601String()], $context->auditReason($reason));
        $told = $this->notify($target['id'], 'notice.timeout', $reason, ['until' => RuxTime::discord($until, 'f')]);

        return $this->record($context, 'timeout', $target, $reason, $seconds, ['until' => $until->toIso8601String(), 'dm_delivered' => $told], automatic: $automatic, ruleId: $ruleId);
    }

    /**
     * @param  array{id: string, name: string}  $target
     */
    public function untimeout(RuxContext $context, array $target, ?string $reason): RuxCase
    {
        $this->discord->modifyMember($context->guildId, $target['id'], ['communication_disabled_until' => null], $context->auditReason($reason));

        return $this->record($context, 'untimeout', $target, $reason);
    }

    /**
     * @param  array{id: string, name: string}  $target
     */
    public function kick(RuxContext $context, array $target, ?string $reason, ?int $ruleId = null): RuxCase
    {
        $told = $this->notify($target['id'], 'notice.kick', $reason);
        $this->discord->kick($context->guildId, $target['id'], $context->auditReason($reason));

        return $this->record($context, 'kick', $target, $reason, meta: ['dm_delivered' => $told], automatic: $ruleId !== null, ruleId: $ruleId);
    }

    /**
     * A ban, for good or for $seconds (rux lifts it then, see liftExpiredBans). A new ban
     * replaces a running one, so an earlier ban for a while cannot lift this one.
     *
     * @param  array{id: string, name: string}  $target
     */
    public function ban(RuxContext $context, array $target, ?string $reason, int $deleteMessageHours, bool $inServer, ?int $seconds = null, bool $automatic = false, ?int $ruleId = null): RuxCase
    {
        $deleteSeconds = max(0, min(604800, $deleteMessageHours * 3600));
        $until = $seconds !== null ? CarbonImmutable::now()->addSeconds($seconds) : null;

        $told = $inServer ? $this->notify($target['id'], $until !== null ? 'notice.ban_for' : 'notice.ban', $reason, $until !== null ? ['until' => RuxTime::discord($until, 'f')] : []) : false;
        $this->discord->ban($context->guildId, $target['id'], $deleteSeconds, $context->auditReason($reason));

        $this->resolveOpenBans($target['id']);

        return $this->record($context, 'ban', $target, $reason, $seconds, [
            'deleted_message_hours' => intdiv($deleteSeconds, 3600),
            'dm_delivered' => $told,
        ], automatic: $automatic, ruleId: $ruleId, expiresAt: $until);
    }

    /**
     * @param  array{id: string, name: string}  $target
     */
    public function unban(RuxContext $context, array $target, ?string $reason): RuxCase
    {
        $this->discord->unban($context->guildId, $target['id'], $context->auditReason($reason));
        $this->resolveOpenBans($target['id']);

        return $this->record($context, 'unban', $target, $reason);
    }

    /**
     * Delete the last $count messages in a channel (only those from $from when given), skipping
     * the order itself. Discord cannot bulk delete messages older than 14 days, so the search
     * stops there; at most 500 messages are looked at.
     *
     * @param  array<string, mixed>  $channel
     * @param  array{id: string, name: string}|null  $from
     * @return array{deleted: int, case: RuxCase}
     */
    public function purge(RuxContext $context, array $channel, int $count, ?array $from, ?string $reason): array
    {
        $count = max(1, min(200, $count));
        $oldest = CarbonImmutable::now()->subDays(14)->addMinutes(5);
        $picked = [];
        $before = null;
        $scanned = 0;

        while (count($picked) < $count && $scanned < 500) {
            $page = $this->discord->messages((string) $channel['id'], array_filter(['limit' => 100, 'before' => $before]));

            if ($page === []) {
                break;
            }

            foreach ($page as $message) {
                $scanned++;
                $before = (string) $message['id'];

                if (CarbonImmutable::parse((string) $message['timestamp'])->lessThan($oldest)) {
                    break 2;
                }

                if ((string) $message['id'] === $context->sourceMessageId || (bool) ($message['pinned'] ?? false)) {
                    continue;
                }

                if ($from !== null && (string) ($message['author']['id'] ?? '') !== $from['id']) {
                    continue;
                }

                $picked[] = (string) $message['id'];

                if (count($picked) >= $count) {
                    break 2;
                }
            }

            if (count($page) < 100) {
                break;
            }
        }

        foreach (array_chunk($picked, 100) as $chunk) {
            $this->discord->deleteMessages((string) $channel['id'], $chunk, $context->auditReason($reason));
        }

        $case = $this->record($context, 'purge', $from, $reason, meta: [
            'channel' => (string) $channel['id'],
            'asked' => $count,
            'deleted' => count($picked),
        ], channelId: (string) $channel['id']);

        return ['deleted' => count($picked), 'case' => $case];
    }

    /**
     * Delete one message a standing rule caught.
     *
     * @param  array{id: string, name: string}  $author
     */
    public function deleteMessage(RuxContext $context, string $channelId, string $messageId, array $author, ?string $reason, ?int $ruleId = null, bool $record = true): ?RuxCase
    {
        $this->discord->deleteMessages($channelId, [$messageId], $context->auditReason($reason));

        return $record
            ? $this->record($context, 'delete', $author, $reason, meta: ['message' => $messageId], channelId: $channelId, automatic: $ruleId !== null, ruleId: $ruleId)
            : null;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    public function slowmode(RuxContext $context, array $channel, int $seconds, ?string $reason): RuxCase
    {
        $seconds = max(0, min(21600, $seconds));
        $this->discord->modifyChannel((string) $channel['id'], ['rate_limit_per_user' => $seconds], $context->auditReason($reason));

        return $this->record($context, 'slowmode', null, $reason, $seconds, ['channel' => (string) $channel['id']], (string) $channel['id']);
    }

    /**
     * Stop (or allow again) @everyone sending messages in a channel, keeping every other part of
     * its @everyone overwrite as it was.
     *
     * @param  array<string, mixed>  $channel
     */
    public function lock(RuxContext $context, array $channel, bool $locked, ?string $reason): RuxCase
    {
        $fresh = $this->discord->channel((string) $channel['id']);
        $everyone = $context->guildId;
        $allow = 0;
        $deny = 0;

        foreach ((array) ($fresh['permission_overwrites'] ?? []) as $overwrite) {
            if ((string) ($overwrite['id'] ?? '') === $everyone) {
                $allow = (int) ($overwrite['allow'] ?? 0);
                $deny = (int) ($overwrite['deny'] ?? 0);
            }
        }

        $bits = self::SEND_MESSAGES | self::SEND_IN_THREADS | self::CREATE_THREADS;

        if ($locked) {
            $deny |= $bits;
            $allow &= ~$bits;
        } else {
            $deny &= ~$bits;
        }

        $this->discord->setPermissionOverwrite((string) $channel['id'], $everyone, 0, (string) $allow, (string) $deny, $context->auditReason($reason));

        return $this->record($context, $locked ? 'lock' : 'unlock', null, $reason, meta: ['channel' => (string) $channel['id']], channelId: (string) $channel['id']);
    }

    /**
     * @param  array{id: string, name: string}  $target
     * @param  array<string, mixed>  $role
     */
    public function role(RuxContext $context, array $target, array $role, bool $add, ?string $reason): RuxCase
    {
        if ((bool) ($role['managed'] ?? false) || (string) $role['id'] === $context->guildId) {
            throw new InvalidArgumentException("The role {$role['name']} is managed by Discord or an integration and cannot be given or taken by hand.");
        }

        $add
            ? $this->discord->addRole($context->guildId, $target['id'], (string) $role['id'], $context->auditReason($reason))
            : $this->discord->removeRole($context->guildId, $target['id'], (string) $role['id'], $context->auditReason($reason));

        return $this->record($context, $add ? 'role_add' : 'role_remove', $target, $reason, meta: ['role' => (string) $role['id'], 'role_name' => (string) $role['name']]);
    }

    /**
     * Lift the bans for a while whose time is up. One that was lifted by hand in Discord is
     * just marked done.
     */
    public function liftExpiredBans(): int
    {
        $lifted = 0;

        RuxCase::query()
            ->where('action', 'ban')
            ->whereNotNull('expires_at')
            ->whereNull('resolved_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(function (RuxCase $ban) use (&$lifted): void {
                try {
                    $this->discord->unban($ban->guild_id, (string) $ban->target_id, 'rux: the ban from case #' . $ban->id . ' ran out');
                } catch (DiscordException $e) {
                    if ($e->discordCode !== 10026) {
                        Log::warning('[rux] could not lift a ban that ran out', ['case' => $ban->id, 'error' => $e->getMessage()]);

                        return;
                    }
                }

                $ban->update(['resolved_at' => now()]);
                $lifted++;

                $case = RuxCase::query()->create([
                    'guild_id' => $ban->guild_id,
                    'action' => 'unban',
                    'target_id' => $ban->target_id,
                    'target_name' => $ban->target_name,
                    'moderator_discord_id' => $ban->moderator_discord_id,
                    'moderator_account_id' => $ban->moderator_account_id,
                    'reason' => 'The ban from case #' . $ban->id . ' ran out.',
                    'automatic' => true,
                ]);

                $this->postToModLog($case);
            });

        return $lifted;
    }

    private function resolveOpenBans(string $discordId): void
    {
        RuxCase::query()->where('action', 'ban')->where('target_id', $discordId)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    /**
     * Put a change to rux itself (a rule, a watched channel, a moderator role) in the case log
     * and the mod log.
     *
     * @param  array<string, mixed>  $meta
     */
    public function note(RuxContext $context, string $action, ?string $reason, array $meta = []): RuxCase
    {
        return $this->record($context, $action, null, $reason, meta: $meta, channelId: isset($meta['channel']) ? (string) $meta['channel'] : null);
    }

    /**
     * @return Collection<int, RuxCase>
     */
    public function history(string $discordId, int $limit = 10): Collection
    {
        return RuxCase::query()->where('target_id', $discordId)->latest('id')->limit($limit)->get();
    }

    /**
     * @param  array{id: string, name: string}|null  $target
     * @param  array<string, mixed>  $meta
     */
    private function record(RuxContext $context, string $action, ?array $target, ?string $reason, ?int $seconds = null, array $meta = [], ?string $channelId = null, bool $automatic = false, ?int $ruleId = null, ?CarbonImmutable $expiresAt = null): RuxCase
    {
        $case = RuxCase::query()->create([
            'guild_id' => $context->guildId,
            'action' => $action,
            'target_id' => $target['id'] ?? null,
            'target_name' => isset($target['name']) ? mb_substr($target['name'], 0, 100) : null,
            'channel_id' => $channelId,
            'moderator_discord_id' => $context->actorDiscordId,
            'moderator_account_id' => $context->actorAccountId,
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
            'duration_seconds' => $seconds,
            'automatic' => $automatic,
            'rule_id' => $ruleId,
            'expires_at' => $expiresAt?->setTimezone((string) config('app.timezone')),
            'meta' => $meta ?: null,
        ]);

        Log::info('[rux] moderation', ['case' => $case->id, 'action' => $action, 'target' => $case->target_id, 'by' => $context->actorDiscordId]);

        $this->postToModLog($case);

        return $case;
    }

    private function postToModLog(RuxCase $case): void
    {
        $channel = trim((string) config('plugged-host-rux.mod_log_channel_id', ''));

        if ($channel === '') {
            return;
        }

        $fields = [];

        if ($case->target_id !== null) {
            $fields[] = ['name' => RuxConfig::say('log.member'), 'value' => "<@{$case->target_id}> ({$case->target_id})", 'inline' => true];
        }

        if ($case->channel_id !== null) {
            $fields[] = ['name' => RuxConfig::say('log.channel'), 'value' => "<#{$case->channel_id}>", 'inline' => true];
        }

        $fields[] = ['name' => RuxConfig::say('log.by'), 'value' => $case->automatic
            ? RuxConfig::say($case->rule_id !== null ? 'log.by_rule' : 'log.by_rux', ['rule' => $case->rule_id])
            : "<@{$case->moderator_discord_id}>", 'inline' => true];

        if ($case->duration_seconds !== null && in_array($case->action, ['timeout', 'slowmode', 'ban'], true)) {
            $fields[] = ['name' => RuxConfig::say('log.duration'), 'value' => $case->duration_seconds === 0 ? 'off' : RuxTime::humanDuration($case->duration_seconds), 'inline' => true];
        }

        $details = array_filter([
            isset($case->meta['deleted']) ? $case->meta['deleted'] . ' deleted' : null,
            isset($case->meta['role_name']) ? (string) $case->meta['role_name'] : null,
            isset($case->meta['cleared']) ? $case->meta['cleared'] . ' cleared, ' . $case->meta['left'] . ' left' : null,
            $case->action === 'warn' && $case->target_id !== null ? RuxConfig::say('log.warnings', ['count' => $this->warningCount($case->target_id)]) : null,
        ]);
        if ($details !== []) {
            $fields[] = ['name' => RuxConfig::say('log.details'), 'value' => implode(', ', $details), 'inline' => true];
        }

        if ($case->reason !== null) {
            $fields[] = ['name' => RuxConfig::say('log.reason'), 'value' => mb_substr($case->reason, 0, 1024), 'inline' => false];
        }

        try {
            $this->discord->createMessage($channel, [
                'embeds' => [[
                    'title' => RuxConfig::say('log.' . $case->action) . ($case->target_name !== null ? ': ' . $case->target_name : ''),
                    'fields' => $fields,
                    'footer' => ['text' => RuxConfig::say('log.case', ['id' => $case->id])],
                    'timestamp' => $case->created_at->toIso8601String(),
                ]],
                'allowed_mentions' => ['parse' => []],
            ]);
        } catch (DiscordException $e) {
            Log::warning('[rux] could not post to the mod log', ['case' => $case->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function notify(string $userId, string $key, ?string $reason, array $replace = []): bool
    {
        $reasonLine = $reason !== null && trim($reason) !== '' ? RuxConfig::say('notice.reason', ['reason' => trim($reason)]) : '';

        return $this->discord->sendDm($userId, [
            'content' => trim(RuxConfig::say($key, $replace + ['server' => $this->serverName(), 'reason' => $reasonLine])),
            'allowed_mentions' => ['parse' => []],
        ]);
    }

    private function serverName(): string
    {
        $guildId = RuxConfig::guildId();

        return (string) Cache::remember("plugged-host-rux:guild-name:{$guildId}", 3600, function () use ($guildId): string {
            try {
                return (string) ($this->discord->json('GET', "guilds/{$guildId}")['name'] ?? 'plugged.host');
            } catch (DiscordException) {
                return 'plugged.host';
            }
        });
    }
}
