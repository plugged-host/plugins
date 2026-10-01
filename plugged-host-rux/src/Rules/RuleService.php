<?php

namespace PluggedHost\Rux\Rules;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Rux\Agent\Gateway\GatewayException;
use PluggedHost\Rux\Agent\Gateway\LimitReachedException;
use PluggedHost\Rux\Agent\RuxGateway;
use PluggedHost\Rux\Discord\DiscordException;
use PluggedHost\Rux\Discord\DiscordRest;
use PluggedHost\Rux\Moderation\ModerationService;
use PluggedHost\Rux\Models\RuxRule;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxTime;
use PluggedHost\Rux\Tools\RuxContext;
use Throwable;

/**
 * Standing orders: "if anyone asks for new locations, give them a warn".
 *
 * Reading every message with the model would use up the AI's capacity within minutes, so this
 * works in two steps. The gateway daemon only compares each member message with the rules'
 * keywords (screen(), no network). A hit goes to a worker (check()), which skips staff, asks the
 * model whether the message really breaks the rule, and only then acts. The model's turn is
 * metered against the admin who made the rule. One rule acts on one member at most once per
 * cooldown, so a burst of messages or a wrong answer cannot pile up warnings.
 */
class RuleService
{
    /** @var list<RuxRule>|null */
    private ?array $rules = null;

    private float $rulesLoadedAt = 0.0;

    public function __construct(
        private readonly DiscordRest $discord,
        private readonly RuxGateway $gateway,
        private readonly ModerationService $moderation,
        private readonly RuxAccess $access,
    ) {}

    // --- in the daemon ------------------------------------------------------------------

    /**
     * Whether a member's message hits a rule's keywords. On a hit the message is parked in the
     * cache and its key returned, for the worker.
     *
     * @param  array<string, mixed>  $message
     */
    public function screen(array $message, string $botId): ?string
    {
        $author = (array) ($message['author'] ?? []);
        $content = (string) ($message['content'] ?? '');

        if ($content === '' || (string) ($message['guild_id'] ?? '') !== RuxConfig::guildId()
            || ($author['bot'] ?? false) || isset($message['webhook_id']) || (string) ($author['id'] ?? '') === $botId) {
            return null;
        }

        $text = RuxRule::normalize($content);
        $channelId = (string) ($message['channel_id'] ?? '');
        $hits = array_values(array_filter($this->activeRules(), static fn (RuxRule $rule): bool => $rule->appliesIn($channelId) && $rule->matches($text)));

        if ($hits === []) {
            return null;
        }

        $limiter = 'plugged-host-rux:rule-checks:' . $author['id'];
        if (RateLimiter::tooManyAttempts($limiter, (int) config('plugged-host-rux.rules.checks_per_member_per_minute', 3))) {
            return null;
        }
        RateLimiter::hit($limiter, 60);

        $key = (string) Str::uuid();
        Cache::put("plugged-host-rux:hit:{$key}", [
            'rules' => array_map(static fn (RuxRule $rule): int => $rule->id, $hits),
            'message_id' => (string) $message['id'],
            'channel_id' => $channelId,
            'author_id' => (string) $author['id'],
            'author_name' => (string) ($message['member']['nick'] ?? $author['global_name'] ?? $author['username'] ?? $author['id']),
            'roles' => array_values(array_map('strval', (array) ($message['member']['roles'] ?? []))),
            'content' => mb_substr($content, 0, 1500),
        ], 600);

        return $key;
    }

    /**
     * Active rules, reloaded every 30 seconds so new ones apply right away without a restart.
     *
     * @return list<RuxRule>
     */
    private function activeRules(): array
    {
        if ($this->rules === null || microtime(true) - $this->rulesLoadedAt > 30) {
            try {
                $this->rules = RuxRule::query()->where('active', true)->where('guild_id', RuxConfig::guildId())->get()->all();
            } catch (Throwable $e) {
                report($e);
                $this->rules ??= [];
            }

            $this->rulesLoadedAt = microtime(true);
        }

        return $this->rules;
    }

    // --- in the worker -------------------------------------------------------------------

