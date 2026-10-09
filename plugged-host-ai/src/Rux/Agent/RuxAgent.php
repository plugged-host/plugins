<?php

namespace PluggedHost\Ai\Rux\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Rux\Agent\Ai\AiBusyException;
use PluggedHost\Ai\Rux\Agent\Ai\AiUnavailableException;
use PluggedHost\Ai\Rux\Agent\Ai\RuxAi;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxToolRegistry;
use PluggedHost\Ai\Rux\Tools\ToolResult;
use PluggedHost\Discord\Contracts\DirectoryUnavailable;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use Throwable;

/**
 * One order, start to finish, in its own worker process (plugged-host:rux-handle).
 *
 * First noprofit.space says who wrote it. A noprofit.space admin gets every tool; a member of a
 * rux moderator role gets the moderation tools; anyone else is ignored (and told so once an hour
 * in DMs) before the model is ever called. Then the usual loop: model turn → run its tool calls → feed the results back,
 * until it answers in text or runs out of steps. Everything is kept in rux_messages, so the next
 * order in the same conversation remembers this one.
 */
final class RuxAgent
{
    public function __construct(
        private readonly RuxAi $ai,
        private readonly RuxToolRegistry $tools,
        private readonly RuxPrompt $prompt,
        private readonly DiscordAccounts $accounts,
        private readonly RuxDiscord $discord,
        private readonly RuxAccess $access,
    ) {}

    public static function deniedKey(string $discordId): string
    {
        return 'plugged-host-ai:rux:denied:' . RuxAccess::generation() . ":{$discordId}";
    }

    /**
     * The last order in this conversation is still being carried out: say so and drop this one.
     */
    public function refuseBusy(RuxMessage $order): void
    {
        $claimed = RuxMessage::query()->whereKey($order->id)->where('status', RuxMessage::PENDING)->update(['status' => RuxMessage::FAILED]);

        if ($claimed === 0) {
            return;
        }

        $this->finish($order->refresh(), RuxMessage::FAILED, ['error' => 'busy']);
        $this->reply($order, RuxConfig::say('reply.too_fast'));
    }

    public function handle(RuxMessage $order): void
    {
        $claimed = RuxMessage::query()->whereKey($order->id)->where('status', RuxMessage::PENDING)->update(['status' => RuxMessage::RUNNING]);

        if ($claimed === 0) {
            return;
        }

        $order->refresh();

        try {
            $actor = $this->accounts->account($order->author_id);
        } catch (DirectoryUnavailable $e) {
            Log::warning('[rux] cannot check who sent an order', ['message' => $order->id, 'error' => $e->getMessage()]);
            $this->finish($order, RuxMessage::FAILED, ['error' => 'noprofit_unavailable']);
            $this->reply($order, RuxConfig::say('reply.noprofit_down'));

            return;
        }

        $level = $actor !== null && $actor['is_admin'] ? RuxAccess::ADMIN : null;
        $accountId = $actor['account_id'] ?? null;
        $name = $actor !== null && $actor['name'] !== '' ? $actor['name'] : (string) ($order->author_name ?? $order->author_id);

        if ($level === null) {
            // Not an admin: a member of a rux moderator role still may give moderation orders.
            try {
                $roles = $this->access->memberRoles($order->author_id);
                $moderatorRole = $roles === null ? null : $this->access->moderatorRole($roles);
            } catch (DiscordException) {
                $moderatorRole = null;
            }

            if ($moderatorRole !== null) {
                $level = RuxAccess::MODERATOR;
            }
        }

        if ($level === null) {
            $this->finish($order, RuxMessage::IGNORED, ['reason' => $actor === null ? 'not_linked' : 'not_admin']);

            // The daemon drops this person's next messages without starting a worker.
            Cache::put(self::deniedKey($order->author_id), true, 300);

            if ($order->isDirect() && Cache::add("plugged-host-ai:rux:told-not-admin:{$order->author_id}", true, 3600)) {
                $this->reply($order, RuxConfig::say('reply.not_admin'));
            }

            return;
        }

        $this->finish($order, RuxMessage::RUNNING, ['level' => $level, 'account_id' => $accountId]);

        $context = new RuxContext(
            guildId: RuxConfig::guildId(),
            actorDiscordId: $order->author_id,
            actorName: $name,
            actorAccountId: $accountId,
            channelId: $order->channel_id,
            direct: $order->isDirect(),
            sourceMessageId: $order->discord_message_id,
            order: $order,
            level: $level,
        );

        Log::info('[rux] order', ['message' => $order->id, 'from' => $order->author_id, 'level' => $level, 'account' => $accountId, 'direct' => $order->isDirect()]);

        try {
            $answer = $this->run($order, $context);
            $this->finish($order, RuxMessage::DONE);
        } catch (AiUnavailableException $e) {
            $this->finish($order, RuxMessage::FAILED, ['error' => 'ai_' . mb_substr($e->getMessage(), 0, 120)]);
            $answer = $this->whatWasDone($order) ?? RuxConfig::say('reply.unavailable');
        } catch (Throwable $e) {
            report($e);
            $this->finish($order, RuxMessage::FAILED, ['error' => class_basename($e)]);
            $answer = RuxConfig::say('reply.failed');
        }

        $this->reply($order, $answer);
    }

