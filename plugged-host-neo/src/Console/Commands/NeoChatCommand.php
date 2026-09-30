<?php

namespace PluggedHost\Neo\Console\Commands;

use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;
use PluggedHost\Neo\Agent\NeoAgent;
use PluggedHost\Neo\Agent\StepResult;
use PluggedHost\Neo\Models\NeoConversation;
use PluggedHost\Neo\Models\NeoMessage;

/**
 * Drives the same agent the chat uses, from the terminal, for testing a deployment end to end.
 * It acts exactly as that user would (their permissions, their neo settings, their limits).
 */
class NeoChatCommand extends Command
{
    protected $signature = 'plugged-host-neo:chat
        {user : User id or username}
        {message : What to ask neo}
        {--server= : Server uuid_short to work on (omit for general support)}
        {--approve : Approve every action neo asks permission for}
        {--new : Start a fresh conversation}';

    protected $description = 'Ask neo something as a given user and print the transcript.';

    public function handle(NeoAgent $agent): int
    {
        $user = User::query()->whereKey($this->argument('user'))->orWhere('username', $this->argument('user'))->first();
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $server = null;
        if ($this->option('server')) {
            $server = Server::query()->where('uuid_short', $this->option('server'))->orWhere('uuid', $this->option('server'))->first();
            if ($server === null) {
                $this->error('Server not found.');

                return self::FAILURE;
            }
        }

        $conversation = $agent->conversationFor($user, $server);
        if ($this->option('new')) {
            $conversation = $agent->newConversation($conversation, $user);
        }

        $seen = (int) $conversation->messages()->max('seq');
        $result = $agent->send($conversation, $user, (string) $this->argument('message'));

        for ($i = 0; $i < 60; $i++) {
            $seen = $this->printNew($conversation, $seen);

            if ($result->state === StepResult::BUSY) {
                sleep(max(1, $result->retryAfter));
            } elseif ($result->state === StepResult::AWAITING_APPROVAL && $this->option('approve')) {
                $result = $agent->approve($conversation->refresh(), $user);

                continue;
            } elseif ($result->state !== StepResult::CONTINUE) {
                break;
            }

            $result = $agent->step($conversation->refresh(), $user);
        }

        $this->printNew($conversation, $seen);
        $this->line('');
        $this->comment("state: {$result->state}" . ($result->message ? " ({$result->message})" : '') . ($result->redirectUrl ? " → {$result->redirectUrl}" : ''));

        return in_array($result->state, [StepResult::DONE, StepResult::AWAITING_APPROVAL, StepResult::REDIRECT], true) ? self::SUCCESS : self::FAILURE;
    }

    private function printNew(NeoConversation $conversation, int $after): int
    {
        foreach ($conversation->messages()->where('seq', '>', $after)->get() as $message) {
            $after = max($after, $message->seq);

            match ($message->role) {
                NeoMessage::USER => $this->line("<fg=cyan>you:</> {$message->content}"),
                NeoMessage::ASSISTANT => $message->content ? $this->line("<fg=green>neo:</> {$message->content}") : null,
                NeoMessage::TOOL => $this->line('  <fg=yellow>[' . $message->tool_name . ' · ' . $message->status . ']</> ' . ($message->meta['summary'] ?? '')),
                default => $this->line("<fg=red>notice:</> {$message->content}"),
            };
        }

        return $after;
    }
}
