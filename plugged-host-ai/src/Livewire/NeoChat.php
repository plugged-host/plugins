<?php

namespace PluggedHost\Ai\Livewire;

use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use PluggedHost\Ai\Agent\ChatImages;
use PluggedHost\Ai\Agent\NeoAgent;
use PluggedHost\Ai\Agent\ServerGuard;
use PluggedHost\Ai\Agent\StepResult;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Models\NeoMessage;
use PluggedHost\Ai\Support\ImageInput;
use PluggedHost\Ai\Support\Markdown\ImagesAsTextExtension;
use PluggedHost\Ai\Support\MclogsHandoff;
use PluggedHost\Ai\Support\NeoAccess;
use PluggedHost\Ai\Support\NeoModel;
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
    use WithFileUploads;

    public const PANELS = ['app', 'server'];

    #[Locked]
    public ?int $serverId = null;

    #[Locked]
    public ?int $conversationId = null;

    public bool $opened = false;

    public string $draft = '';

    /**
     * Images for the next message (picked, pasted or dropped), at most ImageInput::MAX_IMAGES.
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $images = [];

    /**
     * Where uploads land before they join $images, so several picks add up.
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $uploads = [];

    /**
     * chat | settings (the gear) | history (earlier chats). A user who switched neo off only
     * ever gets the small "turn neo back on" view.
     */
    public string $view = 'chat';

    /** The answer streamed so far in this request (live()), and when it was last rendered. */
    private string $liveText = '';

    private float $liveRenderedAt = 0;

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
            $result = $this->agent()->send($this->conversation(), $this->user(), $this->draft, images: $this->images);

            if ($result->state === StepResult::CONTINUE) {
                $this->draft = '';
                $this->images = [];
            }

            return $result;
        });
    }

    /**
     * New uploads join the images; ones that are not an image neo takes are left out with a note.
     */
    public function updatedUploads(): void
    {
        $rules = ['image', 'mimes:png,jpg,jpeg,webp,gif', 'max:' . (ImageInput::MAX_BYTES / 1024)];
        $valid = array_values(array_filter((array) $this->uploads, static fn ($file): bool => validator(['file' => $file], ['file' => $rules])->passes()));

        count($valid) < count((array) $this->uploads)
            ? $this->addError('uploads', trans('plugged-host-ai::strings.chat.image_error'))
            : $this->resetErrorBag('uploads');

        $this->images = array_slice([...$this->images, ...$valid], 0, ImageInput::MAX_IMAGES);
        $this->uploads = [];
    }

    public function removeImage(int $index): void
    {
        unset($this->images[$index]);
        $this->images = array_values($this->images);
    }

    /**
     * @return array<string, mixed>
     */
    public function step(): array
    {
        return $this->guarded(fn (): StepResult => $this->agent()->step($this->conversation(), $this->user(), $this->live(...)));
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
        ChatImages::forget([$id]);

        if ($this->conversationId === $id) {
            $this->conversationId = $this->agent()->conversationFor($user, $this->server())->id;
        }
    }

    /**
     * The model picker under the chat: any model the backend offers, by its key ('standard' goes
     * back to the default model). One the account's plan does not open only says so.
     */
    public function setModel(string $model): void
    {
        abort_unless($model === 'standard' || preg_match('/^\d{1,3}$/', $model) === 1, 422);

        $user = $this->user();

        if ($model !== 'standard') {
            $offered = collect($this->catalog(app(NeoBackend::class)->usage($user))['models'] ?? [])->firstWhere('key', $model);

            if ($offered === null) {
                Notification::make()->title(trans('plugged-host-ai::strings.errors.model_unavailable'))->warning()->send();

                return;
            }

            if (empty($offered['allowed'])) {
                Notification::make()->title(trans('plugged-host-ai::strings.chat.model_locked', [
                    'model' => $offered['label'],
                    'app' => config('app.name'),
                    'plan' => trans('plugged-host-ai::strings.chat.plan_' . (($offered['access'] ?? null) === 'plusplus' ? 'plusplus' : 'plus')),
                ]))->warning()->send();

                return;
            }
        }

        NeoPreferences::set($user, ['model' => $model]);
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

        $ids = NeoConversation::query()->where('user_id', $user->id)->pluck('id');
        NeoConversation::query()->whereKey($ids)->delete();
        ChatImages::forget($ids);
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
        $backend = $acknowledged ? app(NeoBackend::class)->usage($user) : null;
        $models = $this->models($user, $backend);

        return view('plugged-host-ai::livewire.chat', [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->reorder('seq', 'desc')->limit(60)->get()->reverse()->values(),
            'acknowledged' => $acknowledged,
            'autoApprove' => NeoPreferences::autoApprove($user),
            'usage' => $this->meter($backend, $models),
            'models' => $models,
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
        $usage = $enabled ? app(NeoBackend::class)->usage($user) : null;

        $catalog = $this->catalog($usage);

        return [
            'enabled' => $enabled,
            'auto_approve' => NeoPreferences::autoApprove($user),
            'limits' => is_array($usage['limits'] ?? null) ? $usage['limits'] : null,
            'mode' => $catalog['mode'],
            // Limits mode models: the weekly window of every limited model the account may use.
            'model_windows' => array_values(array_filter($catalog['models'], static fn (array $model): bool => !empty($model['allowed']) && (is_array($model['window'] ?? null) || is_array($model['image_window'] ?? null)))),
            // Unknown (backend down) counts as linked: the limits then say "unavailable".
            'linked' => $usage['linked'] ?? true,
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
     * Send the model's answer to the chat while it is written (wire:stream targets in the chat
     * view). The finished message replaces it when the step's response arrives.
     */
    private function live(string $kind, string $text): void
    {
        if ($kind === 'text') {
            // The answer so far, rendered as markdown like a finished message and sent whole in
            // place of the last render, a few times a second.
            $this->liveText .= $text;

            if (microtime(true) - $this->liveRenderedAt >= 0.15) {
                $this->liveRenderedAt = microtime(true);
                $this->stream(self::markdown($this->liveText), true, 'neo-live-text');
            }

            return;
        }

        match ($kind) {
            'thinking' => $this->stream(e($text), false, 'neo-live-think'),
            'reset' => [$this->liveText = '', $this->stream('', true, 'neo-live-text'), $this->stream('', true, 'neo-live-think')],
            default => null,
        };
    }

    /**
     * A message's markdown as the chat shows it (chat.blade.php renders finished messages the same
     * way): GitHub's flavour plus footnotes and ==highlights==. $id keeps each message's footnote
     * anchors apart.
     */
    public static function markdown(string $text, string $id = 'live'): string
    {
        return Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'footnote' => [
                'footnote_id_prefix' => "neo-fn-{$id}-",
                'ref_id_prefix' => "neo-fnref-{$id}-",
                'container_add_hr' => false,
            ],
        ], [new ImagesAsTextExtension(), new FootnoteExtension(), new HighlightExtension()]);
    }

    /**
     * The backend's limits mode and models, in a shape the view can count on.
     *
     * @param  array<string, mixed>|null  $backend  NeoBackend::usage()
     * @return array{mode: string, default: ?string, models: list<array<string, mixed>>}
     */
    private function catalog(?array $backend): array
    {
        $models = array_values(array_filter((array) ($backend['models'] ?? []), static fn ($model): bool => is_array($model) && is_string($model['key'] ?? null) && filled($model['label'] ?? null)));

        return [
            'mode' => ($backend['mode'] ?? 'all') === 'models' ? 'models' : 'all',
            'default' => isset($backend['default_model']) ? (string) $backend['default_model'] : null,
            'models' => $models,
        ];
    }

    /**
     * The model picker, or null while there is nothing to pick from (one model or none).
     *
     * @param  array<string, mixed>|null  $backend  NeoBackend::usage()
     * @return array{current: ?string, list: list<array<string, mixed>>, mode: string, plus_url: ?string}|null
     */
    private function models(User $user, ?array $backend): ?array
    {
        $catalog = $this->catalog($backend);

        if (count($catalog['models']) < 2) {
            return null;
        }

        $allowed = array_values(array_filter($catalog['models'], static fn (array $model): bool => !empty($model['allowed'])));
        $picked = NeoModel::forTurn($user);

        // A pick from before models had names ('premium') is the first model not open to everyone.
        if ($picked === 'premium') {
            $picked = collect($allowed)->first(static fn (array $model): bool => ($model['access'] ?? 'everyone') !== 'everyone')['key'] ?? null;
        }

        $current = collect($allowed)->contains('key', $picked) ? $picked : $catalog['default'];

        $picked = collect($catalog['models'])->firstWhere('key', $current);

        return [
            'current' => $current,
            // Shown in the bars' place: the picked model is not metered at all (limits by model).
            'unlimited' => $catalog['mode'] === 'models' && is_array($picked) && empty($picked['limited']) && empty($picked['image_window']),
            'list' => $catalog['models'],
            'mode' => $catalog['mode'],
            'plus_url' => self::plusUrl(),
        ];
    }

    /**
     * The usage bars under the chat: the 5-hour and weekly windows (limits mode all), the
     * current model's weekly window when it is limited (limits mode models), or nothing.
     *
     * @param  array<string, mixed>|null  $backend  NeoBackend::usage()
     * @param  array<string, mixed>|null  $models  models()
     * @return array<string, array<string, mixed>>|null Windows by the label kind: short, long, week.
     */
    private function meter(?array $backend, ?array $models): ?array
    {
        $catalog = $this->catalog($backend);
        $picked = collect($catalog['models'])->firstWhere('key', $models['current'] ?? $catalog['default']);

        $windows = $catalog['mode'] === 'all'
            ? (is_array($backend['limits'] ?? null) ? $backend['limits'] : [])
            : (is_array($picked['window'] ?? null) ? ['week' => $picked['window']] : []);

        // An image model's pictures per 24 hours, in either mode.
        if (is_array($picked['image_window'] ?? null)) {
            $windows['images'] = $picked['image_window'];
        }

        return $windows === [] ? null : $windows;
    }

    /**
     * Where Plus and PlusPlus are sold (plugged-host-economy's Plus page), for locked models.
     */
    private static function plusUrl(): ?string
    {
        $page = 'PluggedHost\\Economy\\Filament\\App\\Pages\\PlusPage';

        try {
            return Plugins::available($page) && $page::canAccess() ? $page::getUrl(panel: 'app') : null;
        } catch (Throwable) {
            return null;
        }
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
