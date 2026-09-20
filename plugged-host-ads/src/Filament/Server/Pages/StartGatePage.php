<?php

namespace PluggedHost\Ads\Filament\Server\Pages;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use PluggedHost\Economy\Filament\App\Pages\EarnTokensPage;
use PluggedHost\Ads\Models\StartGateEvent;
use PluggedHost\Ads\Services\AdDisplayService;
use PluggedHost\Ads\Services\AdsterraService;
use PluggedHost\Ads\Services\StartGateService;
use PluggedHost\Economy\Services\TokenEarnService;
use Throwable;

/**
 * The waiting room a user sits in before reaching an oversized server.
 *
 * Reached by pressing Start or Restart, not by opening a server:
 * `PowerActionInterceptor` cancels the Livewire call that would have reached wings —
 * and then sends that power signal itself, before raising this page. So the container
 * is already coming up behind the room, and the room is the ad time the start is paid
 * with rather than a queue the start sits in. The user pays with attention while their
 * server boots; closing the tab costs the impression, not the start.
 *
 * `attemptRelease()` still owes a start in exactly one case: the interceptor's own
 * dispatch failed (wings down, node gone) and left the requested action behind as an
 * intent. Then the release replays it, so a failed dispatch cannot cost someone both
 * the wait and the start.
 *
 * That is also why mount() cannot ask `requiresGate()` on its own who belongs here.
 * It asks three things in order — is a wait already open, is a start still owed, and
 * only then would this pair be gated at all. Restart is why the order matters: the
 * container is up during a gated restart, so the plain gate check would say there is
 * nothing to gate and bounce the user to a console in the middle of their wait.
 *
 * Modelled on plugged-host-core's ServerInstallingPage, including the parts that
 * look like accidents and are not:
 *
 * `canAccess()` deliberately does not consult the gate. Filament re-runs it on
 * every Livewire request, so a state check there would 403 the very poll that
 * releases the user — the one moment this page exists for. The state checks live in
 * mount() and in the interceptor.
 *
 * The release is server-side only. `tick()` asks StartGateService, which compares
 * against a timestamp held in the cache under a key the browser never sees. Calling
 * `$wire.tick()` from devtools at second one returns without redirecting; the
 * countdown on screen is decoration.
 *
 * Ad-blocked users are released on the same terms as everyone else. An empty
 * waiting room earns nothing, but refusing to let someone reach their own server
 * over a browser extension turns an extension into a support ticket — the same
 * reasoning as `antiblock.skip_console`. What we do instead is record that the
 * probe failed, so the real fill rate is a number rather than a guess.
 *
 * There is no way to buy past this room, and so there is no skip button on it. Tokens
 * bought one until 2026-08-31; they now buy priority in the capacity start queue
 * instead, which is a different wait for a different reason. The only relief from
 * inside the room is the grace pass `release()` opens on the way out, so a
 * crash-restart loop inside `pass_minutes` is free — and plugged-host-queue opens that
 * same pass when it releases a start, so a user who has just waited for capacity does
 * not then wait for ads.
 */
class StartGatePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-hourglass-high';

    protected static ?string $slug = 'start';

    protected string $view = 'plugged-host-ads::filament.pages.start-gate';

    /**
     * Tighter than the installing page's 5 s: a floor of 10 s needs finer
     * resolution than that, or a release lands up to half its length late.
     */
    public const POLL_INTERVAL = '2s';

    /** Set by the client-side probe. Analytics only — it never gates the release. */
    public bool $adsBlocked = false;

    /**
     * The server's own clock, published so the browser can tick between polls.
     *
     * These two exist as *properties* rather than getViewData() keys for one reason: the
     * countdown used to be rendered server-side, so it moved in POLL_INTERVAL steps — two
     * seconds at a time, which reads as a broken clock rather than a wait. A public property
     * is in the Livewire snapshot, so `$wire.elapsedSeconds` is readable from Alpine, and the
     * view runs a 1 Hz client clock that re-seeds from these on every poll (see the x-effect
     * in start-gate.blade.php). Without the re-seed a client clock would drift away from the
     * server's forever, and the server's is the one that decides.
     *
     * Still decoration: releaseAllowed() is the only thing that ends the wait.
     */
    public int $elapsedSeconds = 0;

    public int $maxSeconds = 0;

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Server && parent::canAccess();
    }

    /** Never in the sidebar. Nobody navigates *to* a waiting room. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Whether the request being rendered is this page.
     *
     * Used by AdsPluginServiceProvider to suppress the generic in-content slot
     * here: the gate renders its own unit stack, and serving the same Adsterra
     * zone twice in one document splits the fill and is exactly the kind of thing
     * a network audits for.
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
        return trans('plugged-host-ads::strings.start_gate_title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-ads::strings.start_gate_subheading', [
            'server' => $this->getServer()->name,
        ]);
    }

    public function mount(): void
    {
        $server = $this->getServer();
        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->redirect(Console::getUrl(tenant: $server), navigate: false);

            return;
        }

        $gate = $this->gate();

        // Who belongs here, in the only order that works.
        //
        // An open hold means this user's wait is already running — the interceptor
        // opened it moments ago, or they came back to a tab inside the TTL. A pending
        // intent means a start is still owed because the interceptor's dispatch
        // failed. Only when neither is true is `requiresGate()` the right question,
        // and asking it first would break the gated restart: the container is up, so
        // it would answer "nothing to gate" and bounce the user to the console
        // without their restart ever going out.
        //
        // Both reads are reads. `hasHold()` cannot be `holdStartedAt()` here, or
        // merely opening this URL would look like a wait in progress; the intent is
        // peeked and not taken, because `finish()` is what consumes it.
        if (!$gate->hasHold($user, $server)
            && $gate->peekStartIntent($user, $server) === null
            && !$gate->requiresGate($user, $server)) {
            $this->redirect(Console::getUrl(tenant: $server), navigate: false);

            return;
        }

        // Starts the server-side clock, or re-reads the one already running. Must
        // happen before the first release check or elapsed would always be zero.
        $gate->holdStartedAt($user, $server);

        // Before attemptRelease(), so the properties are populated even on the path that
        // redirects straight back out.
        $this->syncClock();

        // A wait that was abandoned and returned to inside the hold TTL can already
        // be over. Also the escape hatch if the poll never fires — a browser that
        // suspends timers on a background tab must not strand anyone here.
        $this->attemptRelease(StartGateEvent::OUTCOME_ADS);
    }

    /**
     * Re-read the server clock into the published properties.
     *
     * Called from getViewData(), which runs on *every* render — dehydration happens after
     * render, so whatever this writes reaches the browser in the same response. Doing it there
     * rather than only in tick() means any other Livewire round trip (reportBlocked(), a
     * re-render Filament decides to do) also carries a current clock, so the client's
     * x-effect can never be re-seeded with a stale number.
     */
    protected function syncClock(): void
    {
        $user = $this->getUser();
        $gate = $this->gate();

        $this->maxSeconds = $gate->maxDwell();
        $this->elapsedSeconds = $user instanceof User
            ? min($this->maxSeconds, $gate->elapsed($user, $this->getServer()))
            : 0;
    }

    /**
     * Called by wire:poll. Returns silently until the dwell is satisfied.
     */
    public function tick(): void
    {
        $this->attemptRelease(StartGateEvent::OUTCOME_ADS);
    }

    /**
     * The client-side probe found our ad hosts unreachable.
     *
     * Recorded on the release event rather than acted on, so the fill rate is
     * measurable without the page ever refusing to let someone through.
     */
    public function reportBlocked(): void
    {
        $this->adsBlocked = true;
    }

    protected function attemptRelease(string $outcome): void
    {
        $server = $this->getServer();
        $user = $this->getUser();

        if (!$user instanceof User) {
            return;
        }

        $gate = $this->gate();

        if (!$gate->releaseAllowed($user, $server)) {
            return;
        }

        $this->finish($user, $server, $outcome, $this->adsBlocked ? ['blocked' => true] : []);
    }

    /**
     * End the wait: replay a start only if one is still owed, log the outcome, open
     * the grace window, and leave.
     *
     * Usually there is nothing to send. The interceptor dispatched on the click and
     * left a mark saying so, and this reads `dispatched => 'click'` on the event row.
     * `'release'` means that first attempt failed and the intent is going out now;
     * `'none'` means no power action was ever behind this room — a navigation-raised
     * gate. Keeping the three apart is the whole reason the mark is its own cache key,
     * and it is what makes "how often does the click-time dispatch fail?" a query
     * rather than a guess.
     *
     * `release()` runs before the redirect so the console the user lands on cannot
     * bounce them straight back here.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function finish(User $user, Server $server, string $outcome, array $metadata = []): void
    {
        $this->gate()->releaseFromHold($user, $server, $outcome, $metadata);

        // A full navigation rather than a Livewire swap: the console needs a fresh
        // websocket handshake, and this page must not stay in the SPA history where
        // a back button would land on a gate that has already been paid for.
        $this->redirect(Console::getUrl(tenant: $server), navigate: false);
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

    protected function gate(): StartGateService
    {
        return app(StartGateService::class);
    }

    protected function tokens(): TokenEarnService
    {
        return app(TokenEarnService::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $server = $this->getServer();
        $user = $this->getUser();
        $gate = $this->gate();
        $adsterra = app(AdsterraService::class);
        $ads = app(AdDisplayService::class);

        $this->syncClock();

        return [
            'server' => $server,
            'poll' => self::POLL_INTERVAL,
            'minDwell' => $gate->minDwell(),
            // The same numbers as $this->maxSeconds / $this->elapsedSeconds, kept as view data
            // because they are the server-rendered first paint: the client clock only exists
            // once Alpine has booted, and a wait that renders "in 0 s" for a frame reads as a
            // release that did not happen.
            'maxDwell' => $this->maxSeconds,
            'elapsed' => $this->elapsedSeconds,
            'remaining' => max(0, $this->maxSeconds - $this->elapsedSeconds),
            'passMinutes' => $gate->passMinutes(),
            'balance' => $this->tokens()->balance($user),
            // gateUnits() and not inContentUnits(): the waiting room has its own set
            // of switches and can carry extra banner zones the rest of the panel does
            // not. Its top-document scripts are rendered by AdsPluginServiceProvider's
            // BODY_END hook, not from here — a script tag inside a wire:poll'd
            // component would be re-evaluated on every tick.
            'units' => $adsterra->gateUnits(),
            'sandbox' => $adsterra->frameSandbox(),
            'directLink' => $adsterra->directLink(),
            // Probed unconditionally, not only when the anti-block notice is on: the
            // point here is not to nag anyone, it is to know what share of waiting
            // rooms actually earn anything. An empty list makes the check a no-op.
            'probes' => $ads->probeUrls(),
            'earnUrl' => $this->earnUrl(),
        ];
    }

    /**
     * Link to the earn page, which lives in the *app* panel.
     *
     * Null rather than a broken link if the app panel cannot resolve it — this is a
     * nice-to-have on a page whose job is to release the user.
     */
    protected function earnUrl(): ?string
    {
        try {
            if (!$this->tokens()->isEarnable()) {
                return null;
            }

            return EarnTokensPage::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
