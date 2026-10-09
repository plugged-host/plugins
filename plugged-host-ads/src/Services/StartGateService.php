<?php

namespace PluggedHost\Ads\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Ads\Models\StartGateEvent;
use Throwable;

/**
 * Decides who has to sit through the ad-funded waiting room before reaching a
 * server, and when they are allowed out of it.
 *
 * Three things shape this class.
 *
 * The enforceable moment is the Start click itself. Wave 1 assumed otherwise —
 * that a start only existed in the browser — and gated *navigation* into the
 * server panel instead, which is why customers hit an ad wall for opening a
 * running server's file manager. It is not true: upstream's console Start button
 * is a modal-less Filament action, so the click is a Livewire call to
 * `mountAction('start')` and the websocket event is dispatched *by* PHP at the end
 * of it. core's `StartChain` sits in front of that call, and the navigation
 * gate is now off by default (`start_credits.gate_on_navigation`).
 *
 * Every check has to fail open. `Server::retrieveStatus()` reaches out to wings
 * and does not catch `ConnectionException`, so a node outage would otherwise turn
 * a revenue feature into a lockout on the panel people use to find out why their
 * server is down. Anything unknown here resolves to "let them through".
 *
 * The dwell has to be server-authoritative. The countdown the user sees is
 * decoration; the only timestamp that matters is one held in the cache under a
 * key the browser never learns, so calling the release action early from devtools
 * returns without redirecting.
 *
 * The container starts on the click, not when the ads finish. `dispatchPower()` runs
 * before the redirect, so the wait is ad time rather than a queue: the server is
 * already booting behind the room and is usually up by the time the user is let out.
 * The accepted consequence is that abandoning the wait still gets you a started
 * server — the ad view is what is lost, not the start. An intent is only remembered
 * when that first dispatch failed.
 *
 * Nothing buys a way past this room. Tokens used to: a balance was spent silently at
 * the moment the gate would have appeared. That was removed on 2026-08-31 when tokens
 * became the start-queue currency instead — the two features are both waits, and a
 * currency that shortens one of them while the other charges for priority is a
 * currency nobody can reason about. The gate is now purely ad-funded and free.
 */
class StartGateService
{
    /**
     * How long an in-progress wait is remembered.
     *
     * Longer than max_dwell by a wide margin so a slow container or a tab that
     * was briefly backgrounded does not restart the clock, but short enough that
     * an abandoned wait is not still banked hours later.
     */
    public const HOLD_TTL_MINUTES = 5;

    /** Throttle window for outage logging and fail-open events. */
    protected const FAILOPEN_LOG_MINUTES = 5;

    public function __construct(
        protected AdDisplayService $ads,
        protected AdsterraService $adsterra,
    ) {}

    /**
     * The gate is on only if it was switched on, ads are on at all, and there is
     * actually an Adsterra unit to fill the waiting room with.
     *
     * That last condition is not paperwork. A waiting room with nothing in it
     * earns nothing and costs a customer thirty seconds, which is the worst trade
     * available — so an empty Adsterra configuration disables the gate outright
     * rather than producing one.
     */
    public function isEnabled(): bool
    {
        return (bool) config('plugged-host-ads.start_credits.enabled', false)
            && $this->ads->isEnabled()
            && $this->adsterra->isConfigured();
    }

    public function minDwell(): int
    {
        return max(0, (int) config('plugged-host-ads.start_credits.min_dwell', 10));
    }

    public function maxDwell(): int
    {
        return max($this->minDwell(), (int) config('plugged-host-ads.start_credits.max_dwell', 30));
    }

    public function passMinutes(): int
    {
        return max(0, (int) config('plugged-host-ads.start_credits.pass_minutes', 10));
    }

    /**
     * Should merely *entering* a gated server's panel raise the gate?
     *
     * Off by default, and that default is the wave-3 correction. Gating navigation
     * meant a customer whose server was already down could not reach their own
     * files, backups or settings without sitting through an ad first — the gate
     * fired on "clicked the server", not on "asked for the expensive thing".
     * core's `StartChain` now covers the Start click directly, which is both
     * narrower and harder to get around. Left as a switch because the wide net is
     * still the only thing that catches a start issued outside the panel.
     */
    public function gateOnNavigation(): bool
    {
        return (bool) config('plugged-host-ads.start_credits.gate_on_navigation', false);
    }

