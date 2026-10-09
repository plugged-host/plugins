<?php

namespace PluggedHost\Queue\Services;

use App\Enums\SubuserPermission;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Queue\Filament\Server\Pages\StartQueuePage;
use PluggedHost\Queue\Models\StartQueueEntry;
use Throwable;

/**
 * The line itself: who joins it, who leaves it, and when.
 *
 * The enforceable moment is the Start click, and plugged-host-ads already owns the
 * one Livewire `before('call')` hook that can cancel it — Livewire runs every such
 * listener regardless of who returns early, so two interceptors would both act on the
 * same cancelled start. Ads therefore asks us first, by string class name, and
 * `interceptStart()` is that whole contract: a URL means "this start is ours now and
 * we will dispatch it when the node has room", null means "not our business, carry
 * on". Everything below is either producing that answer or honouring it.
 *
 * Two things shape the rest.
 *
 * The user is not required to stay. Unlike the ad gate — which sends the power signal
 * on the click and uses its waiting room purely to collect the impression — a queued
 * start has not happened yet, so a closed tab would silently cost somebody their
 * server. `QueueSweepCommand` therefore releases and dispatches on the user's behalf,
 * and the waiting page is a view of state the scheduler maintains rather than the
 * thing that maintains it.
 *
 * Every check fails open. A node that cannot be measured, a missing plugged-host-core,
 * an unreadable table: all of them answer "let them through". A capacity feature that
 * turns a monitoring outage into a fleet-wide lockout is worse than the pressure it
 * was protecting against.
 */
class StartQueueService
{
    /** Released because the node came back under the line and it was this entry's turn. */
    public const REASON_CAPACITY = 'capacity';

    /** Released because `max_wait` ran out. Pressure is our problem, not the customer's. */
    public const REASON_TIMEOUT = 'timeout';

    /** Released by hand from the admin area, or by a `--release` sweep. */
    public const REASON_FORCED = 'forced';

    protected const PASS_PREFIX = 'ph:queue-pass:';

    protected const NODE_RELEASE_PREFIX = 'ph:queue-node-release:';

    protected const ADS_GATE = 'PluggedHost\\Ads\\Services\\StartGateService';

    protected const ADS_DISPLAY = 'PluggedHost\\Ads\\Services\\AdDisplayService';

    public function __construct(
        protected NodePressure $pressure,
        protected PriorityPassService $passes,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('plugged-host-queue.enabled', true);
    }

    /**
     * Which power actions can be held.
     *
     * @return array<int, string>
     */
    public function actions(): array
    {
        return (bool) config('plugged-host-queue.include_restart', true)
            ? ['start', 'restart']
            : ['start'];
    }

    public function minWait(): int
    {
        return max(0, (int) config('plugged-host-queue.min_wait', 10));
    }

    /** Never below the floor: a max under min would release before the page had rendered. */
    public function maxWait(): int
    {
        return max($this->minWait(), (int) config('plugged-host-queue.max_wait', 600));
    }

    public function releaseInterval(): int
    {
        return max(0, (int) config('plugged-host-queue.release_interval', 20));
    }

    public function staleAfter(): int
    {
        return max(0, (int) config('plugged-host-queue.stale_after', 180));
    }

    public function passMinutes(): int
    {
        return max(0, (int) config('plugged-host-queue.pass_minutes', 10));
    }