    /**
     * @throws AiUnavailableException
     */
    private function run(RuxMessage $order, RuxContext $context): string
    {
        $messages = $this->messages($order, $context);
        $definitions = $this->tools->definitions($context->level);
        $maxSteps = max(2, (int) config('plugged-host-ai.rux.agent.max_steps', 8));
        $busyUntil = time() + max(30, (int) config('plugged-host-ai.rux.agent.busy_wait_seconds', 150));

        for ($step = 1; ; $step++) {
            $final = $step >= $maxSteps;
            $response = $this->callModel($order, $context, $messages, $definitions, $final ? 'none' : 'auto', $busyUntil);

            $message = (array) ($response['message'] ?? []);
            $content = is_string($message['content'] ?? null) && trim($message['content']) !== '' ? trim($message['content']) : null;
            $calls = $final ? [] : array_values(array_filter((array) ($message['tool_calls'] ?? []), static fn ($call): bool => is_array($call) && isset($call['id'], $call['function']['name'])));

            $calls = array_map(static fn (array $call): array => [
                'id' => Str::limit((string) $call['id'], 64, ''),
                'type' => 'function',
                'function' => ['name' => (string) $call['function']['name'], 'arguments' => is_string($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : (string) json_encode($call['function']['arguments'] ?? (object) [])],
            ], $calls);

            $this->store($order, RuxMessage::ASSISTANT, [
                'content' => $content,
                'tool_calls' => $calls ?: null,
                'meta' => [
                    'provider' => $response['provider']['label'] ?? null,
                    'model' => $response['provider']['model'] ?? null,
                    'tokens' => (int) ($response['usage']['prompt_tokens'] ?? 0) + (int) ($response['usage']['completion_tokens'] ?? 0),
                ],
            ]);

            $messages[] = array_filter(['role' => 'assistant', 'content' => $content, 'tool_calls' => $calls ?: null], static fn ($value): bool => $value !== null) + ['content' => null];

            if ($calls === []) {
                return $content ?? RuxConfig::say('reply.done');
            }

            foreach ($calls as $call) {
                $name = $call['function']['name'];
                $arguments = json_decode($call['function']['arguments'] ?: '{}', true);
                $started = microtime(true);
                $result = is_array($arguments) ? $this->execute($context, $name, $arguments) : ToolResult::error('The arguments were not valid JSON.');
                $output = Str::limit($result->content, (int) config('plugged-host-ai.rux.agent.tool_output_chars', 6000), ' …(cut)');

                Log::info('[rux] tool', ['message' => $order->id, 'tool' => $name, 'ok' => $result->ok, 'ms' => (int) ((microtime(true) - $started) * 1000)]);

                $this->store($order, RuxMessage::TOOL, [
                    'tool_call_id' => $call['id'],
                    'tool_name' => Str::limit($name, 64, ''),
                    'content' => $output,
                    'status' => $result->ok ? RuxMessage::DONE : RuxMessage::FAILED,
                    'meta' => ['arguments' => is_array($arguments) ? $arguments : null],
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
     * When the AI gave out after some tools already ran, what they did, so nobody is told
     * "nothing happened" about a giveaway that was in fact made.
     */
    private function whatWasDone(RuxMessage $order): ?string
    {
        $tools = RuxMessage::query()->where('turn_id', $order->id)->where('role', RuxMessage::TOOL)->orderBy('id')->get();

        if ($tools->isEmpty()) {
            return null;
        }

        $lines = $tools->map(function (RuxMessage $row): string {
            $result = json_decode((string) $row->content, true);
            $detail = is_array($result)
                ? implode(', ', array_filter([
                    isset($result['id'], $result['prize']) ? "#{$result['id']} {$result['prize']}" : null,
                    $result['ends_at'] ?? null,
                    $result['channel'] ?? null,
                    $result['member'] ?? null,
                    $result['message'] ?? $result['link'] ?? null,
                ], static fn ($v): bool => is_string($v) && $v !== ''))
                : Str::limit((string) $row->content, 150);

            return '- ' . $row->tool_name . ($row->status === RuxMessage::FAILED ? ' (failed)' : '') . ($detail !== '' ? ': ' . $detail : '');
        });

        return RuxConfig::say('reply.done_without_summary') . "\n" . $lines->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function execute(RuxContext $context, string $name, array $arguments): ToolResult
    {
        $tool = $this->tools->available($context->level)[$name] ?? null;

        if ($tool === null) {
            return ToolResult::error($this->tools->find($name) === null ? "There is no tool called {$name}." : "{$name} is for noprofit.space admins only; this person is a moderator.");
        }

        try {
            return $tool->execute($context, $arguments);
        } catch (InvalidArgumentException|DiscordException|DirectoryUnavailable $e) {
            return ToolResult::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ToolResult::error('rux failed while doing this (' . class_basename($e) . '). Tell the admin it did not work.');
        }
    }

    /**
     * One model turn, waiting out a busy or rate-limited AI until $busyUntil.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, mixed>
     *
     * @throws AiUnavailableException
     */
    private function callModel(RuxMessage $order, RuxContext $context, array $messages, array $definitions, string $toolChoice, int $busyUntil): array
    {
        while (true) {
            $this->discord->typing($order->channel_id);

            try {
                return $this->ai->turn($messages, $definitions, $toolChoice);
            } catch (AiBusyException $e) {
                if (time() + 1 >= $busyUntil) {
                    throw new AiUnavailableException('busy_too_long: ' . $e->getMessage());
                }

                sleep(max(1, min($e->retryAfter, 8, $busyUntil - time())));
            }
        }
    }

    /**
     * The system prompt, the last half hour of this conversation, and the new order.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(RuxMessage $order, RuxContext $context): array
    {
        $where = $order->isDirect()
            ? 'They are writing to you in a DM, so there is no "this channel": anything that acts on a channel needs one named.'
            : "They are talking to you in <#{$order->channel_id}> (\"this channel\").";

        $turns = RuxMessage::query()
            ->where('conversation', $order->conversation)
            ->where('role', RuxMessage::USER)
            ->where('status', RuxMessage::DONE)
            ->where('id', '<', $order->id)
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) config('plugged-host-ai.rux.agent.history_minutes', 30))))
            ->latest('id')
            ->limit(6)
            ->get()
            ->reverse();

        $history = [];
        foreach ($turns as $turn) {
            $rows = [['role' => 'user', 'content' => self::userContent($turn)]];

            foreach (RuxMessage::query()->where('turn_id', $turn->id)->whereKeyNot($turn->id)->orderBy('id')->get() as $row) {
                $rows[] = match ($row->role) {
                    RuxMessage::ASSISTANT => ['role' => 'assistant', 'content' => $row->content] + ($row->tool_calls ? ['tool_calls' => $row->tool_calls] : []),
                    default => [
                        'role' => 'tool',
                        'tool_call_id' => (string) $row->tool_call_id,
                        'content' => self::fence((string) $row->tool_name, Str::limit((string) $row->content, 600, ' …(cut)')),
                        'is_error' => $row->status === RuxMessage::FAILED,
                    ],
                };
            }

            $history[] = $rows;
        }

        // Oldest turns go first when the history is long.
        $budget = max(4, (int) config('plugged-host-ai.rux.agent.history_messages', 24));
        while ($history !== [] && array_sum(array_map('count', $history)) > $budget) {
            array_shift($history);
        }

        return [
            ['role' => 'system', 'content' => $this->prompt->build($context, $where)],
            ...array_merge(...($history ?: [[]])),
            ['role' => 'user', 'content' => self::userContent($order)],
        ];
    }

    /**
     * The order as the model reads it: the text, then what Discord resolved around it (who was
     * mentioned, which message it replies to).
     */
    private static function userContent(RuxMessage $message): string
    {
        $lines = [(string) $message->content];
        $meta = (array) $message->meta;

        foreach ((array) ($meta['mentions'] ?? []) as $mention) {
            if (is_array($mention) && isset($mention['id'])) {
                $lines[] = "(<@{$mention['id']}> is " . self::untrusted(str_replace("\n", ' ', (string) ($mention['name'] ?? 'unknown'))) . ($mention['bot'] ?? false ? ', a bot' : '') . ')';
            }
        }

        // The replied-to message and its author's name are written by someone else, so they go
        // in a fence as data: inline, they could pass for the admin's own order.
        if (is_array($meta['reply_to'] ?? null)) {
            $reply = $meta['reply_to'];
            $lines[] = '(They are replying to message id ' . ($reply['id'] ?? '?') . ' by <@' . ($reply['author_id'] ?? '?') . '>, shown below.)';
            $lines[] = '<replied_message untrusted="true">' . "\n"
                . 'Author name: ' . self::untrusted(str_replace("\n", ' ', (string) ($reply['author_name'] ?? 'someone'))) . "\n"
                . self::untrusted(Str::limit((string) ($reply['content'] ?? ''), 500)) . "\n"
                . '</replied_message>';
        }

        return implode("\n", $lines);
    }

    /**
     * Someone else's text inside the admin's turn: angle brackets are swapped for look-alikes so
     * it cannot close the fence it sits in or open a fake one.
     */
    private static function untrusted(string $text): string
    {
        return str_replace(['<', '>'], ['‹', '›'], $text);
    }

    private static function fence(string $tool, string $content): string
    {
        return '<tool_output tool="' . $tool . '" untrusted="true">' . "\n" . $content . "\n</tool_output>";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function store(RuxMessage $order, string $role, array $attributes): void
    {
        RuxMessage::query()->create($attributes + [
            'conversation' => $order->conversation,
            'turn_id' => $order->id,
            'channel_id' => $order->channel_id,
            'guild_id' => $order->guild_id,
            'author_id' => $order->author_id,
            'role' => $role,
            'status' => RuxMessage::DONE,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function finish(RuxMessage $order, string $status, array $meta = []): void
    {
        $order->update(['status' => $status, 'meta' => $meta === [] ? $order->meta : array_merge((array) $order->meta, $meta)]);
    }

    /**
     * Answer in the order's channel, as a reply to it, split at Discord's 2000 characters. Never
     * pings anyone: the model's text is not trusted with mentions.
     */
    private function reply(RuxMessage $order, string $text): void
    {
        $chunks = self::split(trim($text) !== '' ? $text : RuxConfig::say('reply.done'));

        foreach ($chunks as $index => $chunk) {
            $payload = ['content' => $chunk, 'allowed_mentions' => ['parse' => [], 'replied_user' => false]];

            if ($index === 0 && $order->discord_message_id !== null) {
                $payload['message_reference'] = ['message_id' => $order->discord_message_id, 'fail_if_not_exists' => false];
            }

            try {
                $this->discord->createMessage($order->channel_id, $payload);
            } catch (DiscordException $e) {
                Log::warning('[rux] could not reply', ['message' => $order->id, 'error' => $e->getMessage()]);

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
