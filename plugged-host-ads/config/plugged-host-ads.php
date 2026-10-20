<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ads Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch to globally enable or disable displaying ads across the panel.
    |
    */
    'enabled' => env('ADS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Adsterra
    |--------------------------------------------------------------------------
    |
    | Adsterra hands out a ready-made snippet per ad unit and rotates the host
    | it serves from (highperformanceformat.com, profitableratecpm.com, …), so
    | every host is configuration rather than a constant: a unit that stops
    | filling is usually a stale host, not a code bug.
    |
    | - banner_*        Display banner. The snippet is an `atOptions` global plus
    |                   `//<host>/<key>/invoke.js`; size must be one Adsterra
    |                   serves (728x90, 468x60, 320x50, 300x250, 160x600, 160x300).
    | - native_*        Native banner. `//<host>/<hash>/invoke.js` writes into a
    |                   `container-<hash>` div. Leave native_container empty to
    |                   derive the id from the script URL.
    | - social_bar_*    Social Bar. One script, renders its own floating widgets.
    | - popunder_*      Popunder. One script, opens a tab on the first click.
    |
    | The two page-level formats each carry a `*_panel_wide` switch beside their
    | script URL, because the zone and the placement are separate decisions. On, the
    | script loads on every panel page that shows ads. Off, the zone still exists and
    | the waiting room can still carry it (`start_credits.gate_ads.*`) — it just does
    | not follow the customer around the panel. Social Bar defaults to on, which is
    | what a docked widget is for; Popunder defaults to *off*, because it opens a tab
    | on the next click wherever it loads, and the one page where that is defensible
    | is the one that says out loud that the ads are paying for the server. So
    | "popunder on the starting page only" is: paste the zone, leave panel_wide off.
    |
    | isolate keeps the native banner inside a sandboxed iframe so third-party ad
    | JS cannot reach the panel's DOM, session or Livewire state. banner_isolate
    | is the same switch for the display banner, and it defaults on for the same
    | reason — but a sandboxed frame has an opaque origin, and a network that
    | attributes an impression to a domain can answer a request carrying none
    | with an empty response, i.e. a white box. Turning it off moves the banner
    | into the top document, where a document.write shim keeps its snippet from
    | blanking the panel after a Livewire body swap. Social Bar and Popunder are
    | page-level by design and always run in the top document — there is no way
    | to sandbox them and still have them work.
    |
    */
    'adsterra' => [
        'banner_key' => env('ADS_ADSTERRA_BANNER_KEY', ''),
        'banner_host' => env('ADS_ADSTERRA_BANNER_HOST', 'www.highperformanceformat.com'),
        'banner_size' => env('ADS_ADSTERRA_BANNER_SIZE', '728x90'),

        /*
        | More banner zones, as `key:WIDTHxHEIGHT:host` entries, comma separated
        | (host optional, defaults to banner_host). Together with the main banner they
        | are one pool: each placement asks it for sizes (see `placements`), and a zone
        | is served at most once per page. The waiting rooms take what is left.
        */
        'banners' => env('ADS_ADSTERRA_BANNERS', ''),
        'native_script' => env('ADS_ADSTERRA_NATIVE_SCRIPT', ''),
        'native_container' => env('ADS_ADSTERRA_NATIVE_CONTAINER', ''),
        'native_height' => env('ADS_ADSTERRA_NATIVE_HEIGHT', 320),
        'social_bar_script' => env('ADS_ADSTERRA_SOCIAL_BAR_SCRIPT', ''),
        'social_bar_panel_wide' => env('ADS_ADSTERRA_SOCIAL_BAR_PANEL_WIDE', true),
        'popunder_script' => env('ADS_ADSTERRA_POPUNDER_SCRIPT', ''),
        'popunder_panel_wide' => env('ADS_ADSTERRA_POPUNDER_PANEL_WIDE', false),
        'isolate' => env('ADS_ADSTERRA_ISOLATE', true),
        'banner_isolate' => env('ADS_ADSTERRA_BANNER_ISOLATE', true),

        /*
        | A second zone, for the start-gate waiting room only. An ordinary Social Bar
        | or Vignette zone — there is no rewarded-video format at Adsterra to point
        | this at, and nothing here claims one. It exists as its own zone so what the
        | room earns is attributable, and so the room and the panel-wide bar never
        | compete for the same corner of the screen.
        */
        'gate_script' => env('ADS_ADSTERRA_GATE_SCRIPT', ''),

        /*
        | An Adsterra Direct Link — a landing URL that pays per visit rather than a
        | script. Rendered on the waiting room as a link the user clicks, never
        | opened automatically, and inert unless start_credits.gate_ads.direct_link
        | is also on.
        */
        'direct_link' => env('ADS_ADSTERRA_DIRECT_LINK', ''),

        /*
        | Sandbox tokens for the isolating iframe. allow-same-origin ensures the
        | browser transmits the Origin/Referer headers so Adsterra attributes the
        | domain and fills the ad instead of rendering a white box. Top-level navigation
        | (allow-top-navigation) and modals remain omitted to block rogue redirects.
        */
        'frame_sandbox' => env('ADS_ADSTERRA_FRAME_SANDBOX', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel Placements
    |--------------------------------------------------------------------------
    |
    | Choose which panels and sections show ads:
    | - show_app_panel: Main user panel (dashboard, server list, account, etc.)
    | - show_server_panel: Server management area (console, files, databases, etc.)
    | - show_admin_panel: Admin control area
    |
    | Signed-out pages never show ads: signing in happens on noprofit.space, and
    | what is left here (the password reset notice) is not worth an ad.
    |
    */
    'show_app_panel' => env('ADS_SHOW_APP_PANEL', true),
    'show_server_panel' => env('ADS_SHOW_SERVER_PANEL', true),
    'show_admin_panel' => env('ADS_SHOW_ADMIN_PANEL', false),

    /*
    |--------------------------------------------------------------------------
    | Named slots + per-page cap (WP4)
    |--------------------------------------------------------------------------
    |
    | max_units_per_page is the single guarantee that "more inventory" cannot become a wall of
    | banners: AdDisplayService::slot() refuses the (max+1)-th unit in any one document, and never
    | serves the same zone twice. A new placement is a line here plus one
    | <x-plugged-host-ads::slot name="..." /> tag — no new provider code.
    |
    | Each slot: enabled, zone (logical key), units (specs for AdsterraService::unitsFor():
    | `native`, a banner size, or sizes joined by `|` for "the widest that fits"), max_height
    | (reserved space so nothing shifts on load). top is the leaderboard above the page heading
    | on every app and server page, console included, waiting rooms and the suspended page
    | excepted; a live sponsored top banner sits above it. The in-content slot at the foot of
    | every page is AdsterraService::IN_CONTENT (leaderboard + 300x250 + native), and gets
    | whatever the slots above it left. server-tools is a strip under the page header on
    | server-panel pages, console excepted. Page components render before the layout, so the
    | page's own slots (server-tools, create, store, …) pick first, then top, then the foot.
    |
    */
    'max_units_per_page' => (int) env('ADS_MAX_UNITS_PER_PAGE', 2),

    'placements' => [
        'top'               => ['enabled' => env('ADS_SLOT_TOP', true),             'zone' => 'top-leaderboard',   'units' => ['728x90|468x60|320x50'], 'max_height' => 0],
        'sidebar-footer'    => ['enabled' => env('ADS_SLOT_SIDEBAR_FOOTER', true),  'zone' => 'sidebar-native',    'units' => ['native'],  'max_height' => 300],
        'create-under-plan' => ['enabled' => env('ADS_SLOT_CREATE', true),          'zone' => 'create-rect',       'units' => ['300x250'], 'max_height' => 250],
        'store-under-tx'    => ['enabled' => env('ADS_SLOT_STORE', true),           'zone' => 'store-rect',        'units' => ['300x250'], 'max_height' => 250],
        'server-list-footer'=> ['enabled' => env('ADS_SLOT_SERVER_LIST', true),     'zone' => 'list-rect',         'units' => ['300x250'], 'max_height' => 250],
        'onboarding-install'=> ['enabled' => env('ADS_SLOT_ONBOARDING', true),      'zone' => 'onboarding-rect',   'units' => ['300x250'], 'max_height' => 250],
        'server-tools'      => ['enabled' => env('ADS_SLOT_SERVER_TOOLS', false),   'zone' => 'server-tools-strip','units' => ['468x60|320x50'], 'max_height' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Slot Stacking
    |--------------------------------------------------------------------------
    |
    | Stacking order of the ad slot. Default 0 so ads do not overlay modals,
    | dropdowns, or tooltips. Unit width is Adsterra's own — the banner fills
    | the size configured in its dashboard and the native unit sizes itself —
    | so there is nothing here to widen.
    |
    */
    'z_index' => env('ADS_Z_INDEX', 0),

    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    |
    | Every sandboxed banner/native frame on a page (in-content slot, named slots, the
    | waiting rooms) reloads after it has been on screen for this many seconds. Only time
    | in view counts: at least half the frame visible, in a visible tab, so a unit below
    | the fold or in a background tab is never reloaded. plus_seconds is the interval for
    | Plus owners (PlusPlus sees no ads at all), never faster than seconds.
    | Page-level formats (Social Bar, Popunder) and non-isolated units are not refreshed.
    |
    */
    'refresh' => [
        'enabled' => (bool) env('ADS_REFRESH_ENABLED', true),
        'seconds' => (int) env('ADS_REFRESH_SECONDS', 5),
        'plus_seconds' => (int) env('ADS_REFRESH_PLUS_SECONDS', 10),
    ],

    /*
    | In-game ads: a console `say` on running Minecraft servers (not proxies), each once per
    | interval at its own minute so the panel never sends them all at once. Owners with
    | Plus (or PlusPlus, or an exempt role) do not get it. Needs ADS_ENABLED. An empty
    | message means "This server is powered by <APP_NAME>" (AdDisplayService::inGameMessage()).
    */
    'in_game' => [
        'enabled' => (bool) env('ADS_IN_GAME_ENABLED', true),
        'message' => (string) env('ADS_IN_GAME_MESSAGE', ''),
        'interval_minutes' => (int) env('ADS_IN_GAME_INTERVAL', 60),
    ],

    /*
    | The maintenance page (core's 503 view, while the panel is down for maintenance) can
    | carry one A-ADS unit: aads_unit is its numeric id from a-ads.com, empty = no ad there.
    | width is the frame's CSS width (a number with %, px or vw). Core renders it; nothing
    | here loads on any other page.
    */
    /*
    | Site-verification meta tags for ad networks. Written into the head of every panel page and
    | into the small page a signed-out visit to `/` gets (Http\Middleware\SiteVerificationPage),
    | because that visit is otherwise a redirect to the account portal. Empty = no tag.
    */
    'verification' => [
        // Monetag's site check: the `content` of <meta name="monetag" content="…">.
        'monetag' => (string) env('ADS_MONETAG_VERIFICATION', ''),
    ],

    'maintenance' => [
        'aads_unit' => (string) env('ADS_MAINTENANCE_AADS_UNIT', ''),
        'width' => (string) env('ADS_MAINTENANCE_AADS_WIDTH', '70%'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Exemptions
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of role names that are exempt from seeing ads
    | when logged in (e.g. "Admin,VIP,Donator").
    |
    */
    'exempt_roles' => env('ADS_EXEMPT_ROLES', ''),

    /*
    |--------------------------------------------------------------------------
    | Ad-block Response
    |--------------------------------------------------------------------------
    |
    | Detection runs only where an ad would have been served, so it inherits
    | every rule above — master switch, panel placements, role exemptions.
    |
    | mode decides what a detected visitor sees:
    | - notice  Inline message next to the empty slot. Dismissible.
    | - modal   Centred overlay; "Continue anyway" unlocks after `grace` seconds.
    | - block   Same overlay with no way past it. Locks people out of their own
    |           servers over a browser extension, so it is off the recommended
    |           path; `skip_console` at least keeps the console reachable.
    |
    | probe adds a same-origin-free fetch of the configured ad hosts to the
    | signal set, which is what catches DNS-level blockers (Pi-hole, NextDNS)
    | that leave the page markup untouched.
    |
    | message overrides the translated headline body when non-empty.
    |
    */
    'antiblock' => [
        'enabled' => env('ADS_ANTIBLOCK_ENABLED', false),
        'mode' => env('ADS_ANTIBLOCK_MODE', 'notice'),
        'grace' => env('ADS_ANTIBLOCK_GRACE', 4),
        'dismiss_hours' => env('ADS_ANTIBLOCK_DISMISS_HOURS', 12),
        'probe' => env('ADS_ANTIBLOCK_PROBE', true),
        'skip_console' => env('ADS_ANTIBLOCK_SKIP_CONSOLE', true),
        'message' => env('ADS_ANTIBLOCK_MESSAGE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Start Gate
    |--------------------------------------------------------------------------
    |
    | Starting a server that is bigger than the Starter plan is where this panel
    | collects revenue. Pressing Start — or Restart, which is a start with an extra
    | step — on such a server lands the user in an ad-funded waiting room for a few
    | seconds, and the container is sent on its way as the room opens rather than
    | when it closes: the room is the ad time the start is paid with, not a queue.
    |
    | Nothing buys a way past the room. Holding a balance used to, silently; that was
    | removed when the currency became the start queue's, because two waits where one
    | charges for priority and the other can be dismissed with the same coin is not a
    | rule anyone can hold in their head.
    |
    | enabled is the feature's own switch and is *off* by default. It is also
    | subordinate to the master `enabled` above: with ads off entirely there is
    | nothing for a waiting room to show, so gating anyone would be pure cost.
    |
    | cpu / memory / disk are the thresholds a server has to exceed to be gated,
    | and they default to exactly the `starter` preset in plugged-host-core — so
    | the rule is literally "bigger than the smallest plan on offer". Note that 0
    | means *unlimited* on a Pelican server, and unlimited is always over the line.
    |
    | min_dwell / max_dwell bound the wait: released as soon as the container is
    | up but never before min_dwell, and never held past max_dwell even if the
    | container never comes up. Both are enforced server-side against a timestamp
    | the browser cannot see, let alone move.
    |
    | pass_minutes is the grace window. Satisfying the gate once opens a pass for
    | that user and that server, so a crash-restart or a quick stop/start inside
    | the window is free. Without it the gate would punish exactly the debugging
    | loop people use a game panel for.
    |
    | claim_ttl_minutes is how long a pending earn-a-token claim stays redeemable.
    | Provider tokens are far shorter-lived than this; the window is only here so an
    | abandoned claim cannot be finished days later.
    |
    | The key is still called start_credits. That is the currency's old name — it
    | became "tokens" on 2026-08-31, and tokens now buy priority in the start queue
    | rather than a way past this gate. Renaming the key would have meant rewriting
    | every ADS_START_* line in a live `.env` for no behavioural gain, so what stayed
    | behind is a name; nothing here grants, spends or counts a balance any more.
    |
    */
    'start_credits' => [
        'enabled' => env('ADS_START_ENABLED', false),

        'cpu' => env('ADS_START_CPU', 100),
        'memory' => env('ADS_START_MEMORY', 2048),
        'disk' => env('ADS_START_DISK', 10240),

        // 15, not 10: a container that boots in three seconds would otherwise show
        // an ad for three seconds. The floor is what makes the room worth serving.
        'min_dwell' => env('ADS_START_MIN_DWELL', 15),
        'max_dwell' => env('ADS_START_MAX_DWELL', 30),
        'pass_minutes' => env('ADS_START_PASS_MINUTES', 10),

        'claim_ttl_minutes' => env('ADS_START_CLAIM_TTL', 30),

        /*
        | Where the gate bites.
        |
        | The gate fires on the Start click itself: both of the panel's start
        | surfaces are Livewire calls, so core's StartChain can refuse one
        | before wings is ever told (see that class for why this was thought
        | impossible at first).
        |
        | gate_on_navigation is the wider, older net — every server-panel page load
        | of a gated, stopped server — and it is off because it charged an ad view
        | for reaching the file manager of a server that happened to be down. Turn
        | it on only to also catch starts issued outside the panel (client API,
        | schedules), and expect complaints.
        */
        'gate_on_navigation' => env('ADS_START_GATE_ON_NAVIGATION', false),

        /*
        | Waiting-room ad surfaces, one switch each.
        |
        | This is the surface the mechanic actually earns on, so it carries more
        | formats than the rest of the panel: the leaderboard, the rest of the banner
        | pool, the native unit, the Social Bar and Popunder, the
        | room's own script zone (adsterra.gate_script) and an Adsterra Direct Link
        | rendered as a button.
        |
        | social_bar / popunder here are the room's half of a pair. The other half is
        | adsterra.<format>_panel_wide: with that off and this on, the format runs in
        | the waiting room and nowhere else in the panel — which is the point of
        | having two switches rather than one. Neither does anything without a zone
        | pasted into adsterra.<format>_script.
        |
        | After the leaderboard (`banner`) the room takes every zone of the banner pool
        | (adsterra.banners) the page has not used, up to AdsterraService::MAX_GATE_BANNERS.
        |
        | direct_link is off by default and stays a link the user chooses to click.
        | A panel that navigates people to an advertiser on its own is adware, and
        | popunder is already as close to that line as this goes.
        */
        'gate_ads' => [
            'banner' => env('ADS_START_GATE_BANNER', true),
            'native' => env('ADS_START_GATE_NATIVE', true),
            'social_bar' => env('ADS_START_GATE_SOCIAL_BAR', true),
            'popunder' => env('ADS_START_GATE_POPUNDER', true),
            'interstitial' => env('ADS_START_GATE_INTERSTITIAL', true),
            'direct_link' => env('ADS_START_GATE_DIRECT_LINK', false),
        ],

        /*
        | Reward providers — one, currently. Off until it is configured, and
        | verified server-side against Linkvertise's own API on the returning
        | request: a redirect back to us proves nothing on its own.
        |
        | daily_cap is per user per provider per day, and it is the only thing
        | standing between a scripted browser and an unbounded token supply.
        | Raise it with that in mind.
        |
        | Two integrations live under these keys, and user_id is the switch between
        | them:
        |
        |   user_id set    → Full Script API. linkvertise.js is loaded on the earn
        |                    page and rewrites our own return link into a
        |                    link-to.net trip. This is the one the publisher
        |                    dashboard now documents.
        |   user_id empty  → the older static link. `link` is a publisher link
        |                    pasted in by hand and the page redirects to it.
        |
        | token is the Anti-Bypass "Link-Verification" token, and it is required
        | either way: without it the returning token cannot be checked, so the
        | provider stays disabled. Anti-Bypass must also be enabled on the
        | Linkvertise account itself, or no hash ever reaches the return URL and
        | nobody can earn.
        |
        | whitelist is a correctness requirement of the Full Script API, not a
        | tuning knob. Empty whitelist plus empty blacklist means "rewrite every
        | link in the document" to that script, which on a panel page would turn
        | the sidebar, the console and the file manager into Linkvertise redirects.
        | It holds substrings of the one href we want rewritten — the return URL
        | carries ?lvr=<nonce>, hence the default — and the script compiles these
        | patterns into a regex, so regex metacharacters do not belong in them.
        */
        'linkvertise' => [
            'enabled' => env('ADS_START_LINKVERTISE_ENABLED', false),
            'user_id' => env('ADS_START_LINKVERTISE_USER_ID', ''),
            'whitelist' => env('ADS_START_LINKVERTISE_WHITELIST', 'lvr='),
            'link' => env('ADS_START_LINKVERTISE_LINK', ''),
            'token' => env('ADS_START_LINKVERTISE_TOKEN', ''),
            'reward' => env('ADS_START_LINKVERTISE_REWARD', 1),
            'daily_cap' => env('ADS_START_LINKVERTISE_DAILY_CAP', 5),
        ],
    ],
];
