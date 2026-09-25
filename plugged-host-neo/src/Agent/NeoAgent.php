<?php

namespace PluggedHost\Neo\Agent;

use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PluggedHost\Neo\Models\NeoConversation;
use PluggedHost\Neo\Models\NeoMessage;
use PluggedHost\Neo\Services\Gateway\GatewayBusyException;
use PluggedHost\Neo\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Neo\Services\Gateway\LimitReachedException;
use PluggedHost\Neo\Services\Gateway\NotLinkedException;
use PluggedHost\Neo\Services\NeoGateway;
use PluggedHost\Neo\Support\NeoAccess;
use PluggedHost\Neo\Support\NeoPreferences;
use PluggedHost\Neo\Support\SecretRedactor;
use PluggedHost\Neo\Tools\NeoToolRegistry;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use Throwable;

/**
 * neo's agent loop, one unit of work per call.
 *
 * The browser drives it: send() starts a turn, then step() is called until it stops returning
 * `continue`. Each step either runs one queued tool or makes one model call through the
 * gateway, and everything is persisted, so no PHP request holds a worker for a whole
 * conversation, no queue worker is needed, and a turn resumes after the user navigates away.
 *
 * Every step re-checks that neo is on, that the user still has it on, and that they may still
 * use this server; every tool re-checks its permissions right before it runs.
 */
final class NeoAgent
{
    public function __construct(
        private readonly NeoGateway $gateway,
        private readonly NeoToolRegistry $tools,
        private readonly ContextBuilder $context,
    ) {}

    /**
     * The open conversation for this user in this place, created on first use.
     */
    public function conversationFor(User $user, ?Server $server): NeoConversation
    {
        return NeoConversation::query()
            ->where('user_id', $user->id)
            ->where('server_id', $server?->id)
            ->whereNull('archived_at')
            ->latest('id')
            ->first()
            ?? NeoConversation::query()->create([
                'user_id' => $user->id,
                'server_id' => $server?->id,
                'status' => NeoConversation::IDLE,
                'last_activity_at' => now(),
            ]);
    }

    public function newConversation(NeoConversation $conversation, User $user): NeoConversation
    {
        $conversation->update(['archived_at' => now()]);

        return $this->conversationFor($user, $conversation->server);
    }

    public function send(NeoConversation $conversation, User $user, string $text): StepResult
    {
        if (($blocked = $this->blockedReason($conversation, $user)) !== null) {
            return StepResult::of(StepResult::BLOCKED, $blocked);
        }

        $text = trim($text);
        if ($text === '') {
            return StepResult::of(StepResult::DONE);
        }

        $text = mb_substr($text, 0, (int) config('plugged-host-neo.max_message_chars', 4000));

        if ($conversation->status === NeoConversation::RUNNING) {
            return StepResult::busy();
        }

        $key = 'plugged-host-neo:messages:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('plugged-host-neo.messages_per_minute', 10)))) {
            return StepResult::of(StepResult::ERROR, trans('plugged-host-neo::strings.errors.rate_limited'));
        }
        RateLimiter::hit($key, 60);

        DB::transaction(function () use ($conversation, $text): void {
            // Anything still waiting belongs to the previous request: answer it so every tool
            // call keeps a result (providers reject a transcript with an unanswered call).
            $conversation->messages()
                ->where('role', NeoMessage::TOOL)
                ->whereIn('status', [NeoMessage::PENDING_APPROVAL, NeoMessage::QUEUED, NeoMessage::APPROVED])
                ->update([
                    'status' => NeoMessage::SKIPPED,
                    'content' => 'Not run: the user sent a new message instead.',
                ]);

            $this->append($conversation, NeoMessage::USER, ['content' => $text]);

            $conversation->update([
                'title' => $conversation->title ?? Str::limit($text, 60),
                'status' => NeoConversation::RUNNING,
                'step_count' => 0,
                'last_error' => null,
                'last_activity_at' => now(),
            ]);
        });

