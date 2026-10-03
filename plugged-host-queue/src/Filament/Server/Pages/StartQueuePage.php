<?php

namespace PluggedHost\Queue\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterval;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use PluggedHost\Queue\Models\StartQueueEntry;
use PluggedHost\Queue\Services\NodePressure;
use PluggedHost\Queue\Services\NodeTransferService;
use PluggedHost\Queue\Services\PriorityPassService;
use PluggedHost\Queue\Services\StartQueueService;
use Throwable;

/**
 * The waiting page for a start that is being held back.
 *
 * Reached by pressing Start or Restart on a server whose node is over the pressure
 * line: plugged-host-ads' `PowerActionInterceptor` cancels the Livewire call and sends
 * the user here instead, so — unlike the ad gate, which starts the container on the
 * click and collects an impression while it boots — nothing has been sent to wings yet
 * and the container is still down. That difference is the whole shape of this page.
 *
 * It is a view of state, not the machine. `QueueSweepCommand` releases and dispatches
 * once a minute whether or not anyone is looking, because a start that only happens
 * while a tab is open would punish the user for closing it. What the poll adds is
 * speed and honesty: a release the moment it is this entry's turn rather than up to a
 * minute later, and a position that is recounted rather than remembered.
 *
 * There are three ways out and the page offers all of them. Wait, and the queue
 * dispatches the start. Buy a priority window with tokens, and the next recount puts
 * this entry above everyone without one. Or take the transfer, and the server moves to
 * a node that is not busy — which cancels the wait rather than ending it, because a
 * server that is copying between nodes cannot start.
 *
 * Modelled on plugged-host-ads' `StartGatePage`, including the parts that look like
 * accidents:
 *
 * `canAccess()` deliberately does not consult the queue. Filament re-runs it on every
 * Livewire request, so a state check there would 403 the very poll that releases the
 * user. The state checks belong in `mount()` and in the interceptor.
 *
 * The release is server-side only. `tick()` asks `StartQueueService`, which compares
 * against `queued_at` in the database and the node's live utilisation. Calling
 * `$wire.tick()` from devtools at second one returns without redirecting; the
 * countdown on screen is decoration.
 */
class StartQueuePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-hourglass-high';

    protected static ?string $slug = 'queue';

    protected string $view = 'plugged-host-queue::filament.pages.start-queue';

    /**
     * Slower than the ad gate's 2 s. This wait is measured in minutes rather than
     * seconds, the sweep is the backstop, and nothing on screen needs per-second
     * resolution — a poll every two seconds for ten minutes would be three hundred
     * round trips to redraw a queue position that changes every twenty.
     */
    public const POLL_INTERVAL = '5s';

    /** Set by the client-side probe. Cosmetic — it never gates the release. */
    public bool $adsBlocked = false;

    /**
     * The server's own clock, published so the browser can tick between polls.
     *
     * Properties rather than getViewData() keys, for the same reason as plugged-host-ads'
     * StartGatePage: the waited clock and the bar used to be rendered server-side, so they
     * moved in POLL_INTERVAL steps — five seconds at a time here, which reads as a stopped
     * clock rather than a wait. A public property is in the Livewire snapshot, so
     * `$wire.elapsedSeconds` is readable from Alpine, and the view runs a 1 Hz client clock
     * re-seeded from these on every poll (see the x-effect in start-queue.blade.php).
     * Without the re-seed the browser's clock would drift away from the server's forever.
     *
     * Still decoration: releaseReason() is the only thing that ends the wait.
     */
    public int $elapsedSeconds = 0;

    public int $maxSeconds = 0;

    /**
     * The node the transfer offer would move this server to, resolved once.
     *
     * Held on the component rather than recomputed per render because finding it costs
     * a viable-node scan, a utilisation reading per candidate and an allocation query —
     * fine once per page load, wasteful every five seconds. It is only ever a label:
     * `NodeTransferService::execute()` re-runs the whole search on the click, so a
     * stale name here cannot send a server anywhere it should not go.
     */
    public ?string $transferNodeName = null;

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Server && parent::canAccess();
    }

    /** Never in the sidebar. Nobody navigates *to* a queue. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Whether the request being rendered is this page.
     *
     * Registered with plugged-host-ads' interstitial registry by
     * `QueuePluginProvider`, which is how that plugin knows to keep its generic
     * in-content slot out of this document and to serve the waiting room's
     * page-level scripts here instead. Route comparison only — it runs twice per
     * request.
     */
    public static function isCurrentRoute(): bool
    {
        try {
            if (Filament::getCurrentPanel()?->getId() !== 'server') {
                return false;
            }

            return request()->routeIs(static::getRouteName());
        } catch (Throwable) {
            return false;
        }
    }

    public function getTitle(): string
    {
        return trans('plugged-host-queue::strings.page_title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-queue::strings.page_subheading', [
            'server' => $this->getServer()->name,
        ]);
    }

    public function mount(): void
    {
        $server = $this->getServer();
        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->finish();

            return;
        }

        $entry = $this->entry();

        // No place in the line means there is nothing to wait for: the sweep released
        // it while the tab was closed, or somebody opened this URL directly.
        if (!$entry instanceof StartQueueEntry) {
            $this->finish();

            return;
        }

        // The server stopped being startable while the wait ran — suspended, put into
        // maintenance, or a transfer started (possibly the one offered below). The
        // entry is cancelled rather than left to time out and fire a power action at a
        // server that cannot take one.
        if ($this->serverConflicted($server)) {
            $this->queue()->cancel($entry);
            $this->notify('conflict', 'warning');
            $this->finish();

            return;
        }

        // A wait that was left and returned to can already be over, and this is also
        // the escape hatch if the poll never fires — a browser that suspends timers on
        // a background tab must not strand anyone here.
        //
        // The clock is read before the release attempt, so the properties are populated
        // even on the path that redirects straight back out.
        $this->syncClock($entry);
        $this->refreshTransferOffer();
        $this->attemptRelease($entry);
    }

    /**
     * Re-read the server clock into the published properties.
     *
     * Called from getViewData(), which runs on *every* render — dehydration happens after
     * render, so whatever this writes reaches the browser in the same response. Doing it
     * there rather than only in tick() means every other Livewire round trip this page has
     * (takeTransfer(), reportBlocked(), a re-render Filament decides to do) also carries a
     * current clock, so the client's x-effect can never be re-seeded with a stale number.
     *
     * `elapsedSeconds` is deliberately *not* capped at `maxSeconds`. The bar and the cap
     * note clamp themselves, but "Waiting 9:59" is a statement about how long this person
     * has actually been here, and it has to stay true in the seconds between the cap firing
     * and the release landing.
     *
     * The entry is passed in wherever the caller already holds one; `entry()` is a query.
     */
    protected function syncClock(?StartQueueEntry $entry = null): void
    {
        $queue = $this->queue();

        $this->maxSeconds = $queue->maxWait();
        $this->elapsedSeconds = $queue->elapsed($entry ?? $this->entry());
    }

    /** Called by wire:poll. Heartbeat, then release if it is time, then recount. */
    public function tick(): void
    {
        $entry = $this->entry();

        // Gone from the line: the sweep got there first. Nothing is owed here — the
        // sweep dispatched the start itself — so this is simply the way out.
        if (!$entry instanceof StartQueueEntry) {
            $this->finish();

            return;
        }

        $this->queue()->touch($entry);
        $this->attemptRelease($entry);
    }

    /** The client-side probe found our ad hosts unreachable. Recorded, never acted on. */
    public function reportBlocked(): void
    {
        $this->adsBlocked = true;
    }

    /**
     * Give up and go back to the console without starting anything.
     *
     * Worth a button of its own. Somebody who has decided they would rather come back
     * later should be able to leave the line properly, so the place they were holding
     * goes to whoever is behind them instead of sitting there until the sweep notices
     * the silence.
     */
    /**
     * Leaving gives up the server's queued start, so it takes the permission that can queue
     * one. Every user of the server is sent to this page while a start waits; a subuser
     * without it only watches.
     */
    public function canLeave(): bool
    {
        $server = $this->getServer();

        return (bool) user()?->can(SubuserPermission::ControlStart, $server)
            || (bool) user()?->can(SubuserPermission::ControlRestart, $server);
    }

    public function leave(): void
    {
        abort_unless($this->canLeave(), 403);

        $entry = $this->entry();

        if ($entry instanceof StartQueueEntry) {
            $this->queue()->cancel($entry);
        }

        $this->finish();
    }

    protected function attemptRelease(StartQueueEntry $entry): void
    {
        $queue = $this->queue();
        $reason = $queue->releaseReason($entry);

        if ($reason === null) {
            return;
        }

        // release() may still answer false — the sweep can claim the same entry in the
        // same second — and either way this wait is over.
        $queue->release($entry, $reason);

        $this->finish();
    }

    /**
     * Take the offer and move the server to a node that is not busy.
     *
     * The wait is cancelled rather than released, and that distinction matters: a
     * server whose data directory is copying between nodes cannot start, so dispatching
     * the power action here would fail and look like the queue had lost the request.
     * The user is told the transfer is running and sent to the Transfer page, where the
     * orchestrator in plugged-host-servers shows its progress and starts the server when it
     * lands. Without that plugin they go to the console and press Start themselves.
     */
    public function takeTransfer(): void
    {
        $user = $this->getUser();
        $server = $this->getServer();

        if (!$user instanceof User) {
            $this->finish();

            return;
        }

        $entry = $this->entry();
        $result = $this->transfers()->execute($server, $user);

        if ($result === NodeTransferService::RESULT_OK) {
            if ($entry instanceof StartQueueEntry) {
                $this->queue()->cancel($entry, StartQueueEntry::STATUS_CANCELLED, 'transfer');
            }

            $this->notify('transfer_started');

            $page = 'PluggedHost\\Servers\\Filament\\Server\\Pages\\ServerTransferPage';

            if ($this->transfers()->orchestrated() && class_exists($page)) {
                $this->redirect($page::getUrl(tenant: $server), navigate: false);

                return;
            }

            $this->finish();

            return;
        }

        match ($result) {
            NodeTransferService::RESULT_NO_NODE => $this->notify('transfer_no_node', 'warning'),
            NodeTransferService::RESULT_CONFLICT => $this->notify('transfer_conflict', 'warning'),
            NodeTransferService::RESULT_UNAVAILABLE => $this->notify('transfer_unavailable', 'warning'),
            default => $this->notify('transfer_failed', 'danger'),
        };

        // Whatever went wrong, the name on the button is no longer trustworthy.
        $this->refreshTransferOffer();
    }

    protected function refreshTransferOffer(): void
    {
        $this->transferNodeName = null;

        $transfers = $this->transfers();

        if (!$transfers->isOfferable($this->getServer(), $this->getUser())) {
            return;
        }

        $node = $transfers->target($this->getServer());

        if ($node !== null) {
            $this->transferNodeName = (string) $node->name;
        }
    }

    /**
     * Leave the page.
     *
     * A full navigation rather than a Livewire swap: the console needs a fresh
     * websocket handshake for the container that is now coming up, and this page must
     * not stay in the SPA history where a back button would land on a queue that has
     * already been served.
     */
    protected function finish(): void
    {
        $this->redirect(Console::getUrl(tenant: $this->getServer()), navigate: false);
    }

    protected function serverConflicted(Server $server): bool
    {
        try {
            return $server->isInConflictState();
        } catch (Throwable) {
            // Unanswerable is not the same as conflicted. Erring the other way would
            // throw people out of the line every time a node stopped answering.
            return false;
        }
    }

    public function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected function getUser(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function entry(): ?StartQueueEntry
    {
        return $this->queue()->waitingEntry($this->getServer());
    }

    protected function queue(): StartQueueService
    {
        return app(StartQueueService::class);
    }

    protected function passes(): PriorityPassService
    {
        return app(PriorityPassService::class);
    }

    protected function transfers(): NodeTransferService
    {
        return app(NodeTransferService::class);
    }

    protected function notify(string $key, string $type = 'success', array $replace = []): void
    {
        $notification = Notification::make()
            ->title(trans("plugged-host-queue::strings.notify_{$key}", $replace));

        match ($type) {
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->success(),
        };

        $notification->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $server = $this->getServer();
        $user = $this->getUser();
        $queue = $this->queue();
        $passes = $this->passes();
        $transfers = $this->transfers();
        $pressure = app(NodePressure::class);

        $entry = $this->entry();
        $this->syncClock($entry);

        return [
            'server' => $server,
            'poll' => self::POLL_INTERVAL,
            'entry' => $entry,
            'action' => (string) ($entry?->action ?? 'start'),
            'position' => $queue->position($entry),
            // The same numbers as $this->elapsedSeconds / $this->maxSeconds, kept as view
            // data because they are the server-rendered first paint: the client clock only
            // exists once Alpine has booted, and a wait that renders "Waiting 0:00" for a
            // frame reads as a queue that forgot you.
            'elapsed' => $this->elapsedSeconds,
            'remaining' => max(0, $this->maxSeconds - $this->elapsedSeconds),
            'maxWait' => $this->maxSeconds,
            'passMinutes' => $queue->passMinutes(),
            // Shown as a plain reason rather than a chart: "this node's memory is over
            // 95%" is something a customer can act on, and hiding why they are waiting
            // is how a capacity feature turns into a support ticket.
            'reason' => ((array) ($entry?->metadata ?? []))['pressure'] ?? $pressure->pressure($server->node),
            'usage' => $pressure->usage($server->node),
            'limit' => $pressure->limit(),
            'dimensions' => $pressure->dimensions(),
            // Priority comes with plugged.host Plus (1) and PlusPlus (2), sold in the Token Store.
            'priorityLevel' => $passes->level($user),
            'priorityActive' => $passes->isActive($user),
            'priorityAvailable' => $passes->available(),
            'plusCost' => $this->plusCost(),
            'storeUrl' => $this->storeUrl(),
            'transferNode' => $this->transferNodeName,
            'transferRequiresPriority' => $transfers->requiresPriority(),
            'transferEnabled' => $transfers->isEnabled(),
            'transferTooBig' => $transfers->isEnabled() && !$transfers->sizeAllows($server),
            'earnUrl' => $this->earnUrl(),
        ] + $this->adData();
    }

    /**
     * The plugged.host Plus page, where Plus (and PlusPlus) are sold, or null without economy.
     */
    protected function storeUrl(): ?string
    {
        $class = 'PluggedHost\\Economy\\Filament\\App\\Pages\\PlusPage';

        if (!class_exists($class)) {
            return null;
        }

        return $this->safely(fn () => $class::getUrl(panel: 'app'), null);
    }

    /**
     * What Plus costs, or 0 without economy.
     */
    protected function plusCost(): int
    {
        $class = 'PluggedHost\\Economy\\Services\\MembershipService';

        return class_exists($class) ? (int) $this->safely(fn () => app($class)->plusCost(), 0) : 0;
    }

    /**
     * The ad surfaces, borrowed from plugged-host-ads.
     *
     * The waiting page is meant to be full of them — it is the one screen where a user
     * is knowingly spending time in exchange for capacity somebody has to pay for — but
     * the queue does not depend on that plugin being installed. Every key here has a
     * defined empty value, and the view renders nothing rather than breaking when ads
     * is gone.
     *
     * `gateUnits()` and not `inContentUnits()`: this is the same kind of surface as the
     * ad gate's waiting room, with its own switches and its own extra zones, and
     * `QueuePluginProvider` registers this page as an interstitial so the generic
     * in-content slot stays out of the document. The same zone twice in one page splits
     * the fill and is what a network audits for.
     *
     * @return array<string, mixed>
     */
    protected function adData(): array
    {
        $adsterra = $this->adsService('PluggedHost\\Ads\\Services\\AdsterraService');
        $display = $this->adsService('PluggedHost\\Ads\\Services\\AdDisplayService');

        // Ads has to be *asked*, not just borrowed from. An ad-free pass holder can still
        // be standing in this line — the pass jumps the queue, it does not empty a node
        // that is full for everybody — and a page that paints the stack anyway would sell
        // somebody a quiet window and then show them the loudest screen in the panel.
        // `shouldDisplay()` rather than `isExemptUser()`, so the master switch and the
        // server-panel placement are honoured here exactly as they are everywhere else.
        $wanted = $display === null || $this->safely(
            fn () => (bool) $display->shouldDisplay($this->getUser(), 'server'),
            true,
        );

        return [
            'units' => $wanted ? $this->safely(fn () => (array) $adsterra?->gateUnits(), []) : [],
            'sandbox' => $this->safely(fn () => (string) $adsterra?->frameSandbox(), ''),
            'directLink' => $wanted ? $this->safely(fn () => $adsterra?->directLink(), null) : null,
            'probes' => $wanted ? $this->safely(fn () => (array) $display?->probeUrls(), []) : [],
            'hasAdPartial' => view()->exists('plugged-host-ads::filament.interstitial-units'),
        ];
    }

    /**
     * Link to the token earn page, which lives in the *app* panel.
     *
     * The whole point of showing it here: somebody who wants the priority window and
     * cannot afford it should be one click from the page that mints tokens. Null rather
     * than a broken link when ads is not installed.
     */
    protected function earnUrl(): ?string
    {
        $class = 'PluggedHost\\Economy\\Filament\\App\\Pages\\EarnTokensPage';

        if (!class_exists($class)) {
            return null;
        }

        return $this->safely(fn () => $class::getUrl(panel: 'app'), null);
    }

    /**
     * "2 days 4 hours" rather than 187,412 seconds.
     *
     * Two parts, because a window in its last hour still has to read as a window and
     * "1 day 23 hours 41 minutes 9 seconds" reads as a stopwatch.
     */
    protected function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        try {
            return CarbonInterval::seconds($seconds)->cascade()->forHumans(['parts' => 2]);
        } catch (Throwable) {
            return (string) $seconds;
        }
    }

    protected function adsService(string $class): mixed
    {
        if (!class_exists($class)) {
            return null;
        }

        return $this->safely(fn () => app($class), null);
    }

    /**
     * Run a lookup that is decoration, and fall back rather than take the page down.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    protected function safely(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