    public function check(string $key): void
    {
        $hit = Cache::pull("plugged-host-rux:hit:{$key}");

        if (!is_array($hit) || $this->access->isStaff($hit['author_id'], $hit['roles'])) {
            return;
        }

        foreach ($hit['rules'] as $ruleId) {
            $rule = RuxRule::query()->where('active', true)->find($ruleId);

            if ($rule === null) {
                continue;
            }

            $rule->increment('hits');
            $cooldown = "plugged-host-rux:rule-cooldown:{$rule->id}:{$hit['author_id']}";

            if (Cache::has($cooldown)) {
                continue;
            }

            $verdict = $this->judge($rule, $hit);

            Log::info('[rux] rule check', ['rule' => $rule->id, 'member' => $hit['author_id'], 'message' => $hit['message_id'], 'verdict' => $verdict]);

            if ($verdict === null || !$verdict['breaks']) {
                continue;
            }

            if (!Cache::add($cooldown, true, max(30, (int) config('plugged-host-rux.rules.cooldown_seconds', 300)))) {
                continue;
            }

            try {
                $this->enforce($rule, $hit);
            } catch (DiscordException|InvalidArgumentException $e) {
                Log::warning('[rux] rule action failed', ['rule' => $rule->id, 'member' => $hit['author_id'], 'error' => $e->getMessage()]);
            }

            return;
        }
    }

    /**
     * The model's verdict, or null when it could not be asked.
     *
     * @param  array<string, mixed>  $hit
     * @return array{breaks: bool, why: string}|null
     */
    private function judge(RuxRule $rule, array $hit): ?array
    {
        $messages = [
            ['role' => 'system', 'content' => implode("\n", [
                'You check one message from the plugged.host Discord server (a free game server host) against one server rule, for the moderation bot rux.',
                'The rule is broken when a member\'s message: ' . $rule->description,
                'Members write casually: slang, typos, abbreviations, other languages. Judge what they mean, not the exact words.',
                'It is NOT broken by messages that only mention the topic in passing, answer someone else, quote the rule, joke about it without asking, or are about something else that shares a word.',
                'Answer with only this JSON and nothing else: {"breaks": true or false, "why": "a few words"}',
            ])],
            ['role' => 'user', 'content' => "Message by {$hit['author_name']}:\n<message untrusted=\"true\">\n{$hit['content']}\n</message>"],
        ];

        try {
            $response = $this->gateway->turn($rule->created_by_account_id, 'rux:rule:' . $rule->id, $messages, [], 'none');
        } catch (LimitReachedException|GatewayException $e) {
            Log::warning('[rux] rule check could not ask the model', ['rule' => $rule->id, 'error' => $e->getMessage()]);

            return null;
        }

        $text = (string) ($response['message']['content'] ?? '');

        if (preg_match('/\{.*\}/s', $text, $json) !== 1 || !is_array($verdict = json_decode($json[0], true)) || !isset($verdict['breaks'])) {
            return null;
        }

        return ['breaks' => $verdict['breaks'] === true || $verdict['breaks'] === 'true', 'why' => mb_substr((string) ($verdict['why'] ?? ''), 0, 200)];
    }

    /**
     * Act on a message that breaks a rule: what the rule says, plus a short notice in the channel.
     *
     * @param  array<string, mixed>  $hit  message_id, channel_id, author_id, author_name
     */
    public function enforce(RuxRule $rule, array $hit): void
    {
        $context = new RuxContext(
            guildId: RuxConfig::guildId(),
            actorDiscordId: $rule->created_by_discord_id,
            actorName: "rule #{$rule->id}",
            actorAccountId: $rule->created_by_account_id,
            channelId: $hit['channel_id'],
            direct: false,
            sourceMessageId: $hit['message_id'],
        );
        $target = ['id' => $hit['author_id'], 'name' => $hit['author_name']];
        $reason = $rule->reason ?: Str::ucfirst($rule->description);
        $deleted = false;

        if ($rule->delete_message || $rule->action === 'delete') {
            try {
                $this->moderation->deleteMessage($context, $hit['channel_id'], $hit['message_id'], $target, $reason, $rule->id, record: $rule->action === 'delete');
                $deleted = true;
            } catch (DiscordException $e) {
                Log::warning('[rux] could not delete a message a rule caught', ['rule' => $rule->id, 'error' => $e->getMessage()]);
            }
        }

        $notice = match ($rule->action) {
            'warn' => (function () use ($context, $target, $reason, $rule): string {
                $result = $this->moderation->warn($context, $target, $reason, ruleId: $rule->id);
                $line = RuxConfig::say('rule.warn', ['member' => "<@{$target['id']}>", 'reason' => $reason]);

                return $result['escalation'] === null ? $line : $line . ' ' . RuxConfig::say('notice.warnings_reached', ['count' => $result['warnings']]);
            })(),
            'timeout' => (function () use ($context, $target, $reason, $rule): string {
                $this->moderation->timeout($context, $target, min(ModerationService::MAX_TIMEOUT_SECONDS, $rule->duration_seconds ?: 3600), $reason, automatic: true, ruleId: $rule->id);

                return RuxConfig::say('rule.timeout', ['member' => "<@{$target['id']}>", 'reason' => $reason]);
            })(),
            'kick' => (function () use ($context, $target, $reason, $rule): string {
                $this->moderation->kick($context, $target, $reason, $rule->id);

                return RuxConfig::say('rule.acted', ['member' => $target['name'], 'reason' => RuxConfig::say('log.kick')]);
            })(),
            'ban' => (function () use ($context, $target, $reason, $rule): string {
                $this->moderation->ban($context, $target, $reason, 0, inServer: true, seconds: $rule->duration_seconds, automatic: true, ruleId: $rule->id);

                return RuxConfig::say('rule.acted', ['member' => $target['name'], 'reason' => RuxConfig::say('log.ban')]);
            })(),
            default => null,
        };

        $rule->forceFill(['actions' => $rule->actions + 1, 'last_action_at' => now()])->save();

        $this->notice($hit, $notice, $deleted);
    }