        return StepResult::continue();
    }

    public function step(NeoConversation $conversation, User $user): StepResult
    {
        $lock = Cache::lock('plugged-host-neo:conversation:' . $conversation->id, 120);
        if (!$lock->get()) {
            return StepResult::busy(1);
        }

        try {
            $conversation->refresh();

            if (($blocked = $this->blockedReason($conversation, $user)) !== null) {
                return StepResult::of(StepResult::BLOCKED, $blocked);
            }

            if ($conversation->status !== NeoConversation::RUNNING) {
                return $this->stateOf($conversation);
            }

            $next = $conversation->messages()
                ->where('role', NeoMessage::TOOL)
                ->whereIn('status', [NeoMessage::QUEUED, NeoMessage::APPROVED])
                ->first();

            if ($next !== null) {
                return $this->runTool($conversation, $user, $next);
            }

            if ($conversation->messages()->where('role', NeoMessage::TOOL)->where('status', NeoMessage::PENDING_APPROVAL)->exists()) {
                $conversation->update(['status' => NeoConversation::AWAITING_APPROVAL]);

                return StepResult::of(StepResult::AWAITING_APPROVAL);
            }

            return $this->callModel($conversation, $user);
        } finally {
            $lock->release();
        }
    }

    public function approve(NeoConversation $conversation, User $user, ?int $messageId = null): StepResult
    {
        return $this->decide($conversation, $user, $messageId, approve: true);
    }

    public function deny(NeoConversation $conversation, User $user, int $messageId): StepResult
    {
        return $this->decide($conversation, $user, $messageId, approve: false);
    }

    /**
     * Stop the current request: nothing still waiting will run.
     */
    public function cancel(NeoConversation $conversation): StepResult
    {
        $conversation->messages()
            ->where('role', NeoMessage::TOOL)
            ->whereIn('status', [NeoMessage::PENDING_APPROVAL, NeoMessage::QUEUED, NeoMessage::APPROVED])
            ->update(['status' => NeoMessage::SKIPPED, 'content' => 'Not run: the user stopped this request.']);

        $conversation->update(['status' => NeoConversation::IDLE]);

        return StepResult::of(StepResult::DONE);
    }

    /**
     * Try again after an outage or once a limit window has reset.
     */
    public function retry(NeoConversation $conversation, User $user): StepResult
    {
        if (!in_array($conversation->status, [NeoConversation::ERROR, NeoConversation::LIMITED, NeoConversation::UNLINKED], true)) {
            return $this->stateOf($conversation);
        }

        if (($blocked = $this->blockedReason($conversation, $user)) !== null) {
            return StepResult::of(StepResult::BLOCKED, $blocked);
        }

        $conversation->update(['status' => NeoConversation::RUNNING, 'last_error' => null]);

        return StepResult::continue();
    }

    /**
     * Why this user cannot use neo here right now, or null.
     */
    public function blockedReason(NeoConversation $conversation, User $user): ?string
    {
        if ((int) $conversation->user_id !== (int) $user->id) {
            return trans('plugged-host-neo::strings.errors.not_yours');
        }

        if (!NeoAccess::enabledFor($user)) {
            return trans('plugged-host-neo::strings.errors.disabled');
        }

        if (!NeoPreferences::acknowledged($user)) {
            return trans('plugged-host-neo::strings.errors.consent');
        }

        if ($conversation->server_id !== null) {
            $server = $conversation->server;

            if ($server === null || !ServerGuard::canUse($user, $server)) {
                return trans('plugged-host-neo::strings.errors.no_access');
            }
        }

        return null;
    }

    private function runTool(NeoConversation $conversation, User $user, NeoMessage $row): StepResult
    {
        $key = 'plugged-host-neo:tools:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('plugged-host-neo.tools_per_minute', 60)))) {
            return StepResult::busy(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        $row->update(['status' => NeoMessage::EXECUTING]);
        $started = microtime(true);
        $context = new ToolContext($user, $conversation->server, $conversation);
        $tool = $this->tools->find((string) $row->tool_name);
        $arguments = $row->arguments();

        try {
            if ($tool === null) {
                $result = ToolResult::error("There is no tool called {$row->tool_name}.");
            } elseif (!$this->tools->permitted($tool, $context, $arguments)) {
                $result = ToolResult::error("The user does not have permission for {$tool->name()} on this server.");
            } elseif ($context->server !== null && $tool->name() !== 'server_status' && ($reason = ServerGuard::blockedReason($context->server)) !== null) {
                $result = ToolResult::error("This cannot be done right now: {$reason}.");
            } else {
                $result = $tool->execute($context, $arguments);
            }
        } catch (InvalidArgumentException $e) {
            $result = ToolResult::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $result = ToolResult::error('The action failed on the server (' . class_basename($e) . '). Tell the user and suggest checking the console.');
        }

        $row->update([
            'status' => $result->ok ? NeoMessage::DONE : NeoMessage::FAILED,
            'content' => SecretRedactor::redact($result->content),
            'meta' => array_merge((array) $row->meta, [
                'display' => $result->display,
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]),
        ]);

        $conversation->update(['last_activity_at' => now()]);

        return $result->redirect !== null ? StepResult::redirect($result->redirect) : StepResult::continue();
    }

    private function callModel(NeoConversation $conversation, User $user): StepResult
    {
        $context = new ToolContext($user, $conversation->server, $conversation);
        $finalAnswer = $conversation->step_count >= max(1, (int) config('plugged-host-neo.max_steps', 10));
        $available = $this->tools->available($context);

        try {
            $response = $this->gateway->turn(
                $user,
                $conversation,
                $this->context->build($conversation, $context, $finalAnswer),
                $this->tools->definitions($available),
                $finalAnswer ? 'none' : 'auto',
            );
        } catch (LimitReachedException $e) {
            return $this->halt($conversation, NeoConversation::LIMITED, StepResult::LIMITED, trans('plugged-host-neo::strings.errors.limited_' . ($e->window === 'long' ? 'long' : 'short'), [
                'time' => $this->localTime($e->retryAt, $user),
            ]), ['window' => $e->window, 'retry_at' => $e->retryAt]);
        } catch (NotLinkedException) {
            return $this->halt($conversation, NeoConversation::UNLINKED, StepResult::UNLINKED, trans('plugged-host-neo::strings.errors.unlinked'));
        } catch (GatewayBusyException $e) {
            return StepResult::busy($e->retryAfter);
        } catch (GatewayUnavailableException $e) {
            $conversation->update(['last_error' => Str::limit($e->getMessage(), 250)]);

            return $this->halt($conversation, NeoConversation::ERROR, StepResult::ERROR, trans('plugged-host-neo::strings.errors.unavailable'));
        }

        $message = (array) ($response['message'] ?? []);
        $calls = array_values(array_filter((array) ($message['tool_calls'] ?? []), 'is_array'));
        $content = is_string($message['content'] ?? null) && trim($message['content']) !== '' ? trim($message['content']) : null;

        DB::transaction(function () use ($conversation, $user, $response, $calls, $content, $available, $finalAnswer): void {
            $this->append($conversation, NeoMessage::ASSISTANT, [
                'content' => $content,
                'tool_calls' => $calls === [] ? null : $calls,
                'meta' => [
                    'provider' => $response['provider']['label'] ?? null,
                    'model' => $response['provider']['model'] ?? null,
                    'finish_reason' => $response['finish_reason'] ?? null,
                    'tokens' => (int) ($response['usage']['charged_tokens'] ?? 0),
                ],
            ]);

            foreach ($calls as $call) {
                $this->queueToolCall($conversation, $user, $call, $available, $finalAnswer);
            }

            $conversation->update([
                'status' => $calls === [] ? NeoConversation::IDLE : NeoConversation::RUNNING,
                'step_count' => $conversation->step_count + 1,
                'tokens_used' => $conversation->tokens_used + (int) ($response['usage']['charged_tokens'] ?? 0),
                'last_provider' => Str::limit((string) ($response['provider']['label'] ?? ''), 60, ''),
                'last_activity_at' => now(),
            ]);

            if (($response['finish_reason'] ?? null) === 'content_filter') {
                $this->append($conversation, NeoMessage::NOTICE, ['content' => trans('plugged-host-neo::strings.errors.refused')]);
            }
        });

        return $calls === [] ? StepResult::of(StepResult::DONE) : StepResult::continue();
    }

    /**
     * Store one tool call from the model with the status the approval policy gives it.
     *
     * @param  array<string, mixed>  $call
     * @param  array<string, \PluggedHost\Neo\Tools\Tool>  $available
     */
    private function queueToolCall(NeoConversation $conversation, User $user, array $call, array $available, bool $finalAnswer): void
    {
        $name = (string) ($call['function']['name'] ?? '');
        $raw = (string) ($call['function']['arguments'] ?? '{}');
        $arguments = json_decode($raw === '' ? '{}' : $raw, true);
        $tool = $available[$name] ?? null;

        $status = NeoMessage::QUEUED;
        $content = null;
        $risk = ToolRisk::Read;
        $summary = $name;

        if ($finalAnswer) {
            [$status, $content] = [NeoMessage::SKIPPED, 'Not run: the step limit for this message was reached.'];
        } elseif ($tool === null) {
            [$status, $content] = [NeoMessage::FAILED, "Tool {$name} is not available here (unknown, or the user lacks the permission)."];
        } elseif (!is_array($arguments)) {
            [$status, $content] = [NeoMessage::FAILED, 'The arguments were not valid JSON. Call the tool again with a JSON object.'];
        } else {
            try {
                $risk = $tool->risk($arguments);
                $summary = $tool->summarize($arguments);
            } catch (InvalidArgumentException $e) {
                [$status, $content] = [NeoMessage::FAILED, $e->getMessage()];
            }

            if ($status === NeoMessage::QUEUED && $risk !== ToolRisk::Read && !$this->runsWithoutAsking($user, $risk)) {
                $status = NeoMessage::PENDING_APPROVAL;
            }
        }

        $this->append($conversation, NeoMessage::TOOL, [
            'tool_call_id' => (string) ($call['id'] ?? ''),
            'tool_name' => Str::limit($name, 64, ''),
            'status' => $status,
            'content' => $content,
            'meta' => [
                'arguments' => is_array($arguments) ? $arguments : [],
                'summary' => $summary,
                'risk' => $risk->value,
                'approval' => $status === NeoMessage::QUEUED && $risk !== ToolRisk::Read ? 'auto' : null,
                'preview' => $this->preview($name, is_array($arguments) ? $arguments : []),
            ],
        ]);
    }

    private function runsWithoutAsking(User $user, ToolRisk $risk): bool
    {
        if (!NeoPreferences::autoApprove($user)) {
            return false;
        }

        return !($risk === ToolRisk::Destructive && config('plugged-host-neo.always_confirm_destructive', false));
    }

    /**
     * What the approval card shows for content-carrying calls, redacted and short.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function preview(string $tool, array $arguments): ?string
    {
        $text = match ($tool) {
            'write_file' => (string) ($arguments['content'] ?? ''),
            'edit_file' => '- ' . (string) ($arguments['old_text'] ?? '') . "\n+ " . (string) ($arguments['new_text'] ?? ''),
            'send_command' => (string) ($arguments['command'] ?? ''),
            default => null,
        };

        return $text === null ? null : Str::limit(SecretRedactor::redact($text), 1500);
    }

    private function decide(NeoConversation $conversation, User $user, ?int $messageId, bool $approve): StepResult
    {
        if (($blocked = $this->blockedReason($conversation, $user)) !== null) {
            return StepResult::of(StepResult::BLOCKED, $blocked);
        }

        $query = $conversation->messages()->where('role', NeoMessage::TOOL)->where('status', NeoMessage::PENDING_APPROVAL);
        if ($messageId !== null) {
            $query->whereKey($messageId);
        }

        foreach ($query->get() as $row) {
            $row->update($approve
                ? ['status' => NeoMessage::APPROVED, 'meta' => array_merge((array) $row->meta, ['approval' => 'user', 'approved_at' => now()->toIso8601String()])]
                : ['status' => NeoMessage::DENIED, 'content' => 'The user declined this action.', 'meta' => array_merge((array) $row->meta, ['approval' => 'denied'])]);
        }

        $stillPending = $conversation->messages()->where('role', NeoMessage::TOOL)->where('status', NeoMessage::PENDING_APPROVAL)->exists();
        $conversation->update(['status' => $stillPending ? NeoConversation::AWAITING_APPROVAL : NeoConversation::RUNNING]);

        return $stillPending ? StepResult::of(StepResult::AWAITING_APPROVAL) : StepResult::continue();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function halt(NeoConversation $conversation, string $status, string $state, string $notice, array $meta = []): StepResult
    {
        $this->append($conversation, NeoMessage::NOTICE, ['content' => $notice, 'meta' => $meta + ['kind' => $state]]);
        $conversation->update(['status' => $status, 'last_activity_at' => now()]);

        return StepResult::of($state, $notice);
    }

    private function stateOf(NeoConversation $conversation): StepResult
    {
        return match ($conversation->status) {
            NeoConversation::AWAITING_APPROVAL => StepResult::of(StepResult::AWAITING_APPROVAL),
            NeoConversation::LIMITED => StepResult::of(StepResult::LIMITED),
            NeoConversation::UNLINKED => StepResult::of(StepResult::UNLINKED),
            NeoConversation::ERROR => StepResult::of(StepResult::ERROR),
            default => StepResult::of(StepResult::DONE),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function append(NeoConversation $conversation, string $role, array $attributes): NeoMessage
    {
        return NeoMessage::query()->create($attributes + [
            'conversation_id' => $conversation->id,
            'seq' => $conversation->nextSeq(),
            'role' => $role,
        ]);
    }

    private function localTime(?string $iso, User $user): string
    {
        if ($iso === null) {
            return '?';
        }

        try {
            return CarbonImmutable::parse($iso)->setTimezone($user->timezone ?: config('app.timezone'))->isoFormat('ddd HH:mm');
        } catch (Throwable) {
            return $iso;
        }
    }
}
