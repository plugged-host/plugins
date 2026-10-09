<?php

return [
    /*
    |--------------------------------------------------------------------------
    | The start queue
    |--------------------------------------------------------------------------
    |
    | When a node is genuinely out of headroom, letting another container come up
    | on it makes every server already on it worse. This plugin holds the start
    | back instead: the user lands on a waiting page, and the start is dispatched
    | for them when the node has room or when the hard cap runs out — whichever
    | comes first.
    |
    | On by default once installed. It only ever acts on a node measured over the
    | pressure line, so on a healthy fleet it is invisible.
    |
    | include_restart — a restart brings a container up, so it queues too. Unlike
    | the ad gate, nothing is taken down first: the Livewire call is cancelled
    | outright, so the container keeps running while the user waits and the
    | restart is dispatched on release.
    |
    | exempt_roles_skip — reuses plugged-host-ads' exempt role list rather than
    | keeping a second one. One list of exempt roles has to mean one thing.
    |
    */
    'enabled' => (bool) env('START_QUEUE_ENABLED', true),
    'include_restart' => (bool) env('START_QUEUE_INCLUDE_RESTART', true),
    'exempt_roles_skip' => (bool) env('START_QUEUE_EXEMPT_ROLES_SKIP', true),

    /*
    |--------------------------------------------------------------------------
    | What counts as "no room"
    |--------------------------------------------------------------------------
    |
    | The thresholds themselves live in plugged-host-core (`nodes.hot_percent`,
    | `nodes.storage_percent`) and are read through its NodeCapacity service, so
    | the queue and the allocator cannot disagree about how full a node is.
    |
    | Only memory and CPU queue a start. Disk deliberately does not: a full disk
    | does not empty itself while somebody waits, so queueing on it would be a
    | line that never moves. Disk pressure is a *placement* rule instead — core
    | keeps new servers off a node over storage_percent, and the transfer offer
    | below moves an existing one away.
    |
    */
    'pressure' => [
        'dimensions' => (string) env('START_QUEUE_PRESSURE_DIMENSIONS', 'memory,cpu'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing
    |--------------------------------------------------------------------------
    |
    | min_wait — the floor, in seconds. A node that cools between the click and
    | the first poll would otherwise produce a waiting page that flashes past
    | before a single ad has rendered.
    |
    | max_wait — the ceiling, in seconds. Past this the start goes out whatever
    | the node looks like. Capacity pressure is our problem, not the customer's,
    | and a queue with no ceiling is an outage with a progress bar.
    |
    | release_interval — seconds between two releases on the same node. A node
    | that has just dropped under the line has not got room for everything that
    | was waiting on it, and releasing the whole line at once puts it straight
    | back over. Entries past max_wait ignore this.
    |
    | stale_after — seconds of silence after which a waiting entry is dropped by
    | the sweep. Only for entries that never became releasable; a tab that was
    | closed still gets its start, because the sweep dispatches on the user's
    | behalf.
    |
    | pass_minutes — how long after a release this user's next start on the same
    | server skips both this queue and the ad gate. Sitting through one wait and
    | then landing in the other is the single worst thing this feature could do.
    |
    */
    'min_wait' => (int) env('START_QUEUE_MIN_WAIT', 10),
    'max_wait' => (int) env('START_QUEUE_MAX_WAIT', 600),
    'release_interval' => (int) env('START_QUEUE_RELEASE_INTERVAL', 20),

    // WP11: the queue's own busy threshold, decoupled from the allocator's 95% ceiling. A node
    // over this (on the pressure dimensions) sends new starts through the queue.
    'threshold_percent' => (float) env('START_QUEUE_THRESHOLD_PERCENT', 85),

    // WP11: when a node's wings utilisation cannot be read, do not fail fully open — still pace
    // starts one-per-release_interval rather than allowing an unbounded stampede on a struggling
    // daemon. The pass-through slot stamp does this; set false to let unmeasurable nodes run free.
    'paced_on_unavailable' => (bool) env('START_QUEUE_PACED_ON_UNAVAILABLE', true),
    'stale_after' => (int) env('START_QUEUE_STALE_AFTER', 180),
    'pass_minutes' => (int) env('START_QUEUE_PASS_MINUTES', 10),

    /*
    | Priority is no longer sold here: plugged.host Plus puts its holders ahead of
    | everyone without it (level 1), PlusPlus at the very front (level 2). Both are
    | sold and kept by plugged-host-economy (MembershipService); PriorityPassService
    | reads the level. The old passes were refunded (queue-refund-priority-passes).
    */

    /*
    |--------------------------------------------------------------------------
    | Moving off a busy node
    |--------------------------------------------------------------------------
    |
    | The other half of what a pass buys: instead of waiting out a node that is
    | over the line every evening, move the server to one that is not. Offered
    | from the waiting page, taken by the user, never automatic — a transfer
    | changes the server's IP and port and takes the server offline while it
    | copies, so it is not something to do to somebody.
    |
    | max_disk — hide the offer at or above this many MB. A transfer copies the
    | whole data directory between nodes, and past a few gigabytes that is long
    | enough that "the fast way out of the queue" stops being true. 0 disk means
    | unlimited, which is always over the line.
    |
    | same_location — only offer nodes sharing a location tag with the current
    | one. Off, the pool widens to every viable node, which can silently move
    | somebody to another continent.
    |
    | require_priority — whether the offer needs plugged.host Plus. On, it is
    | part of what Plus buys. Off, anyone stuck in the queue can move.
    |
    */
    'transfer' => [
        'enabled' => (bool) env('START_QUEUE_TRANSFER_ENABLED', true),
        'require_priority' => (bool) env('START_QUEUE_TRANSFER_REQUIRE_PRIORITY', true),
        'max_disk' => (int) env('START_QUEUE_TRANSFER_MAX_DISK', 3072),
        'same_location' => (bool) env('START_QUEUE_TRANSFER_SAME_LOCATION', true),
    ],
];