    /**
     * A message in a watched channel that breaks one of the always-on guidelines (scams, slurs,
     * harassment, sexual content, spam, advertising): it is deleted and its author warned.
     *
     * @param  array<string, mixed>  $hit
     */
    public function enforceGuideline(string $guideline, array $hit, int $accountId, string $actorDiscordId): void
    {
        $context = new RuxContext(
            guildId: RuxConfig::guildId(),
            actorDiscordId: $actorDiscordId,
            actorName: 'watched channel',
            actorAccountId: $accountId,
            channelId: $hit['channel_id'],
            direct: false,
            sourceMessageId: $hit['message_id'],
        );
        $target = ['id' => $hit['author_id'], 'name' => $hit['author_name']];
        $deleted = false;

        try {
            $this->moderation->deleteMessage($context, $hit['channel_id'], $hit['message_id'], $target, $guideline, record: false);
            $deleted = true;
        } catch (DiscordException $e) {
            Log::warning('[rux] could not delete a message that broke a guideline', ['message' => $hit['message_id'], 'error' => $e->getMessage()]);
        }

        $result = $this->moderation->warn($context, $target, $guideline, automatic: true);
        $line = RuxConfig::say('rule.warn', ['member' => "<@{$target['id']}>", 'reason' => $guideline]);

        $this->notice($hit, $result['escalation'] === null ? $line : $line . ' ' . RuxConfig::say('notice.warnings_reached', ['count' => $result['warnings']]), $deleted);
    }

    /**
     * Said in the channel too, so the member sees it even with DMs closed and others learn the rule.
     *
     * @param  array<string, mixed>  $hit
     */
    private function notice(array $hit, ?string $notice, bool $deleted): void
    {
        if ($notice === null) {
            return;
        }

        $payload = ['content' => $notice, 'allowed_mentions' => ['parse' => [], 'users' => [$hit['author_id']]]];
        if (!$deleted) {
            $payload['message_reference'] = ['message_id' => $hit['message_id'], 'fail_if_not_exists' => false];
        }

        try {
            $this->discord->createMessage($hit['channel_id'], $payload);
        } catch (DiscordException $e) {
            Log::warning('[rux] could not post a moderation notice', ['message' => $hit['message_id'], 'error' => $e->getMessage()]);
        }
    }

    /**
     * What the rules are, for the model.
     *
     * @return array<string, mixed>
     */
    public static function summary(RuxRule $rule): array
    {
        return array_filter([
            'id' => $rule->id,
            'when' => $rule->description,
            'action' => $rule->action . ($rule->duration_seconds ? ' for ' . RuxTime::humanDuration($rule->duration_seconds) : ''),
            'deletes_message' => $rule->delete_message ?: null,
            'reason' => $rule->reason,
            'only_in' => $rule->channel_ids ? array_map(static fn (string $id): string => "<#{$id}>", $rule->channel_ids) : null,
            'keywords' => $rule->keywords,
            'active' => $rule->active,
            'messages_checked' => $rule->hits,
            'times_acted' => $rule->actions,
        ], static fn ($value): bool => $value !== null);
    }
}
