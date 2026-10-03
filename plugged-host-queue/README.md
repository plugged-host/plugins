# plugged.host Queue

Holds a server **start** back while its node is genuinely out of headroom, and dispatches
it as soon as there is room — or when the hard cap runs out, whichever comes first.

Not a queue for creating servers. Storage fills over weeks and is a placement problem;
memory and CPU fill in the evening and are a *timing* problem, which is the only kind a
queue can solve.

## Why it exists

A node measured over the pressure line has nothing left to give. Letting one more
container come up on it does not fail loudly — it quietly makes every server already
running there worse, including the ones belonging to people who did nothing. Holding the
new start for ninety seconds costs one customer a wait. Not holding it costs everyone on
that node their tick rate.

The waiting page is also the one screen where a user is knowingly spending time in
exchange for capacity somebody has to pay for, which is why it carries ads and why the
two ways out of it cost tokens.

## How a start becomes a place in a line

1. The user presses Start or Restart in the server panel.
2. `plugged-host-ads`' `PowerActionInterceptor` — the single Livewire `call` hook, see
   *One hook* below — asks `StartQueueService::interceptStart()` **first**.
3. The queue checks, in this order: enabled, action is queueable, the user may power this
   server, the user is not exempt, no existing entry, no active skip pass, the server is
   not in a conflict state, and the node is over the line. Anything false and it returns
   `null`, the interceptor moves on, and nothing happened.
4. Over the line: a row goes into `start_queue_entries` and the URL of the waiting page
   comes back. The interceptor cancels the Livewire call and redirects. **Nothing was
   sent to wings** — the container is still down.
5. `QueueSweepCommand`, every minute, releases every entry whose node has room or whose
   cap has run out, and dispatches the power action itself.
6. The waiting page polls every 5 s and does the same thing sooner.

Step 5 is the load-bearing one. A user who presses Start and closes the tab has still
asked for a start, so the sweep delivers it on their behalf. The page only ever makes it
happen faster.

## What the wait is measured against

Live utilisation from wings, through `plugged-host-core`'s `NodeCapacity` — never
committed-allocation arithmetic. "The node is 95% full" means the machine is *at* 95%
right now, not that 95% of its RAM has been promised to servers that are mostly idle.
The threshold is core's `nodes.hot_percent`, deliberately shared: the queue and the
allocator disagreeing about how full a node is would be worse than either being wrong.

Memory and CPU by default. **Disk is available and not recommended** — a node at 96% full
is still at 96% full in ten minutes, so a line waiting on it never advances. Disk is
handled as a placement rule instead: core keeps new servers off a node over
`nodes.storage_percent`, and the transfer offer below moves an existing one away.

## The line order

`priority` descending (2 PlusPlus, 1 Plus, 0 everyone else), then `queued_at`, then `id`.
So with two Plus holders and two without, arriving in that order, the line is
`p1, p2, nop1, nop2`, and a PlusPlus holder goes ahead of both. Buying Plus while waiting
moves you ahead of everyone without it and behind every Plus holder who was already
waiting — never in front of somebody at the same level who got there first.

`StartQueueEntry::precedingCount()` is the exact negation of that ordering, and
`releaseReason()` requires it to be zero. One place defines the order; the other two read
it.

## The two ways out, and what they cost

**Priority** — comes with plugged.host Plus (permanent) and PlusPlus (the front of the
line), sold on the plugged.host Plus page by plugged-host-economy (`MembershipService`);
`PriorityPassService::level()` reads it. Account-wide: every start of every server the
user owns sorts ahead of lower levels. The queue no longer sells priority itself; the
token passes it used to sell (`start_queue_priorities`) were closed and refunded by
`plugged-host:queue-refund-priority-passes` when Plus replaced them (2026-10-03).
An ad exemption (PlusPlus) never skips the queue; only exempt roles and root admins do.

**Moving to a quieter node** — offered on the waiting page, taken by the user, never
automatic. A transfer changes the server's IP and port and takes it offline while the
data directory copies, so it is not something to do *to* somebody: it is behind
`wire:confirm`, and the confirmation says the address will change. Hidden at or above
3 GB of disk (past a few gigabytes it is no longer the fast way out) and for servers with
unlimited disk. The queue entry is **cancelled** rather than released, because a server
that is copying between nodes cannot start; the user presses Start themselves when it
lands.

The move is gated on Plus by default (`require_priority`).

## One hook

Livewire's `EventBus::trigger()` runs **every** `before('call')` listener regardless of
what any of them passed to `$returnEarly`. Two plugins hooking the same power action
would each cancel it, and only one of the two answers would survive — so the ad gate and
the queue would fight over the same click.

Therefore: **`plugged-host-ads` owns the hook.** It asks this plugin by class name,
before its own gate check, and returns early on a URL. This plugin registers no Livewire
listener at all. That is not a layering accident, it is the constraint.

The rest of the coupling runs the other way and is all soft — every reach into ads
(`StartGateService`, `AdDisplayService`, `AdsterraService`, `EarnTokensPage`, the
interstitial partial) is by string behind `class_exists` and fails open. The queue works
with ads uninstalled; the waiting page is just quiet.

## Two waits never stack

The ad gate still exists and is unchanged: press Start on an ordinary node and you get
the gate, which starts the container on the click and collects an impression while it
boots.

What must not happen is sitting through the queue and then landing in the gate. Two
things prevent it:

- Ads asks the queue **before** its own `isEnabled()`, and returns early on a queue URL.
  So a queued start never reaches the gate on the way in.
