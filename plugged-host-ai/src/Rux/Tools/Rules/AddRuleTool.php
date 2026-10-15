<?php

namespace PluggedHost\Ai\Rux\Tools\Rules;

use InvalidArgumentException;
use PluggedHost\Ai\Rux\Discord\Resolver;
use PluggedHost\Ai\Rux\Moderation\ModerationService;
use PluggedHost\Ai\Rux\Models\RuxRule;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxTime;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Ai\Rux\Tools\ToolResult;

final class AddRuleTool extends RuxTool
{
    /** Single words too common to be keywords. */
    private const COMMON_WORDS = [
        'a', 'about', 'add', 'added', 'adding', 'all', 'an', 'and', 'any', 'anyone', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'coming', 'do',
        'does', 'for', 'from', 'get', 'go', 'got', 'have', 'help', 'hey', 'how', 'i', 'if', 'in', 'is', 'it', 'just', 'like', 'list', 'make', 'me',
        'more', 'my', 'need', 'new', 'no', 'not', 'now', 'of', 'ok', 'on', 'one', 'or', 'place', 'places', 'please', 'pls', 'plz', 'r', 'server',
        'servers', 'so', 'soon', 'that', 'the', 'there', 'they', 'this', 'to', 'u', 'up', 'we', 'what', 'when', 'where', 'which', 'who', 'why',
        'will', 'with', 'would', 'yes', 'you', 'your', 'have', 'there', 'is there', 'we have', 'want', 'wanna', 'gonna', 'lol', 'yo', 'ya', 'ur',
    ];

    public function __construct(
        private readonly Resolver $resolver,
        private readonly ModerationService $moderation,
    ) {}

    public function name(): string
    {
        return 'add_rule';
    }

    public function description(): string
    {
        return 'Make a standing rule rux enforces by itself from now on, e.g. "if anyone asks for new locations, warn them". Every member message containing one of the keywords (and every message in watched channels) is checked by AI against `when` and `not_when` before rux acts; that AI sees only the rule and the message, never this conversation. Staff are never affected.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'when' => ['type' => 'string', 'description' => 'What breaks the rule: the message a member writes, completing "a member\'s message that …", clear to someone who never saw this conversation, with what the admin meant (not their exact words). E.g. "asks staff to add or announce a server location plugged.host does not have yet (a new country, city or region)".'],
            'not_when' => ['type' => 'string', 'description' => 'What looks close but must be left alone: every exception the admin gave, plus the obvious near misses. E.g. "asking about, for or how to get a location plugged.host already has; asking which location to pick; talking about locations without asking for a new one". The check already knows plugged.host\'s own links and current locations.'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '10 to 40 lowercase topic words or short phrases such a message would very likely contain: synonyms, slang, typos, plurals, place names, common non-English words. Every hit costs an AI check, so no everyday words (add, new, when, where, server, pls, coming, any): they match almost every message and are dropped.'],
            'action' => ['type' => 'string', 'enum' => RuxRule::ACTIONS, 'description' => 'What rux does: warn (counts towards the automatic timeout and ban), timeout, delete (the message only), kick or ban.'],
            'duration' => ['type' => 'string', 'description' => 'For timeout or ban: how long, like "1h" or "24h". A ban without one is permanent.'],
            'delete_message' => ['type' => 'boolean', 'description' => 'Also delete the message. Default false (true for action delete).'],
            'reason' => ['type' => 'string', 'description' => 'What the member is told, short and friendly, e.g. "Please don\'t ask for new locations, we announce them when they come."'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only in these channels (#name or id). Leave out for the whole server.'],
        ], ['when', 'keywords', 'action']);
    }

    /**
     * A rule's keywords as rux keeps them: normalized and unique, without everyday single words
     * (they would send almost every message to the AI). At least 3 must be left.
     *
     * @param  array<mixed>  $raw
     * @return array{keywords: list<string>, dropped: list<string>}
     */
    public static function keywords(array $raw): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn ($k): string => mb_substr(RuxRule::normalize((string) $k), 0, 60),
            $raw,
        ), static fn (string $k): bool => mb_strlen($k) >= 2)));

        $dropped = array_values(array_filter($keywords, static fn (string $k): bool => !str_contains($k, ' ') && in_array($k, self::COMMON_WORDS, true)));
        $keywords = array_values(array_diff($keywords, $dropped));

        if (count($keywords) < 3) {
            throw new InvalidArgumentException('Give at least 3 specific keywords (topic words or phrases, not everyday words like ' . implode(', ', array_slice($dropped ?: ['new', 'add', 'when'], 0, 5)) . ').');
        }

        return ['keywords' => array_slice($keywords, 0, 80), 'dropped' => $dropped];
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $action = strtolower($this->required($arguments, 'action'));
        if (!in_array($action, RuxRule::ACTIONS, true)) {
            throw new InvalidArgumentException('action must be one of ' . implode(', ', RuxRule::ACTIONS) . '.');
        }

        ['keywords' => $keywords, 'dropped' => $dropped] = self::keywords((array) ($arguments['keywords'] ?? []));

        $duration = $this->string($arguments, 'duration');
        $seconds = $duration !== null ? RuxTime::duration($duration) : null;
        if ($action === 'timeout') {
            $seconds = max(60, min(ModerationService::MAX_TIMEOUT_SECONDS, $seconds ?? 3600));
        }

        $channels = [];
        foreach ((array) ($arguments['channels'] ?? []) as $channel) {
            $channels[] = (string) $this->resolver->channel($context->guildId, (string) $channel, [0, 5, 15])['id'];
        }

        $rule = RuxRule::query()->create([
            'guild_id' => $context->guildId,
            'description' => mb_substr($this->required($arguments, 'when'), 0, 600),
            'exceptions' => $this->string($arguments, 'not_when') !== null ? mb_substr($this->string($arguments, 'not_when'), 0, 600) : null,
            'keywords' => $keywords,
            'action' => $action,
            'duration_seconds' => in_array($action, ['timeout', 'ban'], true) ? $seconds : null,
            'delete_message' => $action === 'delete' || $this->bool($arguments, 'delete_message'),
            'reason' => $this->string($arguments, 'reason') !== null ? mb_substr($this->string($arguments, 'reason'), 0, 300) : null,
            'channel_ids' => $channels ?: null,
            'active' => true,
            'created_by_discord_id' => $context->actorDiscordId,
            'created_by_account_id' => $context->actorAccountId,
        ]);

        $this->moderation->note($context, 'rule_add', $rule->description, ['rule' => $rule->id, 'action' => $action]);

        return ToolResult::ok(['created' => true] + RuleService::summary($rule) + ($dropped !== [] ? ['keywords_dropped_as_too_common' => $dropped] : []));
    }
}
