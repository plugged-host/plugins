<?php

namespace PluggedHost\Ai\NeoBot;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Models\NeoBotMessage;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Services\Gateway\GatewayBusyException;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\NeoGateway;
use PluggedHost\Ai\Support\SecretRedactor;
use PluggedHost\Ai\Tools\NeoToolRegistry;
use PluggedHost\Ai\Tools\Tool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;
use PluggedHost\Noprofit\Support\NoprofitLink;
use Throwable;

/**
 * neo on Discord, in its own worker process (plugged-host:neo-bot-handle).
 *
 * It answers every message waiting in one conversation (one person in one channel or DM);
 * several sent in a row are answered together. noprofit.space first says who the Discord
 * account is and whether its limits allow another answer: a linked account uses its own
 * limits, an unlinked one a share of them. Then the usual loop: model turn (metered by
 * noprofit.space) → run its tool calls → feed the results back, until it answers in text.
 *
 * Account support only. The tools are read-only account lookups from neo's registry, offered
 * in DMs to a Discord account linked to a plugged.host user, and they only ever see that user.
 */
final class NeoBotAgent
{
    /** Read-only account lookups neo may use on Discord. Nothing here changes anything. */
    public const TOOLS = ['account_overview', 'list_servers', 'list_create_options'];

    public function __construct(
        private readonly NeoGateway $gateway,
        private readonly NeoToolRegistry $tools,
        private readonly NeoBotPrompt $prompt,
        private readonly NeoBotDiscord $discord,
    ) {}

    /**
     * Answer what is waiting in this conversation, until nothing is. The caller holds the
     * conversation's lock.
     *
     * @param  (Closure(NeoBotMessage, string): void)|null  $send  Where answers go; Discord when null.
     */
    public function handleConversation(string $conversation, ?Closure $send = null): void
    {
        while (($rows = $this->claim($conversation)) !== null) {
            $this->answer($rows, $send ?? fn (NeoBotMessage $last, string $text) => $this->reply($last, $text));
        }
    }

    /**
     * @return Collection<int, NeoBotMessage>|null
     */
    private function claim(string $conversation): ?Collection
    {
        $rows = NeoBotMessage::query()
            ->where('conversation', $conversation)
            ->where('role', NeoBotMessage::USER)
            ->where('status', NeoBotMessage::PENDING)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $turnId = (int) $rows->first()->id;
        NeoBotMessage::query()->whereKey($rows->modelKeys())->update(['status' => NeoBotMessage::RUNNING, 'turn_id' => $turnId]);

        return $rows->each(fn (NeoBotMessage $row) => $row->forceFill(['status' => NeoBotMessage::RUNNING, 'turn_id' => $turnId]));
    }

    /**
     * @param  Collection<int, NeoBotMessage>  $rows
     * @param  Closure(NeoBotMessage, string): void  $send
     */
    private function answer(Collection $rows, Closure $send): void
    {
        $first = $rows->first();
        $last = $rows->last();

        try {
            $who = $this->gateway->discordUsage($first->author_id);
        } catch (GatewayUnavailableException $e) {
            Log::warning('[neo-bot] cannot ask noprofit.space about a Discord account', ['message' => $first->id, 'error' => $e->getMessage()]);
            $this->finish($rows, NeoBotMessage::FAILED, ['error' => 'noprofit_unavailable']);
            $send($last, NeoBotConfig::say('unavailable'));

            return;
        }

        if (is_string($who['blocked'] ?? null)) {
            $this->finish($rows, NeoBotMessage::FAILED, ['error' => 'limited']);
            $send($last, $this->limitText((bool) ($who['linked'] ?? false), $who['limits'][$who['blocked']]['resets_at'] ?? null));

            return;
        }

        $panelUser = !empty($who['linked']) && (int) ($who['account_id'] ?? 0) > 0 ? NoprofitLink::userFor((int) $who['account_id']) : null;
        $available = $first->isDirect() && $panelUser !== null ? $this->availableTools() : [];

        $this->finish($rows, NeoBotMessage::RUNNING, ['linked' => (bool) ($who['linked'] ?? false), 'account_id' => $who['account_id'] ?? null, 'user_id' => $panelUser?->id]);

        Log::info('[neo-bot] answering', ['message' => $first->id, 'from' => $first->author_id, 'linked' => (bool) ($who['linked'] ?? false), 'direct' => $first->isDirect(), 'messages' => $rows->count()]);

        try {
            $text = $this->run($rows, $who, $panelUser, $available);
            $this->finish($rows, NeoBotMessage::DONE);
        } catch (LimitReachedException $e) {
            $this->finish($rows, NeoBotMessage::FAILED, ['error' => 'limited']);
            $text = $this->limitText((bool) ($who['linked'] ?? false), $e->retryAt);
        } catch (GatewayUnavailableException $e) {
            $this->finish($rows, NeoBotMessage::FAILED, ['error' => Str::limit($e->getMessage(), 120, '')]);
            $text = NeoBotConfig::say('unavailable');
        } catch (Throwable $e) {
            report($e);
            $this->finish($rows, NeoBotMessage::FAILED, ['error' => class_basename($e)]);
            $text = NeoBotConfig::say('failed');
        }

        $send($last, $text);
    }