- On release, the queue calls ads' `openPass($user, $server, 'queue', max(1, $minutes))`
  alongside its own pass. So the console cannot bounce the user into the gate on the way
  out. The one-minute floor means this holds even with `pass_minutes` set to 0.

Tokens no longer buy a way past the ad gate — that tier was scrapped deliberately. Tokens
buy priority; the gate is the price of a free start.

## Races, and what settles them

| Race | Settled by |
|---|---|
| Poll and sweep both release the same entry | `release()` claims the row with a conditional `UPDATE … WHERE status = 'waiting'`; zero rows changed means somebody else got there first, and it returns `false` |
| Two starts for one server | `unique` index on `start_queue_entries.server_id` |
| Two priority purchases at once | `unique` index on `start_queue_priorities.user_id`; the violation is retried as an extension |
| A cooled node getting the whole line at once | `release_interval`, per node, cache-backed. Entries at the cap ignore it — but the marker is still set, because a container did land there |
| An entry that hit its cap in the same tick it went stale | `sweep()` releases before it expires. That start is owed |

A silent entry is only dropped when its start has stopped meaning anything: the server is
gone, the action was `restart`, or the container is already up. An unreachable node
answers "unknown", and unknown never drops an entry.

## Configuration

Admin Area → Plugins → **plugged.host Queue**. Settings are written to `pelican/.env` as
`START_QUEUE_*` keys, so editing `.env` by hand is equivalent.

| Key | Default | |
|---|---|---|
| `START_QUEUE_ENABLED` | `true` | |
| `START_QUEUE_INCLUDE_RESTART` | `true` | A restart brings a container up, so it costs the same headroom |
| `START_QUEUE_EXEMPT_ROLES_SKIP` | `true` | Reuses ads' exempt role list. Root admins always skip |
| `START_QUEUE_PRESSURE_DIMENSIONS` | `memory,cpu` | |
| `START_QUEUE_MIN_WAIT` | `10` | Floor, seconds |
| `START_QUEUE_MAX_WAIT` | `600` | Ceiling, seconds. A queue with no ceiling is an outage with a progress bar |
| `START_QUEUE_RELEASE_INTERVAL` | `20` | Seconds between releases on one node |
| `START_QUEUE_STALE_AFTER` | `180` | Drop entries that went silent *and* never became releasable |
| `START_QUEUE_PASS_MINUTES` | `10` | Skip both waits after a release |
| `START_QUEUE_TRANSFER_ENABLED` | `true` | |
| `START_QUEUE_TRANSFER_REQUIRE_PRIORITY` | `true` | The move needs plugged.host Plus |
| `START_QUEUE_TRANSFER_MAX_DISK` | `3072` | MiB. Hide the offer at or above |
| `START_QUEUE_TRANSFER_SAME_LOCATION` | `true` | |

The pressure thresholds are not here. They are `nodes.hot_percent` and
`nodes.storage_percent` in `plugged-host-core`.

## Operating it

There is no admin screen for the line. It is scratch state, usually empty, and a table
listing it would be a page that says "nobody is waiting" all day. The CLI is the window:

```bash
sudo -u www-data php artisan plugged-host:queue-sweep --status
```

Config summary, then a row per waiting entry with its place, how long it has waited, and
**why it has not been released** — `min wait`, `node hot (memory)`, `spacing`,
`not first`, or `ready`. Those are the same four checks `releaseReason()` makes, in the
same order, reported instead of acted on, so a line that is not moving can be explained
without reading the source.

```bash
sudo -u www-data php artisan plugged-host:queue-sweep --release
```

Empties every line now, whatever the nodes look like: for a bad measurement, or a node
about to go down for maintenance where holding people in front of it helps nobody. It
**releases** rather than cancels — everybody standing there asked for a start and is owed
one.

Turning the plugin off has the same effect on the next tick: the sweep releases everyone
rather than leaving them stranded.

## Layout

```
config/plugged-host-queue.php
database/migrations/001_create_start_queue_tables.php
lang/{en,de}/strings.php
resources/views/filament/pages/start-queue.blade.php
src/PluggedHostQueuePlugin.php                    Filament plugin + settings form
src/Providers/QueuePluginProvider.php             sweep schedule + interstitial registration
src/Console/Commands/QueueSweepCommand.php        the part that runs unwatched
src/Filament/Server/Pages/StartQueuePage.php      the waiting page
src/Models/StartQueueEntry.php                    line order lives here
src/Models/StartQueuePriority.php                 the old passes (closed and refunded)
src/Services/StartQueueService.php                enqueue, release, sweep, dispatch
src/Services/NodePressure.php                     adapter over core's NodeCapacity
src/Services/PriorityPassService.php              priority level from Plus/PlusPlus
src/Services/NodeTransferService.php              find a quieter node, move the server
```

## Dependencies

- **`plugged-host-core`** — `NodeCapacity` and the thresholds. Soft; the queue fails open
  (nothing is ever under pressure) without it, which means it does nothing.
- **`plugged-host-economy`** — `MembershipService` (Plus/PlusPlus). Soft; without it
  nobody has priority and the Plus offer is not rendered.
- **`plugged-host-ads`** — owns the Livewire hook, so in practice this is the one hard
  dependency: without ads installed nothing calls `interceptStart()` and no start is ever
  queued. Everything else ads provides (units, direct link, exempt roles, the skip pass)
  degrades quietly.
