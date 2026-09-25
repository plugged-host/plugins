<?php

namespace PluggedHost\Neo\Livewire;

use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PluggedHost\Neo\Agent\NeoAgent;
use PluggedHost\Neo\Agent\ServerGuard;
use PluggedHost\Neo\Agent\StepResult;
use PluggedHost\Neo\Models\NeoConversation;
use PluggedHost\Neo\Models\NeoMessage;
use PluggedHost\Neo\Services\NeoGateway;
use PluggedHost\Neo\Support\NeoAccess;
use PluggedHost\Neo\Support\NeoPreferences;
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
     * Whether the launcher should exist on this page at all.
     */
    public static function shouldMount(): bool
    {
        try {
            $user = Filament::auth()->user();
            $panel = Filament::getCurrentPanel()?->getId();

            if (!$user instanceof User || !in_array($panel, self::PANELS, true) || !NeoAccess::enabledFor($user)) {
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
        $result = $this->agent()->send($this->conversation(), $this->user(), $this->draft);

        if ($result->state === StepResult::CONTINUE) {
            $this->draft = '';
        }

        return $this->respond($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function step(): array
    {
        return $this->respond($this->agent()->step($this->conversation(), $this->user()));
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $messageId): array
    {
        return $this->respond($this->agent()->approve($this->conversation(), $this->user(), $messageId));
    }

    /**
     * @return array<string, mixed>
     */
    public function approveAll(): array
    {
        return $this->respond($this->agent()->approve($this->conversation(), $this->user()));
    }

    /**
     * @return array<string, mixed>
     */
    public function deny(int $messageId): array
    {
        return $this->respond($this->agent()->deny($this->conversation(), $this->user(), $messageId));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(): array
    {
        return $this->respond($this->agent()->cancel($this->conversation()));
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(): array
    {
        return $this->respond($this->agent()->retry($this->conversation(), $this->user()));
    }

    public function newChat(): void
    {
        $this->conversationId = $this->agent()->newConversation($this->conversation(), $this->user())->id;
        $this->draft = '';
    }

    public function acknowledge(): void
    {
        NeoPreferences::set($this->user(), ['acknowledged_at' => now()]);
    }

    public function render(): View
    {
        if (!$this->opened || $this->conversationId === null) {
            return view('plugged-host-neo::livewire.chat', ['conversation' => null]);
        }

        $user = $this->user();
        $conversation = $this->conversation();
        $acknowledged = NeoPreferences::acknowledged($user);

        return view('plugged-host-neo::livewire.chat', [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->reorder('seq', 'desc')->limit(60)->get()->reverse()->values(),
            'acknowledged' => $acknowledged,
            'autoApprove' => NeoPreferences::autoApprove($user),
            'usage' => $acknowledged ? $this->usage($user) : null,
            'serverName' => $conversation->server?->name,
            'pending' => $conversation->messages()->where('role', NeoMessage::TOOL)->where('status', NeoMessage::PENDING_APPROVAL)->count(),
            'profileUrl' => Route::has('filament.app.auth.profile') ? route('filament.app.auth.profile', ['tab' => 'neo::data::tab']) : null,
        ]);
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
        $conversation = $this->conversationId === null ? null : NeoConversation::query()
            ->whereKey($this->conversationId)
            ->where('user_id', $this->user()->id)
            ->first();

        abort_if($conversation === null, 404);

        return $conversation;
    }

    private function server(): ?Server
    {
        return $this->serverId === null ? null : Server::query()->find($this->serverId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && NeoAccess::enabledFor($user), 403);

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
            if (class_exists($gate) && $gate::isCurrentRoute()) {
                return true;
            }

            return class_exists($ads) && app($ads)->onInterstitialRoute();
        } catch (Throwable) {
            return false;
        }
    }
}
