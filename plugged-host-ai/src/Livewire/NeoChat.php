<?php

namespace PluggedHost\Ai\Livewire;

use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Ai\Agent\NeoAgent;
use PluggedHost\Ai\Agent\ServerGuard;
use PluggedHost\Ai\Agent\StepResult;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Models\NeoMessage;
use PluggedHost\Ai\Services\NeoGateway;
use PluggedHost\Ai\Support\MclogsHandoff;
use PluggedHost\Ai\Support\NeoAccess;
use PluggedHost\Ai\Support\NeoPreferences;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * The floating neo chat on the app and server panels (mounted from a BODY_END render hook).
 *
 * Nothing is loaded until the panel is opened, so the launcher costs nothing on page loads.
 * The browser drives the agent loop by calling step() while it answers `continue`; every call
 * re-checks ownership and access, and the ids it holds are #[Locked].
 */
class NeoChat extends Component
{
    public const PANELS = ['app', 'server'];

    #[Locked]
    public ?int $serverId = null;

    #[Locked]
    public ?int $conversationId = null;

    public bool $opened = false;

    public string $draft = '';

    /**
     * chat | settings (the gear) | history (earlier chats). A user who switched neo off only
     * ever gets the small "turn neo back on" view.
     */
    public string $view = 'chat';