    /**
     * @param  Collection<int, NeoBotMessage>  $rows
     * @param  array<string, mixed>  $who
     * @param  array<string, Tool>  $available
     *
     * @throws LimitReachedException|GatewayUnavailableException
     */
    private function run(Collection $rows, array $who, ?User $panelUser, array $available): string
    {
        $first = $rows->first();
        $definitions = $this->tools->definitions($available);
        $maxSteps = max(2, (int) config('plugged-host-ai.neo_bot.max_steps', 6));
        $busyUntil = time() + max(30, (int) config('plugged-host-ai.neo_bot.busy_wait_seconds', 120));
        $context = $panelUser !== null ? new ToolContext($panelUser, null, new NeoConversation()) : null;

        $messages = [
            ['role' => 'system', 'content' => $this->prompt->build($first->author_id, (string) ($first->author_name ?: $first->author_id), $who, $panelUser, $first->isDirect(), $available !== [])],
            ...$this->history($first, $definitions !== []),
            ['role' => 'user', 'content' => self::userContent($rows)],
        ];

        for ($step = 1; ; $step++) {
            $final = $step >= $maxSteps;
            $response = $this->callModel($first, $messages, $definitions, $final ? 'none' : 'auto', $busyUntil);

            $message = (array) ($response['message'] ?? []);
            $content = is_string($message['content'] ?? null) && trim($message['content']) !== '' ? trim($message['content']) : null;
            $calls = $final ? [] : array_values(array_filter((array) ($message['tool_calls'] ?? []), static fn ($call): bool => is_array($call) && isset($call['id'], $call['function']['name'])));

            $calls = array_map(static fn (array $call): array => [
                'id' => Str::limit((string) $call['id'], 64, ''),
                'type' => 'function',
                'function' => ['name' => (string) $call['function']['name'], 'arguments' => is_string($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : (string) json_encode($call['function']['arguments'] ?? (object) [])],
            ], $calls);

            $this->store($first, NeoBotMessage::ASSISTANT, [
                'content' => $content,
                'tool_calls' => $calls ?: null,
                'meta' => [
                    'provider' => $response['provider']['label'] ?? null,
                    'model' => $response['provider']['model'] ?? null,
                    'tokens' => (int) ($response['usage']['charged_tokens'] ?? 0),
                ],
            ]);

            $messages[] = array_filter(['role' => 'assistant', 'content' => $content, 'tool_calls' => $calls ?: null], static fn ($value): bool => $value !== null) + ['content' => null];

            if ($calls === []) {
                return $content ?? NeoBotConfig::say('empty');
            }

            foreach ($calls as $call) {
                $name = $call['function']['name'];
                $arguments = json_decode($call['function']['arguments'] ?: '{}', true);
                $result = is_array($arguments) ? $this->execute($available, $context, $name, $arguments) : ToolResult::error('The arguments were not valid JSON.');
                $output = Str::limit(SecretRedactor::redact($result->content), (int) config('plugged-host-ai.neo_bot.tool_output_chars', 6000), ' …(cut)');

                $this->store($first, NeoBotMessage::TOOL, [
                    'tool_call_id' => $call['id'],
                    'tool_name' => Str::limit($name, 64, ''),
                    'content' => $output,
                    'status' => $result->ok ? NeoBotMessage::DONE : NeoBotMessage::FAILED,
                ]);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => self::fence($name, $output),
                    'is_error' => !$result->ok,
                ];
            }
        }
    }

