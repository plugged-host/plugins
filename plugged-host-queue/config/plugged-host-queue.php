<?php

return [
    /*
    |--------------------------------------------------------------------------
    | The start queue
    |--------------------------------------------------------------------------
    |
    | Every start (and restart) of a user who is not exempt and holds no pass
    | goes through a waiting page: the start is dispatched for them after at
    | least `min_wait`, one release per node every `release_interval`, once the
    | node is under the line — or when the hard cap runs out, whichever comes
    | first. A node over the line holds its line until it has room, so another
    | container never makes every server already on it worse.
    |
    | On by default once installed. The node is measured when an entry is
    | released, not on the click.
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
    | The line is `threshold_percent` below, measured live through
    | plugged-host-servers' NodeCapacity. servers' deployment pause reads the
    | same setting (ten points lower on small nodes), so the queue and the
    | allocator cannot disagree about which nodes are busy.
    |
    | Only memory and CPU hold a release back. Disk deliberately does not: a full
    | disk does not empty itself while somebody waits, so waiting on it would be
    | a line that never moves. Disk pressure is a *placement* rule instead —
    | servers keeps new servers off a node over NODES_STORAGE_PERCENT, and the
    | transfer offer below moves an existing one away.
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
    | min_wait — the floor, in seconds: every queued start waits at least this
    | long, so the waiting page never flashes past before a single ad has
    | rendered.
    |
    | max_wait — the ceiling, in seconds. Past this the start goes out whatever
    | the node looks like. Capacity pressure is our problem, not the customer's,
    | and a queue with no ceiling is an outage with a progress bar.
    |
    | release_interval — seconds between two releases on the same node. Starts
    | come up one at a time instead of all at once, so a node that just took one
    | is measured again before the next. Entries past max_wait ignore this.
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

    // The busy line. A node over this (on the pressure dimensions) releases nobody but
    // entries at max_wait, and servers' DeploymentPause takes it out of new servers (ten
    // points lower on small nodes).
    'threshold_percent' => (float) env('START_QUEUE_THRESHOLD_PERCENT', 85),

    'stale_after' => (int) env('START_QUEUE_STALE_AFTER', 180),
    'pass_minutes' => (int) env('START_QUEUE_PASS_MINUTES', 10),

    /*
    | Priority is no longer sold here: Plus puts its holders ahead of
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
    | require_priority — whether the offer needs Plus. On, it is
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