    /**
     * Whether the launcher should exist on this page at all.
     */
    public static function shouldMount(): bool
    {
        try {
            $user = Filament::auth()->user();
            $panel = Filament::getCurrentPanel()?->getId();

            // Still mounted for a user who switched neo off: the settings now live in the chat
            // itself, so it stays as a small dimmed button to switch it back on.
            if (!$user instanceof User || !in_array($panel, self::PANELS, true) || !NeoAccess::ready()) {
                return false;
            }

            if (self::onWaitingRoom()) {
                return false;
            }

            if ($panel === 'server') {
                $tenant = Filament::getTenant();

                return $tenant instanceof Server && ServerGuard::canUse($user, $tenant);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function mount(): void
    {
        $tenant = Filament::getCurrentPanel()?->getId() === 'server' ? Filament::getTenant() : null;
        $this->serverId = $tenant instanceof Server ? $tenant->id : null;
    }

    /**
     * @return array{resume: bool}
     */
    public function open(): array
    {
        if (!NeoPreferences::enabled($this->account())) {
            $this->opened = true;

            return ['resume' => false];
        }

        $user = $this->user();
        $server = $this->server();

        if ($server !== null && !ServerGuard::canUse($user, $server)) {
            abort(403);
        }

        $this->opened = true;
        $this->conversationId ??= $this->agent()->conversationFor($user, $server)->id;

        return ['resume' => $this->conversation()->status === NeoConversation::RUNNING];
    }

    public function close(): void
    {
        $this->opened = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function send(): array
    {
        return $this->guarded(function (): StepResult {
            $result = $this->agent()->send($this->conversation(), $this->user(), $this->draft);

            if ($result->state === StepResult::CONTINUE) {
                $this->draft = '';
            }

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function step(): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->step($this->conversation(), $this->user()));
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $messageId): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->approve($this->conversation(), $this->user(), $messageId));
    }

    /**
     * @return array<string, mixed>
     */
    public function approveAll(): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->approve($this->conversation(), $this->user()));
    }

    /**
     * @return array<string, mixed>
     */
    public function deny(int $messageId): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->deny($this->conversation(), $this->user(), $messageId));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->cancel($this->conversation()));
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->retry($this->conversation(), $this->user()));
    }

    /**
     * "Ask neo" on the notification neo adds to a mclo.gs upload: a fresh chat that starts with
     * the log's analysis and asks neo to fix what it found. Only uploads this user made from
     * this server in the last hours are accepted, so "my server's log" is true.
     *
     * @return array<string, mixed>
     */
    public function askAboutLog(string $log): array
    {
        $user = $this->user();
        $upload = MclogsHandoff::find($user, $log);

        if ($upload === null || $this->serverId === null || (int) $upload['server_id'] !== $this->serverId) {
            Notification::make()->title(trans('plugged-host-ai::strings.mclogs.expired'))->warning()->send();

            return StepResult::of(StepResult::DONE)->toArray();
        }

        return $this->guarded(function () use ($user, $upload): StepResult {
            $conversation = $this->conversation();

            if ($conversation->messages()->exists()) {
                if (in_array($conversation->status, [NeoConversation::RUNNING, NeoConversation::AWAITING_APPROVAL], true)) {
                    $this->agent()->cancel($conversation);
                }

                $conversation = $this->agent()->newConversation($conversation, $user);
                $this->conversationId = $conversation->id;
            }

            $text = $upload['problems'] > 0
                ? trans_choice('plugged-host-ai::strings.mclogs.ask_problems', $upload['problems'], ['count' => $upload['problems'], 'url' => $upload['url']])
                : trans('plugged-host-ai::strings.mclogs.ask_errors', ['url' => $upload['url']]);

            return $this->agent()->sendStartingWith($conversation, $user, $text, 'read_mclogs', ['log' => $upload['id']]);
        });
    }

    public function newChat(): void
    {
        $this->conversationId = $this->agent()->newConversation($this->conversation(), $this->user())->id;
        $this->draft = '';
        $this->view = 'chat';
    }

    public function acknowledge(): void
    {
        NeoPreferences::set($this->user(), ['acknowledged_at' => now()]);
    }

    public function showSettings(): void
    {
        $this->view = 'settings';
    }

    public function showChat(): void
    {
        $this->view = 'chat';
    }

    public function showHistory(): void
    {
        $this->view = 'history';
    }

    /**
     * Continue an earlier chat from the history: it becomes the open conversation again (and
     * the current one, if it has anything in it, goes to the history in its place).
     *
     * @return array{resume: bool}
     */
    public function openConversation(int $id): array
    {
        $user = $this->user();
        $target = $this->ownConversation($user, $id);
        $current = $this->conversation();

        if ($current->id !== $target->id) {
            if (in_array($current->status, [NeoConversation::RUNNING, NeoConversation::AWAITING_APPROVAL], true)) {
                $this->agent()->cancel($current);
            }

            if ($current->messages()->exists()) {
                $current->update(['archived_at' => now()]);
            } else {
                $current->delete();
            }

            $target->update(['archived_at' => null, 'last_activity_at' => now()]);
            $this->conversationId = $target->id;
        }

        $this->view = 'chat';

        return ['resume' => $target->fresh()?->status === NeoConversation::RUNNING];
    }

    public function deleteConversation(int $id): void
    {
        $user = $this->user();
        $this->ownConversation($user, $id)->delete();

        if ($this->conversationId === $id) {
            $this->conversationId = $this->agent()->conversationFor($user, $this->server())->id;
        }
    }

    /**
     * The two per-user switches, saved the moment they are flipped. Works while neo is off,
     * since this is how it is switched back on.
     */
    public function setPreference(string $key, bool $value): void
    {
        abort_unless(in_array($key, ['enabled', 'auto_approve'], true), 422);

        NeoPreferences::set($this->account(), [$key => $value]);

        if ($key === 'enabled' && $value) {
            $this->view = 'chat';
            $this->conversationId = null;
        }
    }

    public function deleteHistory(): void
    {
        $user = $this->account();

        NeoConversation::query()->where('user_id', $user->id)->delete();
        $this->conversationId = NeoPreferences::enabled($user) ? $this->agent()->conversationFor($user, $this->server())->id : null;

        Notification::make()->title(trans('plugged-host-ai::strings.profile.deleted'))->success()->send();
    }

    public function render(): View
    {
        $account = $this->account();
        $enabled = NeoPreferences::enabled($account);

        if ($enabled && $this->opened && $this->view === 'history') {
            return view('plugged-host-ai::livewire.chat', [
                'conversation' => null,
                'enabled' => true,
                'settings' => null,
                'history' => $this->history($this->user()),
                'serverName' => $this->server()?->name,
            ]);
        }

        if (!$enabled || ($this->opened && $this->view === 'settings')) {
            return view('plugged-host-ai::livewire.chat', [
                'conversation' => null,
                'enabled' => $enabled,
                'settings' => $this->opened ? $this->settings($account, $enabled) : null,
                'serverName' => $this->server()?->name,
            ]);
        }

        if (!$this->opened || $this->conversationId === null) {
            return view('plugged-host-ai::livewire.chat', ['conversation' => null, 'enabled' => true, 'settings' => null]);
        }

        $user = $this->user();
        $conversation = $this->conversation();
        $acknowledged = NeoPreferences::acknowledged($user);

        return view('plugged-host-ai::livewire.chat', [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->reorder('seq', 'desc')->limit(60)->get()->reverse()->values(),
            'acknowledged' => $acknowledged,
            'autoApprove' => NeoPreferences::autoApprove($user),
            'usage' => $acknowledged ? $this->usage($user) : null,
            'serverName' => $conversation->server?->name,
            'pending' => $conversation->messages()->where('role', NeoMessage::TOOL)->where('status', NeoMessage::PENDING_APPROVAL)->count(),
            'enabled' => true,
            'settings' => null,
        ]);
    }

    /**
     * This place's chats (this server, or the app panel), newest first.
     *
     * @return \Illuminate\Support\Collection<int, NeoConversation>
     */
    private function history(User $user): \Illuminate\Support\Collection
    {
        return NeoConversation::query()
            ->where('user_id', $user->id)
            ->where('server_id', $this->serverId)
            ->whereHas('messages', fn ($query) => $query->where('role', NeoMessage::USER))
            ->withCount(['messages as user_messages_count' => fn ($query) => $query->where('role', NeoMessage::USER)])
            ->orderByDesc('last_activity_at')
            ->limit(50)
            ->get();
    }

    private function ownConversation(User $user, int $id): NeoConversation
    {
        return NeoConversation::query()
            ->whereKey($id)
            ->where('user_id', $user->id)
            ->where('server_id', $this->serverId)
            ->firstOrFail();
    }

    /**
     * What the settings view shows.
     *
     * @return array<string, mixed>
     */
    private function settings(User $user, bool $enabled): array
    {
        $usage = $enabled ? app(NeoGateway::class)->usage($user) : null;

        return [
            'enabled' => $enabled,
            'auto_approve' => NeoPreferences::autoApprove($user),
            'limits' => is_array($usage['limits'] ?? null) ? $usage['limits'] : null,
            'linked' => NoprofitLink::accountId($user) !== null || ($usage['linked'] ?? false),
            'retention_days' => (int) config('plugged-host-ai.retention_days', 30),
        ];
    }

    /**
     * Run an agent call, surviving the conversation being deleted underneath it (history
     * cleared in another tab, retention prune): the chat moves on to a fresh conversation
     * instead of failing the request.
     *
     * @param  callable(): StepResult  $call
     * @return array<string, mixed>
     */
    private function guarded(callable $call): array
    {
        try {
            return $this->respond($call());
        } catch (QueryException $e) {
            if ($this->conversationId !== null && NeoConversation::query()->whereKey($this->conversationId)->exists()) {
                throw $e;
            }

            $this->conversationId = $this->agent()->conversationFor($this->user(), $this->server())->id;

            return StepResult::of(StepResult::ERROR, trans('plugged-host-ai::strings.errors.conversation_gone'))->toArray();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function respond(StepResult $result): array
    {
        if ($result->state === StepResult::REDIRECT && $result->redirectUrl !== null) {
            // A full load: the start pages carry third-party scripts that SPA navigation would strand.
            $this->redirect($result->redirectUrl, navigate: false);
        }

        return $result->toArray();
    }

    /**
     * @return array{short: array<string, mixed>, long: array<string, mixed>}|null
     */
    private function usage(User $user): ?array
    {
        $usage = app(NeoGateway::class)->usage($user);

        return is_array($usage['limits'] ?? null) ? $usage['limits'] : null;
    }

    private function conversation(): NeoConversation
    {
        $user = $this->user();
        $conversation = $this->conversationId === null ? null : NeoConversation::query()
            ->whereKey($this->conversationId)
            ->where('user_id', $user->id)
            ->first();

        if ($conversation === null) {
            // Gone (deleted history, prune) or never opened: continue in the current one.
            $server = $this->server();
            abort_if($server !== null && !ServerGuard::canUse($user, $server), 403);

            $conversation = $this->agent()->conversationFor($user, $server);
            $this->conversationId = $conversation->id;
        }

        return $conversation;
    }

    private function server(): ?Server
    {
        return $this->serverId === null ? null : Server::query()->find($this->serverId);
    }

    private function user(): User
    {
        $user = $this->account();
        abort_unless(NeoAccess::enabledFor($user), 403);

        return $user;
    }

    /**
     * The signed-in user while neo is available on this panel at all, whether or not they have
     * it switched on (the settings view works in both states).
     */
    private function account(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && NeoAccess::ready(), 403);

        if ($this->serverId !== null) {
            $server = $this->server();
            abort_unless($server !== null && ServerGuard::canUse($user, $server), 403);
        }

        return $user;
    }

    private function agent(): NeoAgent
    {
        return app(NeoAgent::class);
    }

    /**
     * The start queue's and the ad gate's waiting rooms: no chat on top of the ads.
     */
    private static function onWaitingRoom(): bool
    {
        $gate = 'PluggedHost\\Ads\\Filament\\Server\\Pages\\StartGatePage';
        $ads = 'PluggedHost\\Ads\\Services\\AdDisplayService';

        try {
            if (Plugins::available($gate) && $gate::isCurrentRoute()) {
                return true;
            }

            return Plugins::available($ads) && app($ads)->onInterstitialRoute();
        } catch (Throwable) {
            return false;
        }
    }
}