    /**
     * The account lookups neo offers on Discord, as they are registered (read-only only).
     *
     * @return array<string, Tool>
     */
    private function availableTools(): array
    {
        return array_filter(
            array_intersect_key($this->tools->all(), array_flip(self::TOOLS)),
            static fn (Tool $tool): bool => $tool->scope() !== Tool::SCOPE_SERVER,
        );
    }

    /**
     * @param  array<string, Tool>  $available
     * @param  array<string, mixed>  $arguments
     */
    private function execute(array $available, ?ToolContext $context, string $name, array $arguments): ToolResult
    {
        $tool = $available[$name] ?? null;

        if ($tool === null || $context === null) {
            return ToolResult::error("{$name} is not available here. On Discord neo can only look up the person's own account, in a DM.");
        }

        try {
            if ($tool->risk($arguments) !== ToolRisk::Read) {
                return ToolResult::error("{$name} would change something, and neo on Discord changes nothing.");
            }

            return $tool->execute($context, $arguments);
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ToolResult::error('The lookup failed (' . class_basename($e) . '). Tell them it did not work and to try again later.');
        }
    }

    /**
     * One model turn through noprofit.space, waiting out a busy gateway until $busyUntil.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|GatewayUnavailableException
     */
    private function callModel(NeoBotMessage $first, array $messages, array $definitions, string $toolChoice, int $busyUntil): array
    {
        while (true) {
            $this->typing($first);

            try {
                return $this->gateway->discordTurn($first->author_id, $first->conversation, $messages, $definitions, $toolChoice);
            } catch (GatewayBusyException $e) {
                if (time() + 1 >= $busyUntil) {
                    throw new GatewayUnavailableException('busy_too_long');
                }

                sleep(max(1, min($e->retryAfter, 8, $busyUntil - time())));
            }
        }
    }