    /**
     * The power action this user was trying to send when the gate stopped them.
     *
     * Without this the waiting room is a dead end: the user pressed Start, watched
     * the ads, and would have to press Start again. The intent is kept server-side
     * under a key the browser never sees, so it cannot be used to make the panel
     * issue a power signal the user never asked for.
     */
    public function rememberStartIntent(User $user, Server $server, string $action): void
    {
        if (!in_array($action, ['start', 'restart'], true)) {
            return;
        }

        try {
            Cache::put($this->intentKey($user, $server), $action, now()->addMinutes(self::HOLD_TTL_MINUTES));
        } catch (Throwable) {
            // The gate still works without it; the user just presses Start again.
        }
    }

    /** Read and consume the pending intent. Consumed, so a reload cannot re-fire it. */
    public function takeStartIntent(User $user, Server $server): ?string
    {
        try {
            $key = $this->intentKey($user, $server);
            $action = Cache::get($key);
            Cache::forget($key);
        } catch (Throwable) {
            return null;
        }

        return in_array($action, ['start', 'restart'], true) ? $action : null;
    }

    /** Read the pending intent without consuming it. */
    public function peekStartIntent(User $user, Server $server): ?string
    {
        try {
            $action = Cache::get($this->intentKey($user, $server));
        } catch (Throwable) {
            return null;
        }

        return in_array($action, ['start', 'restart'], true) ? $action : null;
    }

    public function forgetStartIntent(User $user, Server $server): void
    {
        try {
            Cache::forget($this->intentKey($user, $server));
        } catch (Throwable) {
            // Intentionally silent.
        }
    }

    /**
     * Note that the start signal has already gone out for this wait.
     *
     * A separate key from the intent, and deliberately so: an intent is an action the
     * release still has to replay, this is proof that there is nothing left to replay.
     * One key holding both would make "already done" and "never asked" indistinguishable,
     * and the difference is exactly what the event row records — a room entered by
     * navigation, with no start behind it at all, is a third case again.
     */
    public function markDispatched(User $user, Server $server): void
    {
        try {
            Cache::put($this->dispatchKey($user, $server), 1, now()->addMinutes(self::HOLD_TTL_MINUTES));
        } catch (Throwable) {
            // Only the analytics label is lost.
        }
    }