    /**
     * The contract plugged-host-ads calls. A URL takes the start; null leaves it alone.
     *
     * The order of the refusals is the interesting part. Authorisation comes before
     * anything else because a subuser without ControlStart must get upstream's error
     * and not a waiting page for a start that was never going to happen. The conflict
     * check comes before the measurement for the same reason: a suspended server's
     * Start press has a correct answer already, and it is not "please wait".
     *
     * The URL is built before the row is written, so a page that cannot be routed
     * (panel not registered, plugin half-installed) means the start goes through
     * rather than vanishing into a queue nobody can see.
     */
    public function interceptStart(User $user, Server $server, string $action): ?string
    {
        if (!$this->isEnabled() || !in_array($action, $this->actions(), true)) {
            return null;
        }

        if (!$this->authorised($user, $server, $action)) {
            return null;
        }

        if ($this->skips($user)) {
            return null;
        }

        // Already in line: hand back the same page rather than judging the node
        // again. Pressing Start in a second tab must not create a second place, and
        // the unique index on `server_id` would not let it anyway.
        $existing = $this->waitingEntry($server);

        if ($existing instanceof StartQueueEntry) {
            $url = $this->pageUrl($server);

            if ($url === null) {
                return null;
            }

            $this->refresh($existing, $user, $server, $action);

            return $url;
        }

        if ($this->hasPass($user, $server)) {
            return null;
        }

        try {
            if ($server->isInConflictState()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $node = $server->node;

        if (!$node instanceof Node) {
            return null;
        }

        $url = $this->pageUrl($server);

        if ($url === null) {
            return null;
        }

        return $this->enqueue($user, $server, $node, $action) instanceof StartQueueEntry
            ? $url
            : null;
    }

    /** Whether a start is paced on a node whose utilisation cannot be read (WP11). */
    public function pacedOnUnavailable(): bool
    {
        return (bool) config('plugged-host-queue.paced_on_unavailable', true);
    }

    /**
     * Is anyone already waiting in line for this node? (WP11 line preservation.)
     *
     * When a line has formed, a fresh non-exempt click joins the back of it rather than being
     * let straight through on a momentary dip in node load — which is how starts used to jump
     * ahead of servers already waiting.
     */
    public function hasWaitingQueue(Node $node): bool
    {
        try {
            return StartQueueEntry::query()
                ->where('node_id', $node->getKey())
                ->where('status', StartQueueEntry::STATUS_WAITING)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** This server's live place in a line, or null when it is not standing in one. */
    public function waitingEntry(?Server $server): ?StartQueueEntry
    {
        if (!$server instanceof Server) {
            return null;
        }

        try {
            return StartQueueEntry::query()
                ->waiting()
                ->where('server_id', $server->getKey())
                ->first();
        } catch (Throwable $e) {
            $this->log('queue lookup failed', $e);

            return null;
        }
    }

    /**
     * Take a place in the line, reusing this server's row.
     *
     * `updateOrCreate` against the unique `server_id`, so the row a previous wait left
     * behind as `released` becomes this wait rather than a second row. Two clicks that
     * land together still race — the loser gets a constraint violation, and re-reading
     * is the correct resolution because the winner wrote the place we would have.
     */
    public function enqueue(User $user, Server $server, Node $node, string $action): ?StartQueueEntry
    {
        $attributes = [
            'user_id' => $user->getKey(),
            'node_id' => $node->getKey(),
            'action' => $action,
            'status' => StartQueueEntry::STATUS_WAITING,
            'priority' => $this->passes->level($user),
            'queued_at' => now(),
            'last_seen_at' => now(),
            'released_at' => null,
            'metadata' => ['pressure' => $this->pressure->pressure($node)],
        ];

        try {
            $entry = StartQueueEntry::query()->updateOrCreate(
                ['server_id' => $server->getKey()],
                $attributes,
            );

            // WP7/WP11: a queued start is a mutation worth an activity row.
            try {
                \App\Facades\Activity::event('server:queue.enqueue')
                    ->subject($server)
                    ->property('node', $node->getKey())
                    ->property('pressure', $this->pressure->pressure($node))
                    ->log();
            } catch (Throwable) {
                // Activity is best-effort; never fail a queue insert over a log row.
            }

            return $entry;
        } catch (QueryException $e) {
            $this->log('queue insert raced, re-reading', $e);

            return $this->waitingEntry($server);
        } catch (Throwable $e) {
            $this->log('queue insert failed', $e);

            return null;
        }
    }

    /**
     * Keep an existing place current without moving it.
     *
     * `queued_at` is never touched here: a second Start press is not a new arrival,
     * and re-stamping it would send the user to the back of the line they are already
     * in. The action is, because the second press may have been Restart.
     */
    protected function refresh(StartQueueEntry $entry, User $user, Server $server, string $action): void
    {
        try {
            $entry->action = $action;
            $entry->node_id = (int) $server->node_id;
            $entry->last_seen_at = now();

            // Plus or PlusPlus bought while waiting moves the place up, never down.
            $entry->priority = max((int) $entry->priority, $this->passes->level($user));

            $entry->save();
        } catch (Throwable $e) {
            $this->log('queue refresh failed', $e);
        }
    }

    /** Heartbeat from the waiting page's poll. The sweep reads it and nothing else does. */
    public function touch(?StartQueueEntry $entry): void
    {
        if (!$entry instanceof StartQueueEntry) {
            return;
        }

        try {
            $entry->forceFill(['last_seen_at' => now()])->save();
        } catch (Throwable $e) {
            $this->log('queue heartbeat failed', $e);
        }
    }

    /** 1 for the front of the line. Counted, not stored — the line changes under people. */
    public function position(?StartQueueEntry $entry): int
    {
        if (!$entry instanceof StartQueueEntry) {
            return 0;
        }

        try {
            return $entry->precedingCount() + 1;
        } catch (Throwable $e) {
            $this->log('queue position count failed', $e);

            return 1;
        }
    }

    /** How long this entry has been waiting, from the server's clock and not the browser's. */
    public function elapsed(?StartQueueEntry $entry): int
    {
        if (!$entry instanceof StartQueueEntry || $entry->queued_at === null) {
            return 0;
        }

        return max(0, now()->getTimestamp() - $entry->queued_at->getTimestamp());
    }

    /** Seconds until the hard cap fires. The one number on the page that is a promise. */
    public function remaining(?StartQueueEntry $entry): int
    {
        return max(0, $this->maxWait() - $this->elapsed($entry));
    }

    /**
     * Why this entry may go now, or null because it may not.
     *
     * The checks are ordered by cost, which happens also to be the order that reads
     * best: the cap first because it overrides everything, then the floor, then the
     * measurement, then the two that keep a cooled node from being flooded — one
     * release per `release_interval`, and only ever to whoever is actually at the
     * front.
     */
    public function releaseReason(?StartQueueEntry $entry): ?string
    {
        if (!$entry instanceof StartQueueEntry || !$entry->isWaiting()) {
            return null;
        }

        $elapsed = $this->elapsed($entry);

        if ($elapsed >= $this->maxWait()) {
            return self::REASON_TIMEOUT;
        }

        if ($elapsed < $this->minWait()) {
            return null;
        }

        if ($this->pressure->isUnderPressure($entry->node)) {
            return null;
        }

        if (!$this->nodeSlotFree((int) $entry->node_id)) {
            return null;
        }

        return $entry->precedingCount() === 0 ? self::REASON_CAPACITY : null;
    }

    /**
     * Let this entry out and send its power action.
     *
     * The status is claimed with a conditional UPDATE before anything else happens,
     * and a claim that changes no rows is a release somebody else already did. That is
     * not a rare case: the waiting page polls every two seconds and the sweep runs
     * every minute, so both can decide the same entry is ready inside the same second.
     * Whoever writes the row first sends the start; the other returns false and does
     * nothing. Without this the user's container gets two power signals.
     */
    public function release(?StartQueueEntry $entry, string $reason): bool
    {
        if (!$entry instanceof StartQueueEntry || !$entry->isWaiting()) {
            return false;
        }

        try {
            $claimed = StartQueueEntry::query()
                ->whereKey($entry->getKey())
                ->where('status', StartQueueEntry::STATUS_WAITING)
                ->update([
                    'status' => StartQueueEntry::STATUS_RELEASED,
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            $this->log('queue release claim failed', $e);

            return false;
        }

        if ((int) $claimed !== 1) {
            return false;
        }

        $entry->status = StartQueueEntry::STATUS_RELEASED;
        $entry->released_at = now();

        $user = $entry->user;
        $server = $entry->server;

        // The node is stamped even for a timeout release. The entry ignored the
        // interval when it was *decided*, but a container went onto that node all the
        // same, and the next capacity release should still give it its breathing room.
        $this->markNodeReleased((int) $entry->node_id);

        if (!$user instanceof User || !$server instanceof Server) {
            // The row survived its user or server going away. Released is still the
            // right status — there is simply nothing left to start.
            return true;
        }

        // Before the dispatch, not after: the console the user is about to land on
        // must not bounce them into the ad gate for the start they just waited out.
        $this->openPasses($user, $server);

        $started = $this->dispatchPower($user, $server, (string) $entry->action);

        $this->recordOutcome($entry, $reason, $started);

        // WP7/WP11: release and timeout are both mutations worth an activity row.
        try {
            \App\Facades\Activity::event($reason === self::REASON_TIMEOUT ? 'server:queue.timeout' : 'server:queue.release')
                ->actor($user)
                ->subject($server)
                ->property('reason', $reason)
                ->property('waited_seconds', $this->elapsed($entry))
                ->log();
        } catch (Throwable) {
            // best-effort
        }

        return true;
    }

    /**
     * Leave the line without starting anything: the user gave up, took the transfer,
     * or the server stopped being startable while they waited.
     */
    public function cancel(?StartQueueEntry $entry, string $status = StartQueueEntry::STATUS_CANCELLED, ?string $note = null): bool
    {
        if (!$entry instanceof StartQueueEntry || !$entry->isWaiting()) {
            return false;
        }

        try {
            $claimed = StartQueueEntry::query()
                ->whereKey($entry->getKey())
                ->where('status', StartQueueEntry::STATUS_WAITING)
                ->update([
                    'status' => $status,
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            $this->log('queue cancel failed', $e);

            return false;
        }

        if ((int) $claimed !== 1) {
            return false;
        }

        $entry->status = $status;
        $this->recordOutcome($entry, $note ?? $status, null);

        return true;
    }

    /**
     * One pass over every line in the fleet. Called by the scheduler, once a minute.
     *
     * This — not the waiting page — is what makes the queue safe to close a tab on.
     * The page is a view; the sweep is the machine.
     *
     * @return array{released: int, expired: int}
     */
    public function sweep(): array
    {
        $counts = ['released' => 0, 'expired' => 0];

        // Switched off while people were standing in it. They pressed Start and are
        // owed a start, so they are released rather than dropped — turning the feature
        // off must not eat the request that was already made.
        if (!$this->isEnabled()) {
            foreach ($this->waitingEntries() as $entry) {
                if ($this->release($entry, self::REASON_FORCED)) {
                    $counts['released']++;
                }
            }

            return $counts;
        }

        // Releases before expiries, always. An entry that reached its cap in the same
        // tick that it went stale is owed its start, and doing this the other way round
        // would drop it a second before it was due.
        foreach ($this->waitingEntries() as $entry) {
            $reason = $this->releaseReason($entry);

            if ($reason !== null && $this->release($entry, $reason)) {
                $counts['released']++;
            }
        }

        $counts['expired'] = $this->expireStale();

        return $counts;
    }

    /**
     * Every line in the fleet, in the order they are served.
     *
     * Eager-loads what `release()` needs, because the alternative is three queries per
     * waiting entry inside a scheduler tick.
     *
     * @return \Illuminate\Support\Collection<int, StartQueueEntry>
     */
    public function waitingEntries()
    {
        try {
            return StartQueueEntry::query()
                ->waiting()
                ->with(['user', 'server.node', 'node'])
                ->inLineOrder()
                ->get();
        } catch (Throwable $e) {
            $this->log('queue sweep read failed', $e);

            return collect();
        }
    }

    /**
     * Drop entries nobody is standing in *and* nothing is owed to.
     *
     * The silence itself is never enough. A user who pressed Start and closed the tab
     * still gets their server when the cap fires, which is the promise the sweep
     * exists to keep — so going quiet only removes an entry whose start has stopped
     * meaning anything: the server is gone, the container is already up, or the action
     * was a restart, and firing one of those at a running container minutes later on
     * behalf of somebody who walked away is a surprise rather than a service.
     */
    protected function expireStale(): int
    {
        $seconds = $this->staleAfter();

        if ($seconds <= 0) {
            return 0;
        }

        $cutoff = now()->subSeconds($seconds);

        try {
            $entries = StartQueueEntry::query()
                ->waiting()
                ->with(['server'])
                ->where(function ($query) use ($cutoff): void {
                    $query->where('last_seen_at', '<', $cutoff)
                        ->orWhere(function ($inner) use ($cutoff): void {
                            $inner->whereNull('last_seen_at')
                                ->where('queued_at', '<', $cutoff);
                        });
                })
                ->get();
        } catch (Throwable $e) {
            $this->log('queue stale read failed', $e);

            return 0;
        }

        $expired = 0;

        foreach ($entries as $entry) {
            if (!$this->abandoned($entry)) {
                continue;
            }

            if ($this->cancel($entry, StartQueueEntry::STATUS_EXPIRED, 'stale')) {
                $expired++;
            }
        }

        return $expired;
    }

    /** Has this silent entry stopped being worth anything to the person who made it? */
    protected function abandoned(StartQueueEntry $entry): bool
    {
        $server = $entry->server;

        if (!$server instanceof Server) {
            return true;
        }

        if ($entry->action === 'restart') {
            return true;
        }

        // Only a definite yes counts. An unreachable node answers null, and null must
        // leave the entry alone — the wait is then still real and the cap still owes
        // this user a start.
        return $this->containerIsUp($server) === true;
    }

    /** True up, false down, null we could not find out. Never fold null into false. */
    public function containerIsUp(Server $server): ?bool
    {
        try {
            return $server->retrieveStatus()->isStartingOrRunning();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Send the power action the wait was for.
     *
     * Re-authorised from scratch rather than trusted from the row: the subuser
     * permission may have been revoked during the wait, and a queue entry is not an
     * authorisation record. A copy of plugged-host-ads' method rather than a call to
     * it, because this is the one thing the queue must be able to do with ads
     * uninstalled — everything else it borrows from that plugin is a nicety.
     *
     * False is survivable. The console the user lands on has its own Start button.
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
            $this->log('deferred power action failed', $e);

            return false;
        }
    }

    /**
     * Open the grace windows on the way out — ours and the ad gate's.
     *
     * Both, because they answer different doors. Ours keeps the *next* start on this
     * server out of this queue; plugged-host-ads' keeps it out of the ad waiting room.
     * Somebody who has just sat through one wait for capacity landing straight in
     * another for advertising is the single worst thing this feature could do, so the
     * ads pass is opened with a one-minute floor even when our own window is off.
     */
    protected function openPasses(User $user, Server $server): void
    {
        $minutes = $this->passMinutes();

        if ($minutes > 0) {
            try {
                Cache::put($this->passKey($user, $server), 'queue', now()->addMinutes($minutes));
            } catch (Throwable) {
                // A pass that could not be stored costs one extra wait later, which is
                // not worth failing a release the user has already earned.
            }
        }

        $gate = $this->gate();

        if ($gate === null) {
            return;
        }

        try {
            $gate->openPass($user, $server, 'queue', max(1, $minutes));
        } catch (Throwable) {
            // Same reasoning.
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

    protected function passKey(User $user, Server $server): string
    {
        return self::PASS_PREFIX . $user->getKey() . ':' . $server->getKey();
    }

    /**
     * Has this node had a release recently enough that the next one should wait?
     *
     * A node that has just dropped under the line has room for one more container, not
     * for the whole line that was waiting on it — releasing them together puts it
     * straight back over and turns one queue into two.
     */
    public function nodeSlotFree(int $nodeId): bool
    {
        if ($this->releaseInterval() <= 0) {
            return true;
        }

        try {
            return !Cache::has(self::NODE_RELEASE_PREFIX . $nodeId);
        } catch (Throwable) {
            return true;
        }
    }

    protected function markNodeReleased(int $nodeId): void
    {
        $interval = $this->releaseInterval();

        if ($interval <= 0) {
            return;
        }

        try {
            Cache::put(self::NODE_RELEASE_PREFIX . $nodeId, now()->getTimestamp(), now()->addSeconds($interval));
        } catch (Throwable) {
            // Losing the marker means the next release is not spaced. Survivable, and
            // certainly better than failing this one.
        }
    }

    /**
     * What happened to this entry, kept on the row.
     *
     * The queue keeps no event table of its own — plugged-host-ads already records
     * start-gate analytics, and one row per server here would not be a history anyway.
     * What this is for is answering "why did this server start at 19:04" from the row
     * that is still there.
     */
    protected function recordOutcome(StartQueueEntry $entry, string $reason, ?bool $started): void
    {
        try {
            $metadata = array_merge((array) ($entry->metadata ?? []), [
                'outcome' => $reason,
                'waited' => $this->elapsed($entry),
            ]);

            if ($started !== null) {
                $metadata['started'] = $started;
            }

            $entry->forceFill(['metadata' => $metadata])->save();
        } catch (Throwable $e) {
            $this->log('queue outcome write failed', $e);
        }
    }

    /**
     * Does this user bypass the line entirely?
     *
     * Root admins do, and that is not a perk: somebody putting a hot node right needs
     * to be able to start a container on it now, and a ten-minute cap is not "now".
     * The exempt-role list is plugged-host-ads', read rather than copied — one list of
     * exempt roles has to mean one thing.
     */
    public function skips(?User $user): bool
    {
        if (!$user instanceof User) {
            return true;
        }

        try {
            if ($user->isRootAdmin()) {
                return true;
            }
        } catch (Throwable) {
            // Fall through: an unanswerable role check is not a reason to skip.
        }

        if (!(bool) config('plugged-host-queue.exempt_roles_skip', true)) {
            return false;
        }

        $display = $this->display();

        if ($display === null) {
            return false;
        }

        // Exempt roles only: PlusPlus is exempt from ads too, but it buys the front of the
        // line, not a way around it.
        try {
            return (bool) $display->isExemptByRole($user);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Can this user actually do what they just asked for?
     *
     * Asked before the queue takes the start, so an unauthorised press produces
     * upstream's refusal instead of a waiting page for something that was never going
     * to happen. `can()` covers the owner, the subuser permission and the admin
     * override in one call.
     */
    protected function authorised(User $user, Server $server, string $action): bool
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
            return (bool) $user->can($permission, $server);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The waiting page's URL for this server.
     *
     * Null when it cannot be routed — a half-installed plugin, a panel that has not
     * registered the page — and null is what makes the start go through instead of
     * disappearing into a queue with no door.
     */
    public function pageUrl(Server $server): ?string
    {
        try {
            $url = StartQueuePage::getUrl(panel: 'server', tenant: $server);
        } catch (Throwable $e) {
            $this->log('queue page url failed', $e);

            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** plugged-host-ads' gate, or null when ads is not installed. */
    protected function gate(): mixed
    {
        return $this->adsService(self::ADS_GATE);
    }

    /** plugged-host-ads' display service, for the exempt-role list only. */
    protected function display(): mixed
    {
        return $this->adsService(self::ADS_DISPLAY);
    }

    protected function adsService(string $class): mixed
    {
        if (!Plugins::available($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (Throwable) {
            return null;
        }
    }

    protected function log(string $message, Throwable $e): void
    {
        Log::warning("[plugged-host-queue] {$message}", ['error' => $e->getMessage()]);
    }
}