    /**
     * Earlier answers in this conversation from the last hour, oldest first. Only answers that
     * finished normally are replayed, so every tool call in them has its result. Without tools
     * this time (some providers refuse tool calls in a request that defines no tools), only
     * what was said is replayed.
     *
     * @return list<array<string, mixed>>
     */
    private function history(NeoBotMessage $first, bool $withTools): array
    {
        $turnIds = NeoBotMessage::query()
            ->where('conversation', $first->conversation)
            ->where('role', NeoBotMessage::USER)
            ->where('status', NeoBotMessage::DONE)
            ->whereColumn('id', 'turn_id')
            ->where('id', '<', $first->id)
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) config('plugged-host-ai.neo_bot.history_minutes', 60))))
            ->latest('id')
            ->limit(max(1, (int) config('plugged-host-ai.neo_bot.history_turns', 6)))
            ->pluck('id')
            ->reverse();

        $history = [];

        foreach ($turnIds as $turnId) {
            $rows = NeoBotMessage::query()->where('turn_id', $turnId)->orderBy('id')->get();
            $history[] = ['role' => 'user', 'content' => self::userContent($rows->where('role', NeoBotMessage::USER)->values())];

            foreach ($rows->where('role', '!=', NeoBotMessage::USER) as $row) {
                if ($row->role === NeoBotMessage::TOOL) {
                    if ($withTools) {
                        $history[] = [
                            'role' => 'tool',
                            'tool_call_id' => (string) $row->tool_call_id,
                            'content' => self::fence((string) $row->tool_name, Str::limit((string) $row->content, 600, ' …(cut)')),
                            'is_error' => $row->status === NeoBotMessage::FAILED,
                        ];
                    }

                    continue;
                }

                $calls = $withTools && $row->tool_calls ? ['tool_calls' => $row->tool_calls] : [];

                if ($row->content !== null || $calls !== []) {
                    $history[] = ['role' => 'assistant', 'content' => $row->content] + $calls;
                }
            }
        }

        return $history;
    }

    /**
     * The messages as the model reads them: the text, then what Discord resolved around it (who
     * was mentioned, which message it replies to).
     *
     * @param  Collection<int, NeoBotMessage>  $rows
     */
    private static function userContent(Collection $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = (string) $row->content;
            $meta = (array) $row->meta;

            foreach ((array) ($meta['mentions'] ?? []) as $mention) {
                if (is_array($mention) && isset($mention['id'])) {
                    $lines[] = "(<@{$mention['id']}> is " . self::untrusted(str_replace("\n", ' ', (string) ($mention['name'] ?? 'unknown'))) . ($mention['bot'] ?? false ? ', a bot' : '') . ')';
                }
            }

            // Someone else's words, so they go in a fence as data.
            if (is_array($meta['reply_to'] ?? null)) {
                $reply = $meta['reply_to'];
                $lines[] = '(They are replying to a message by <@' . ($reply['author_id'] ?? '?') . '>, shown below.)';
                $lines[] = '<replied_message untrusted="true">' . "\n"
                    . 'Author name: ' . self::untrusted(str_replace("\n", ' ', (string) ($reply['author_name'] ?? 'someone'))) . "\n"
                    . self::untrusted(Str::limit((string) ($reply['content'] ?? ''), 500)) . "\n"
                    . '</replied_message>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Someone else's text: angle brackets become look-alikes so it cannot close its fence.
     */
    private static function untrusted(string $text): string
    {
        return str_replace(['<', '>'], ['‹', '›'], $text);
    }

    private static function fence(string $tool, string $content): string
    {
        return '<tool_output tool="' . $tool . '" untrusted="true">' . "\n" . str_replace(['<tool_output', '</tool_output'], ['‹tool_output', '‹/tool_output'], $content) . "\n</tool_output>";
    }

    private function limitText(bool $linked, ?string $resetsAt): string
    {
        $when = '?';

        try {
            $when = $resetsAt !== null ? '<t:' . CarbonImmutable::parse($resetsAt)->getTimestamp() . ':R>' : $when;
        } catch (Throwable) {
            //
        }

        return $linked
            ? NeoBotConfig::say('limited', ['time' => $when])
            : NeoBotConfig::say('limited_unlinked', ['time' => $when, 'link' => NeoBotConfig::linkUrl()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(NeoBotMessage $first, string $role, array $attributes): void
    {
        NeoBotMessage::query()->create($attributes + [
            'conversation' => $first->conversation,
            'turn_id' => $first->id,
            'channel_id' => $first->channel_id,
            'guild_id' => $first->guild_id,
            'author_id' => $first->author_id,
            'role' => $role,
            'status' => NeoBotMessage::DONE,
        ]);
    }

    /**
     * @param  Collection<int, NeoBotMessage>  $rows
     * @param  array<string, mixed>  $meta
     */
    private function finish(Collection $rows, string $status, array $meta = []): void
    {
        foreach ($rows as $row) {
            $row->update(['status' => $status, 'meta' => $meta === [] ? $row->meta : array_merge((array) $row->meta, $meta)]);
        }
    }

    private function typing(NeoBotMessage $first): void
    {
        if ($first->channel_id !== 'cli') {
            $this->discord->typing($first->channel_id);
        }
    }

    /**
     * Answer in the message's channel, as a reply to the last message, split at Discord's 2000
     * characters. Never pings anyone: the model's text is not trusted with mentions.
     */
    private function reply(NeoBotMessage $last, string $text): void
    {
        foreach (self::split(trim($text) !== '' ? $text : NeoBotConfig::say('empty')) as $index => $chunk) {
            $payload = ['content' => $chunk, 'allowed_mentions' => ['parse' => [], 'replied_user' => false]];

            if ($index === 0 && $last->discord_message_id !== null) {
                $payload['message_reference'] = ['message_id' => $last->discord_message_id, 'fail_if_not_exists' => false];
            }

            try {
                $this->discord->createMessage($last->channel_id, $payload);
            } catch (DiscordException $e) {
                Log::warning('[neo-bot] could not reply', ['message' => $last->id, 'error' => $e->getMessage()]);

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        $chunks = [];

        while (mb_strlen($text) > 1900) {
            $cut = mb_strrpos(mb_substr($text, 0, 1900), "\n") ?: 1900;
            $chunks[] = mb_substr($text, 0, $cut);
            $text = ltrim(mb_substr($text, $cut));
        }

        $chunks[] = $text;

        return $chunks;
    }
}