    /** Read and clear the mark, so a second release cannot claim the same dispatch. */
    public function takeDispatchMark(User $user, Server $server): bool
    {
        try {
            $key = $this->dispatchKey($user, $server);
            $marked = Cache::has($key);
            Cache::forget($key);

            return $marked;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send a power signal on the user's behalf.
     *
     * Sent on the click, before the room is even raised: the waiting room is where
     * the start is paid for, not a queue the start sits in. Sent again at release
     * only if that first attempt failed, which is what `rememberStartIntent()` is
     * for.
     *
     * Re-authorised from scratch rather than trusted from the cache entry: the
     * subuser permission may have been revoked during the wait, and a cache key is
     * not an authorisation record. Returns whether wings accepted it — false is
     * survivable, because the console the user lands on has its own Start button.
     */
    public function dispatchPower(User $user, Server $server, string $action): bool
    {
        $permission = match ($action) {
            'start' => SubuserPermission::ControlStart,
            'restart' => SubuserPermission::ControlRestart,
            default => null,
        };

        if ($permission === null) {
            return false;
        }

        try {
            if (!$user->can($permission, $server)) {
                return false;
            }

            if ($server->isInConflictState()) {
                return false;
            }

            app(DaemonServerRepository::class)->setServer($server)->power($action);

            return true;
        } catch (Throwable $e) {
            // A node that is unreachable now was reachable enough to gate the
            // click a moment ago; either way the user is on the console and can
            // press Start themselves.
            $this->throttledLog('plugged-host-ads: deferred power action failed', $e);

            return false;
        }
    }

    /**
     * Is this server bigger than the Starter plan?
     *
     * The thresholds default to exactly plugged-host-core's `starter` preset — the
     * lowest of the six — so the answer is "did someone take more than the smallest
     * plan we hand out". Every preset is free; the gate is about size, not payment.
     */
    public function isGatedServer(Server $server): bool
    {
        return $this->exceeds($server->cpu, 'cpu')
            || $this->exceeds($server->memory, 'memory')
            || $this->exceeds($server->disk, 'disk');
    }

    /**
     * Whether one resource limit is over its threshold.
     *
     * The `=== 0` arm is the whole reason this is a method. On a Pelican server 0
     * means *unlimited*, and `0 > 2048` is false — so a naive comparison would
     * wave through the single most expensive server shape there is.
     */
    protected function exceeds(mixed $value, string $key): bool
    {
        $value = (int) $value;
        $threshold = max(0, (int) config("plugged-host-ads.start_credits.$key", 0));

        return $value === 0 || $value > $threshold;
    }

    /**
     * Does this user, on this server, right now, have to see the waiting room?
     *
     * Ordered cheapest-first, so the common case — the feature is off, or the
     * server is a Starter-sized one — costs a couple of config reads and no I/O. The
     * daemon round trip is last and only happens for a user who is otherwise
     * about to be gated.
     *
     * `$requireStopped` is what makes **Restart** gate. A restart is a start with
     * an extra step — it takes the container down and brings it back up — so the
     * container being up at the moment the button is pressed is not a reason to
     * wave it through. Callers that are reacting to an explicit restart pass false
     * here; navigation into a server panel keeps the default, because a running
     * server nobody asked to restart is not a start at all.
     */
    public function requiresGate(?User $user, mixed $server, bool $requireStopped = true): bool
    {
        try {
            if (!$user || !$server instanceof Server) {
                return false;
            }

            if (!$this->isEnabled() || !$this->isGatedServer($server)) {
                return false;
            }

            if ($this->ads->isExemptUser($user)) {
                return false;
            }

            // An unfinished install belongs to plugged-host-core's installing
            // page, and a suspended, transferring, restoring or maintenance
            // server has its own handling. Two takeovers competing for the same
            // request is a redirect loop.
            if (!$server->isInstalled() || $server->isInConflictState()) {
                return false;
            }

            if ($this->hasPass($user, $server)) {
                return false;
            }

            // An explicit restart is gated whatever the container is doing, and
            // the daemon is not asked — there is nothing its answer could change.
            if (!$requireStopped) {
                return true;
            }

            $up = $this->containerIsUp($server);

            if ($up === null) {
                $this->recordFailOpen($user, $server);

                return false;
            }

            return !$up;
        } catch (Throwable $e) {
            // Nothing about collecting ad revenue justifies a 500 on the page
            // someone opened to rescue their server.
            $this->throttledLog('plugged-host-ads: start gate check failed, allowing request', $e);

            return false;
        }
    }

    /**
     * `true` up, `false` down, `null` we could not find out.
     *
     * Callers must treat null as "let them through" rather than folding it into
     * false — the difference between a stopped container and an unreachable node
     * is the difference between a paywall and an outage.
     */
    public function containerIsUp(Server $server): ?bool
    {
        try {
            return $server->retrieveStatus()->isStartingOrRunning();
        } catch (Throwable) {
            return null;
        }
    }

    public function hasPass(User $user, Server $server): bool
    {
        try {
            return Cache::has($this->passKey($user, $server));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Open the grace window after the gate has been satisfied.
     *
     * Cache rather than a table: this churns once per start per user, and losing
     * one to `optimize:clear` costs a single extra ad view rather than corrupting
     * anything.
     *
     * `$minutes` overrides the configured window for callers that need a floor rather
     * than a setting. plugged-host-queue is the one that does: a user who has just
     * waited out the start queue must not land on the ad gate as well, so the queue
     * opens a pass on release even when pass_minutes is 0.
     */
    public function openPass(User $user, Server $server, string $reason, ?int $minutes = null): void
    {
        $minutes = $minutes ?? $this->passMinutes();

        if ($minutes <= 0) {
            return;
        }

        try {
            Cache::put($this->passKey($user, $server), $reason, now()->addMinutes($minutes));
        } catch (Throwable) {
            // A pass that could not be stored means one more waiting room later,
            // which is not worth failing the release the user just earned.
        }
    }

    /**
     * The unix timestamp this wait started, set on first ask.
     *
     * `add` rather than `put`: it is a single atomic SETNX on redis, so two tabs
     * mounting the page at once cannot reset each other's clock.
     */
    public function holdStartedAt(User $user, Server $server): int
    {
        $key = $this->holdKey($user, $server);
        $now = now()->getTimestamp();

        try {
            Cache::add($key, $now, now()->addMinutes(self::HOLD_TTL_MINUTES));

            $stored = Cache::get($key, $now);
        } catch (Throwable) {
            return $now;
        }

        return is_numeric($stored) ? (int) $stored : $now;
    }

    public function elapsed(User $user, Server $server): int
    {
        return max(0, now()->getTimestamp() - $this->holdStartedAt($user, $server));
    }

    /**
     * Is a wait already open for this pair?
     *
     * A read and never a write, which `holdStartedAt()` cannot be: the page asks this
     * *before* it knows whether the visitor belongs here, and a question that creates
     * the clock would make merely opening the URL look like a wait in progress.
     */
    public function hasHold(User $user, Server $server): bool
    {
        try {
            return Cache::has($this->holdKey($user, $server));
        } catch (Throwable) {
            return false;
        }
    }

    public function clearHold(User $user, Server $server): void
    {
        try {
            Cache::forget($this->holdKey($user, $server));
        } catch (Throwable) {
            // Worst case the next wait inherits a spent clock, which shortens it.
        }
    }

    /**
     * May this wait end?
     *
     * Released as soon as the container is coming up, but never before min_dwell —
     * otherwise a server that boots in two seconds would show no ad at all, and
     * the mechanic would collect nothing from exactly the servers people restart
     * most. Never held past max_dwell, because a container that refuses to start
     * is not the user's fault.
     */
    public function releaseAllowed(User $user, Server $server): bool
    {
        $elapsed = $this->elapsed($user, $server);

        if ($elapsed >= $this->maxDwell()) {
            return true;
        }

        if ($elapsed < $this->minDwell()) {
            return false;
        }

        // null (node unreachable) deliberately does not release early: the user
        // still leaves at max_dwell a moment later, and this keeps a flaky node
        // from becoming a way to skip the wait.
        return $this->containerIsUp($server) === true;
    }

    /**
     * Finish a wait: log what happened, open the grace window, drop the clock.
     */
    public function release(User $user, Server $server, string $outcome, array $metadata = []): void
    {
        $this->recordEvent($user, $server, $outcome, $this->elapsed($user, $server), $metadata);
        $this->openPass($user, $server, $outcome);
        $this->clearHold($user, $server);
    }

    /**
     * Release a user from an active hold: replay a deferred start only if still owed,
     * log the outcome, open the grace pass, and clear hold markers.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function releaseFromHold(User $user, Server $server, string $outcome = StartGateEvent::OUTCOME_ADS, array $metadata = []): void
    {
        $dispatchedOnClick = $this->takeDispatchMark($user, $server);
        $intent = $this->takeStartIntent($user, $server);

        if ($dispatchedOnClick) {
            $metadata['dispatched'] = 'click';
        } elseif ($intent !== null) {
            $metadata['dispatched'] = 'release';
            $metadata['intent'] = $intent;
            $metadata['started'] = $this->dispatchPower($user, $server, $intent);
        } else {
            $metadata['dispatched'] = 'none';
        }

        $this->release($user, $server, $outcome, $metadata);
    }

    /**
     * Append to the analytics table.
     *
     * Swallows everything. These rows exist to tell us whether the dwell is set
     * right; none of them is worth failing a release the user has already earned.
     */
    protected static ?bool $hasEventsTable = null;

    public function recordEvent(User $user, Server $server, string $outcome, int $dwellSeconds = 0, array $metadata = []): void
    {
        try {
            if (self::$hasEventsTable === null) {
                self::$hasEventsTable = Schema::hasTable('ads_start_gate_events');
            }

            if (!self::$hasEventsTable) {
                return;
            }

            StartGateEvent::create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'outcome' => $outcome,
                'dwell_seconds' => max(0, $dwellSeconds),
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Intentionally silent.
        }
    }

    /**
     * A fail-open is worth knowing about but not worth a row per request: while a
     * node is unreachable this path runs on every page load of every server on
     * it, so it is throttled to one event per user and server per window.
     */
    protected function recordFailOpen(User $user, Server $server): void
    {
        try {
            if (!Cache::add("ph:start-failopen:{$user->id}:{$server->id}", 1, now()->addMinutes(self::FAILOPEN_LOG_MINUTES))) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $this->recordEvent($user, $server, StartGateEvent::OUTCOME_FAILOPEN, 0, [
            'reason' => 'container_status_unavailable',
        ]);
    }

    protected function throttledLog(string $message, Throwable $e): void
    {
        try {
            if (!Cache::add('ph:start-gate-error-log', 1, now()->addMinutes(self::FAILOPEN_LOG_MINUTES))) {
                return;
            }

            Log::warning($message, ['exception' => $e->getMessage()]);
        } catch (Throwable) {
            // Intentionally silent.
        }
    }

    protected function passKey(User $user, Server $server): string
    {
        return "ph:start-pass:{$user->id}:{$server->id}";
    }

    protected function holdKey(User $user, Server $server): string
    {
        return "ph:start-hold:{$user->id}:{$server->id}";
    }

    protected function intentKey(User $user, Server $server): string
    {
        return "ph:start-intent:{$user->id}:{$server->id}";
    }

    protected function dispatchKey(User $user, Server $server): string
    {
        return "ph:start-dispatched:{$user->id}:{$server->id}";
    }
}
