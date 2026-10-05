# plugged.host Ads Plugin for Pelican Panel

Serves **Adsterra** advertising units across the panel, with an optional ad-block response and an
optional ad-funded start gate, without modifying a single core panel file.

## Features

- **Adsterra formats**: Banner, Native Banner, Social Bar, Popunder — each independently configured,
  each skipped when its fields are empty.
- **Third-party JS isolation**: Adsterra display units render inside a sandboxed `srcdoc` frame with
  an opaque origin, so ad code cannot reach the panel's DOM, cookies, or Livewire state.
- **Flexible panel targeting**: App panel, server panel, admin panel, auth pages — each a toggle.
- **Role exemptions**: exclude VIP, donor, or staff roles from seeing ads.
- **Ad-block response** (off by default): multi-signal detection with an inline notice, a dismissible
  modal, or a hard block.
- **Start gate** (off by default): pressing Start — or Restart — on a server bigger than the Starter
  plan (the lowest of plugged-host-core's presets; every preset is free, so this is about size, not
  payment) lands the user in a short ad-funded waiting room. The container is sent on its way as the
  room opens rather than when the ads finish, so the room is the ad time the start is paid with, not
  a queue. Satisfying the gate once opens a grace window, so restarts inside it are free.
- **Token earning** (off by default): the panel's tokens are earned here, from Linkvertise, verified
  server-side against the publisher API and capped per user per day. Tokens themselves live in
  `plugged-host-economy` and are spent in `plugged-host-queue` on start-queue priority — nothing in this
  plugin sells a way past the waiting room.
- **Admin settings GUI**: **Admin Area → Plugins → plugged.host Ads → Settings**. Everything persists
  to `pelican/.env`, so hand-editing `.env` is an equivalent path.
- **Multi-language**: full English (`en`) and German (`de`) localization.
- **Strictly modular**: 100% contained in the plugin directory; zero core modifications, no Vite
  assets (all CSS/JS is injected at Filament render hooks).

## Setting up Adsterra

Create the ad units in the Adsterra dashboard, then copy the values out of the snippets it gives you.
Nothing is fetched from Adsterra's API — the plugin only rebuilds the snippets it was given.

### Which formats to create

| Format | Use it? | Why |
|---|---|---|
| **Banner** | Yes | The core in-content unit. Fills a fixed size in the ad slot. |
| **Native Banner** | Yes | Blends with panel content, sizes itself, no fixed height. Good second unit. |
| **Social Bar** | Careful | A floating widget drawn over the whole page. It cannot be sandboxed. Fine on auth pages, intrusive for paying customers — `ADS_ADSTERRA_SOCIAL_BAR_PANEL_WIDE=false` keeps the zone but confines it to the waiting room. |
| **Popunder** | Waiting room only | Opens a window on any click. Panel-wide on a control panel that reads as malware and generates support tickets, so `ADS_ADSTERRA_POPUNDER_PANEL_WIDE` is `false` by default and the zone runs on the start page and nowhere else. |
| **Smartlink** | No | A destination URL for links you place yourself, not an on-page unit — this plugin has nowhere to put it. |

If you only want to tick boxes once: **Banner + Native Banner**, and add **Social Bar** only if you
accept a floating widget.

### Which website category to register

Register `panel.plugged.host` (or `plugged.host`) under **Hosting / Web Services** — the category that
matches what the site actually is. Adsterra's list is ordered by how well it pays, and picking a
better-paying category the site does not match gets the placement rejected on review, or filled with
mismatched creatives that never convert. If that exact label is not in the dropdown, the closest
honest option wins, in this order: *Hosting & Domains* → *Web Services / SaaS* → *Software* →
*Technology / IT* → *Other*. Do not pick Streaming, Downloads, File Sharing, or Entertainment: they
pay more, they are what a game-hosting panel gets mistaken for, and they are also the categories with
the most aggressive creatives — exactly what you do not want in a logged-in customer's browser.

For the traffic questions alongside it: the panel is **logged-in dashboard traffic**, mostly desktop,
and the audience is worldwide with no single dominant GEO unless you know otherwise.

### Filling in the fields

Each field below maps to one value in an Adsterra snippet. Leave a field empty to skip that format
entirely — a half-filled format renders nothing rather than something broken.

| Setting | Where it comes from |
|---|---|
| Banner Key | `'key' : '…'` in the Banner snippet's `atOptions` (32 hex characters) |
| Banner Size | Must match the size you chose in Adsterra, or the unit never fills |
| Banner Host | The **hostname only** from the Banner snippet's `invoke.js` URL (`www.highperformanceformat.com`, or whatever yours says — Adsterra rotates these per unit). Paste the whole URL if it is easier; it is reduced to the host on save. |
| Banner Zones | More banner zones: paste the Banner snippets as Adsterra gives them, or `key:WIDTHxHEIGHT:host` one per line. Stored as `ADS_ADSTERRA_BANNERS` (see *Banner pool*) |
| Native Banner Script URL | The `src` of the Native Banner `<script>` (`//pl…/…/invoke.js`) |
| Native Banner Container ID | The `id` of the empty `<div>` in the Native Banner snippet |
| Social Bar / Popunder Script URL | The `src` of that snippet's `<script>` |

Values are validated on render: a key must be 8–64 alphanumerics, a host must be a real domain, and a
script URL must be `//host/path` with no query, fragment, or `..` segment. Anything else is dropped —
so a pasting mistake costs you an empty slot, never broken markup.

### Isolation and the sandbox

Both display formats are sandboxed by default: each renders as a whole document inside a `srcdoc`
iframe, so the ad script gets a fresh opaque origin and cannot reach the panel's DOM, cookies or
Livewire state. *Isolate Display Banner* and *Isolate Native Banner* turn that off per format.

**Isolation and sandbox security.** Both display formats render inside a sandboxed `srcdoc`
iframe. The sandbox permissions include `allow-scripts`, `allow-same-origin`, `allow-popups`,
`allow-popups-to-escape-sandbox`, and `allow-forms`. `allow-same-origin` ensures that the browser
transmits the site's `Origin` and `Referer` headers so Adsterra can verify the domain and fill the ad
instead of returning an empty white box.

Crucially, **`allow-top-navigation` and `allow-top-navigation-by-user-activation` are omitted**. This
blocks rogue ads from redirecting the main panel or hijacking navigation. `allow-modals` is also omitted
to block intrusive dialogs, and the iframe prevents `document.write` or Livewire DOM conflicts on the
panel.

`ADS_ADSTERRA_FRAME_SANDBOX` defaults to
`allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms`.

Social Bar and Popunder are injected into the top document at `BODY_END` because both need it to draw
at all; they carry `data-navigate-once` so an SPA navigation cannot stack a second widget or fire a
second popunder.

### Where the page-level formats load

Pasting a zone in and deciding where it runs are two separate decisions, so they are two settings.
Each of the two page-level formats has a `*_PANEL_WIDE` switch beside its script URL:

| Switch | Default | On | Off |
|---|---|---|---|
| `ADS_ADSTERRA_SOCIAL_BAR_PANEL_WIDE` | `true` | The bar loads on every panel page that shows ads | The zone stays configured but only loads where a waiting-room switch asks for it |
| `ADS_ADSTERRA_POPUNDER_PANEL_WIDE` | `false` | A popunder can fire on any click anywhere in the panel | The zone runs on the waiting room only |

So **"popunder on the server starting page only"** — which is the only placement for it worth
defending — is: paste the zone into `ADS_ADSTERRA_POPUNDER_SCRIPT`, leave
`ADS_ADSTERRA_POPUNDER_PANEL_WIDE` off, and leave `ADS_START_GATE_POPUNDER` on. The same pair works for
the Social Bar. The waiting room draws from the configured zones directly, not from the panel-wide
placement, so turning a format off across the panel can never silently empty the one page that earns.

A popunder opens a tab on the *next click*, wherever its script loaded. Panel-wide, that click is
whatever the customer does next — a file, a console command, a sidebar link. On the starting page it is
a click on a page that has just said out loud that the ads are what pay for the server. That asymmetry
is the whole reason the two defaults differ.

## Banner pool

The main banner (`ADS_ADSTERRA_BANNER_KEY`/`_SIZE`/`_HOST`) and every zone in `ADS_ADSTERRA_BANNERS`
(`key:WIDTHxHEIGHT:host`, comma separated, host optional) form one pool. Each placement asks the pool
for sizes, and a zone is handed out at most once per page (`AdsterraService::take*()`, request-scoped),
so two placements never serve the same zone in one document.

| Placement | Units |
|---|---|
| Foot of every page (`CONTENT_END`) | Leaderboard (`728x90`, `468x60` or `320x50`, whichever fits) + native |
| Named slots: create, store, server list, onboarding | `300x250` |
| `server-tools`: under the page header on server pages, not the console or waiting pages | `468x60`, or `320x50` on phones |
| Waiting rooms (start gate, start queue) | Leaderboard, then every pool zone the page has not used (up to six), then native |

A unit over several sizes renders one inert `<template>` per size, widest first, and the unit script
inserts the widest that fits its container, or none. A banner wider than the screen is left out instead
of cropped, and nothing is hidden with CSS: a hidden iframe still loads, and an impression nobody can see
is what a network audits for. The named-slot wrapper is `wire:ignore`, so a Livewire morph cannot put
the templates back over the inserted frame.

## Ad refresh

On by default (`ADS_REFRESH_ENABLED`). Every sandboxed banner and native frame on a page (the
in-content slot, the named slots, the start gate and the start queue's waiting room) reloads once it
has been **in view** for `ADS_REFRESH_SECONDS` (default 5), or `ADS_REFRESH_PLUS_SECONDS` (default 10)
for plugged.host Plus owners. PlusPlus sees no ads, so nothing to refresh. Settings: *Ad Refresh*.

Only time in view counts: at least half the frame on screen, in a visible tab. A unit below the fold
or in a background tab is never reloaded, so every refresh is an impression someone could see. The
Plus interval is stored as at least the regular one. Social Bar, Popunder and non-isolated units are
not refreshed (they live in the top document, where a reload would mean re-running third-party code
against the panel).

## Ad-block response

Off by default (`ADS_ANTIBLOCK_ENABLED=false`). When on, it only runs where an ad was actually asked
for: it inherits the master switch, the per-panel placements, the auth-page toggle and the role
exemptions, and it additionally never runs on the server console while `ADS_ANTIBLOCK_SKIP_CONSOLE` is
true — interrupting someone mid-command turns a browser extension into a support ticket.

### How detection decides

Three signals, weighted, with a threshold of 2 — so no single circumstantial signal can accuse a
paying customer on its own:

| Signal | Weight | What it means |
|---|---|---|
| Bait element hidden **in place** | 2 | A `div` with EasyList-style class names (`adsbox`, `pub_728x90`, …). Nothing but an ad blocker hides it, so this is decisive alone. |
| Every configured ad host unreachable | 1 | A `no-cors` fetch of each ad URL. This is the only signal that catches a DNS- or router-level blocker, which leaves page markup untouched. |
| Every visible ad unit collapsed | 1 | Units below `2 × viewport` are skipped, since lazy loading legitimately leaves them empty. |

Nothing is judged for 1.2 s after the page settles, because a frame that is still loading looks
exactly like a blocked one. The verdict is cached for the SPA session and re-applied on navigation
rather than re-tested, and the probe is skipped entirely once the bait has already decided.

**Nothing is acted on until a second look agrees.** A reading that reaches the threshold is taken
again 2.5 s later in the same document, and only a second one that also reaches it produces a notice.
Every innocent way to hit 2 is transient — a unit that had not filled yet, a probe the network
answered with a reset — and is gone by then; a blocker is not. The cost is a notice that appears
around four seconds after the page rather than one, which is the cheaper of the two mistakes.

**Three things that look like blocking but are this panel.** They were what made a burst of refreshes
report an ad blocker, and none of them counts any more:

- **A bait that is no longer in the document.** Livewire replaces the whole `<body>` on a
  `wire:navigate`, which detaches anything a script appended to it. A cosmetic filter sets
  `display:none` and leaves the element where it was, so only *hidden in place* is evidence; a missing
  bait is replanted and the next look answers.
- **A measurement that spans a navigation.** A `wire:navigate` swap, a real navigation or the tab
  going into the background detaches the bait, cancels probes in flight and empties the units that
  were about to fill. Each of those bumps a counter the detector compares before and after every
  reading, and a reading that spans one is thrown away and retried, never scored. A background tab is
  not measured at all: it loads no lazy frame and its requests are deprioritised.
- **Probes the detector itself provoked.** They no longer set `cache: 'no-store'`, a host that answers
  once is not asked again for the rest of the document, and the whole signal is abandoned rather than
  counted if it has not answered within 4 s. One uncached probe per navigation is a burst that a fast
  refresh turns into a rate-limit reset, which from the browser is indistinguishable from a blocked
  request — the old code was manufacturing its own evidence.

The trade for the last two is a blocker switched on mid-session going unnoticed until the next full
page load, and a slow network reading as reachable. Both are false negatives, which is the direction
this feature is allowed to be wrong in.

### Modes

| Mode | Behaviour |
|---|---|
| `notice` | An inline banner where the ad would have been. Dismissible; the dismissal is remembered for `ADS_ANTIBLOCK_DISMISS_HOURS`. |
| `modal` | A centred overlay that locks scrolling. "Continue anyway" unlocks after `ADS_ANTIBLOCK_GRACE` seconds so it cannot be clicked away on reflex. |
| `block` | The same overlay with no way past it, and dismissals are ignored. Read the warning below first. |

`block` mode locks out a paying customer over a browser extension, and detection — however careful —
is heuristic. Ship `notice` first and look at your support queue before considering anything firmer.

The notice's own markup uses neutral class names (`ph-support-*`) and sits outside every ad-shaped
wrapper, so the blocker that triggered it does not also hide it. All of its text is set with
`textContent`; the custom message is admin-supplied free text and is never parsed as HTML.

### Styling

Both the inline notice and the modal dialog are real Filament surfaces, not a look this plugin
invented. Each is an `fi-section` containing Filament's own section anatomy
(`fi-section-header` → `fi-section-header-heading` / `-description`, then `fi-section-content-ctn`),
and the two buttons carry the exact class lists a Filament `Action` renders — solid primary for
"Reload", `fi-outlined` for the dismiss. So the notice inherits the panel's palette, borders,
typography and light/dark switch, and follows any theme change without an edit here.

That leaves `styles/adblock.blade.php` naming no colour of its own except the modal backdrop, which
copies Filament's `fi-modal` values (`color-mix(in oklab, var(--gray-950) 50%, transparent)`, 75% in
dark) because a real `fi-modal` needs Alpine to drive it and this has to work on a page Livewire has
already navigated. Everything else in that file is layout, the scroll lock, and a `prefers-reduced-motion`
opt-out for the entrance animation.

Its stylesheet is injected unconditionally while the feature is on, rather than per page: Livewire
merges `<head>` on the first full page load only, so CSS gated on "this page shows an ad" would be
missing for the rest of the SPA session whenever the entry page happened to be an exempt one.

## Start gate

Off by default (`ADS_START_ENABLED=false`), and subordinate to `ADS_ENABLED` and to having at least one
Adsterra unit configured — with no ad to show, a waiting room is pure cost.

**What it does.** Pressing **Start** on a stopped server that exceeds the thresholds, or **Restart** on
one whatever its container is doing, does not reach wings by the route it was pressed on. The click is
cancelled and the user lands on `/server/<id>/start`: a full-page waiting room carrying the Adsterra
units, with the sidebar hidden while it is open. It releases as soon as the container reports up, but
never before `ADS_START_MIN_DWELL`, and never later than `ADS_START_MAX_DWELL` — both measured
server-side against a cache timestamp the browser never sees. Satisfying the gate once opens a pass for
that user and that server for `ADS_START_PASS_MINUTES`, so a crash-restart or a quick stop/start is
free.

**The container starts on the click, not when the ads finish.** Cancelling the Livewire call is what
keeps the original code path from reaching wings, so the interceptor sends the power action itself,
immediately, and *then* opens the hold and redirects. The room is therefore ad time the start is paid
with rather than a queue in front of it: the console behind the room already shows the container coming
up, and the release is a navigation, not a trigger. Only if that dispatch fails — wings unreachable, the
node gone between the gate check and the call — is the action remembered instead and replayed on
release, so a failed dispatch costs neither the start nor a second wait. The two paths stay
distinguishable afterwards: the event row's metadata carries `dispatched` = `click`, `release` or
`none`. The accepted trade is that closing the tab mid-wait now costs the ad view but not the start.

**Restart counts, even on a running server.** A restart takes the container down and brings it back up,
so it is a start with an extra step and the expensive half of it is identical — there is no reason it
should be free just because the button was pressed while the server happened to be live. `Start` keeps
its own "is it already up?" check, because pressing Start on a running server is a no-op and must not
cost an ad view; `restart` is listed in `PowerActionInterceptor::ALWAYS_GATED_ACTIONS` and skips that
check (and with it the daemon round trip — there is nothing the answer could change). Two consequences
worth knowing. The waiting room's own `mount()` cannot ask `requiresGate()` first, or a gated restart
would be bounced straight to the console: it asks "is a hold already open for this user and server?",
then "is a start still owed?", and only then the plain gate check — the first two reads being reads, so
opening the URL by hand never manufactures a wait. And because the container of a restarted server is
already up again within a second or two, such a wait ends at exactly `ADS_START_MIN_DWELL`. Navigation
gating keeps the stopped-only rule — a running server nobody asked to restart is not a start at all.

**Nothing buys a way past the room.** Until 2026-08-31 a start credit did, silently: the interceptor
spent one on the Start click and the user went straight to their console. That was removed when the
currency became the start queue's. Two waits — one that charges for priority and one that can be
dismissed with the same coin — is not a rule anyone can hold in their head, so tokens now buy priority in
[plugged-host-queue](../plugged-host-queue/README.md) and nothing at all shortens this room. The only
relief from inside it is the grace pass opened on the way out. The two dwell settings are therefore the
whole revenue dial, and the dwell also stands between a customer and their server, so raise it carefully.

**Where the gate bites, and why.** On the Start click itself, through Livewire's `call` hook. Both of the
panel's start surfaces round-trip to PHP before wings hears anything — the console header action runs its
`->action()` closure server-side (the websocket event is emitted *by* that closure), and the server-list
row menu dispatches `powerAction`, which Livewire delivers back as a `__dispatch` call. So the click is
interceptable, including from devtools: `$wire.mountAction('start')` arrives at the same hook. What is
*not* interceptable stays that way by design: the client API, scheduled power tasks, and wings' own
crash-restart.

Merely opening a page of a stopped oversized server does **not** gate. `ADS_START_GATE_ON_NAVIGATION=true`
restores that behaviour if you want it — it additionally catches starts issued outside the panel, at the
cost of charging an ad view to open the file manager of a server that happens to be down.

**Which servers.** `cpu > ADS_START_CPU` **or** `memory > ADS_START_MEMORY` **or**
`disk > ADS_START_DISK`. The defaults are exactly plugged-host-core's `starter` preset — the lowest of
its six — so the rule is "bigger than the smallest plan on offer". A limit of `0` on a Pelican server
means *unlimited* and is always over the line — which is why the check is
`value === 0 || value > threshold`, not just `>`.

**It fails open, everywhere.** The middleware body, every container-status read and every service call
in the gate is inside a `try/catch` that lets the request through. `Server::retrieveStatus()` does not
catch `ConnectionException`, so a node that is down would otherwise throw on a page the customer needs
most. An unreachable node is logged as a `failopen` event (throttled per user+server) and the user goes
straight to their console.

### Waiting-room ads

The waiting room is the one surface where this mechanic actually earns, so it carries more formats than
the rest of the panel — and each one is still its own switch, under **Start Gate — Waiting Room Ads**:

| Switch | Default | What it adds to the page |
|---|---|---|
| `ADS_START_GATE_BANNER` | `true` | The leaderboard (`728x90`/`468x60`/`320x50`, whichever fits). After it come the pool zones the page has not used, up to six (see *Banner pool*) |
| `ADS_START_GATE_NATIVE` | `true` | The Native Banner |
| `ADS_START_GATE_SOCIAL_BAR` | `true` | The Social Bar zone, if one is configured — whether or not it is switched on panel-wide |
| `ADS_START_GATE_POPUNDER` | `true` | The Popunder zone. With `ADS_ADSTERRA_POPUNDER_PANEL_WIDE` off this is the only page it runs on, and a page the user is already waiting on is the least intrusive place for it |
| `ADS_START_GATE_INTERSTITIAL` | `true` | `ADS_ADSTERRA_GATE_SCRIPT`, a zone of the waiting room's own — an ordinary Social Bar or Vignette zone, kept separate from the panel-wide one so what the room earns is attributable and the two never fight for the same corner of the screen |
| `ADS_START_GATE_DIRECT_LINK` | `false` | A "visit our sponsor" button opening `ADS_ADSTERRA_DIRECT_LINK` in a new tab — `rel="noopener nofollow sponsored"`, and always a click the user chooses to make. Nothing opens on its own |

**Layout.** The first two units sit directly under the status card, above everything else — this page is
not a progress bar with ads attached, it is the moment the server is paid for — and the rest go into a
second block below it, so the skyscrapers and the rest of the pool are not all crammed above the fold. Both
blocks are a two-column grid from `lg` up, which on a desktop panel puts two units in one screen instead
of one unit and a scroll, and each unit keeps its reserved `min-height` so the page does not jump as they
fill. The sidebar, its toggles and the breadcrumbs are hidden for the duration, which both widens the
grid and removes every link that could take the user off the page mid-wait.

Each unit renders inside `wire:ignore`, because the page re-renders itself every two seconds and a
Livewire morph would strip whatever the ad script had appended. Top-document scripts (Social Bar,
Popunder, the waiting-room zone) are emitted from the `BODY_END` render hook rather than from the page
component, for the same reason: a `<script>` that arrives inside a morph is never executed, and one inside
the polled component would be re-evaluated on every tick.

An ad-blocked user is released on exactly the same terms as everyone else. The page probes the ad hosts
and records the result on the release event, so the real fill rate is a number rather than a guess — but
it never refuses to let someone reach their own server over a browser extension.

### What this is not: incentivised traffic

Nothing here pays a user to look at an Adsterra unit. The waiting room is an interstitial in front of an
action the user asked for, which is what every network sells vignette and interstitial inventory as — the
user is waiting for their own server, and the ads are what the wait is spent on. The room itself rewards
nothing: no impression, click or dwell second on that page moves a balance, and there is no code here that
could tell whether a creative was even seen.

Tokens are deliberately earned somewhere else, and they are not spent here at all. The only source is
completing a **Linkvertise** trip — Linkvertise's own monetised flow, under their terms, verified by their
anti-bypass API, which pays for finishing their pages rather than for viewing ours. What the token then
buys is a place further up the start queue in another plugin, never the absence of an Adsterra impression.
That is the line this feature keeps: an Adsterra view is never the thing being rewarded, in either
direction, and no balance can shorten the room this plugin puts in front of a start.

There is no rewarded video, either. `ADS_START_GATE_INTERSTITIAL` is an ordinary Social Bar or Vignette
zone; Adsterra publishes no rewarded-video format, and if a creative in that zone happens to be a video,
nothing here knows or cares whether it played. If a network with real rewarded inventory and an S2S
postback is ever signed, that is a new feature with its own verification, not a flag on this one.

## Earning tokens

Tokens are the panel's second currency: `plugged-host-economy` owns the balance, `plugged-host-queue`
spends it on start-queue priority, and this plugin is one of the places it is earned. Until 2026-08-31 the
same balance was called "start credits", lived in this plugin's own tables, and bought a silent skip past
the waiting room; the tables were renamed into the economy's
(`database/migrations/002_rename_start_credits_to_tokens.php`) and the skip was deleted. What stayed
behind is the `plugged-host-ads.start_credits` config namespace and its `ADS_START_*` environment keys —
renaming a settings namespace would have meant rewriting a live `.env` for no behavioural gain.

This plugin still owns every rule that must not vary between providers: whose claim it is, whether it is
still fresh, whether that provider token has been redeemed before, and whether the daily cap is spent.
`TokenEarnService` keeps all of it, holds `token_claims`, and calls the economy's `TokenService::add()`
for the payout — inside the same transaction that marks the claim granted, so a ledger that is unreachable
leaves the claim retryable instead of leaving a user with a spent provider token and nothing to show.

**Earning: Linkvertise.** The earn page is `EarnTokensPage` in the app panel (`/tokens`, sidebar entry
"Tokens"). It shows the balance, what tokens buy — read out of `plugged-host-queue`'s config, so the page
still reads correctly with the queue uninstalled — today's cap usage, the recent ledger, and one earn link
per configured provider. Two integrations live under the same settings, and
`ADS_START_LINKVERTISE_USER_ID` is the switch:

| `USER_ID` | Integration | How the trip works |
|---|---|---|
| set | **Full Script API** (what the publisher dashboard documents) | The page loads `linkvertise.js` and the earn anchor — our own return URL — is rewritten in place into a `link-to.net/<id>/…/dynamic/?r=…&v=2` trip |
| empty | the older static link | `ADS_START_LINKVERTISE_LINK` is a publisher link pasted in by hand, and the page redirects to it |

`ADS_START_LINKVERTISE_TOKEN` (the Anti-Bypass "Link-Verification" token) is required either way: without
it a completion cannot be checked, so the provider reports itself unconfigured and stays dark rather than
handing out unverified tokens.

**The whitelist is a correctness requirement, not tuning.** `linkvertise.js` registers a single
`DOMContentLoaded` handler and rewrites every `a[href]` it is allowed to match — and it reads an *empty*
whitelist as "every link in the document", which on a panel page means the sidebar, the console and the
file manager. `ADS_START_LINKVERTISE_WHITELIST` is therefore built server-side, never allowed to be
empty, and defaults to the literal `lvr=`, which matches our own earn anchor and nothing else, because
that is the query key the return URL carries. The script compiles these fragments into a regex (only `*`
is expanded, into `.*`), so regex metacharacters do not belong in them and are dropped on save — a `?` in
a pattern would be read as a quantifier and silently stop matching.

Two consequences of "rewrites once, at load" are designed around: the anchor has to be in the HTML the
page ships, so the claim is opened at **render** rather than on a click and reused while it is still
pending — otherwise every reload of the earn page would open another row and burn another attempt against
the throttle — and the script is emitted from `BODY_END` on this one route only, gated on
`EarnTokensPage::isCurrentRoute()`.

**Verification fails closed.** On the way back, `TokenEarnService::redeem()` matches the returning
`lvr=<nonce>` to a pending claim, then POSTs the provider's `hash` to Linkvertise's
`api/v1/anti_bypassing` endpoint with the publisher token. Anything other than a positive answer — a
network error, a timeout, a malformed body — grants nothing. Replay is a database constraint rather than
a race the application has to win: `unique(provider, token_hash)` on `token_claims`, with the
token stored as a sha256 hex digest because it is a bearer credential there is no reason to retain. On top
of that, `ADS_START_LINKVERTISE_DAILY_CAP` bounds successful claims per user per day, an attempt throttle
derived from that cap bounds *attempts* per hour, and `ADS_START_CLAIM_TTL` bounds how long an abandoned
claim stays redeemable.

**If nobody can earn, look here first.** Anti-Bypass has to be enabled on the Linkvertise account itself,
or no hash ever reaches the return URL and every redemption comes back `no_token`. That case logs the
query parameter **names** it did receive — names only, never values — onto the claim's metadata, which is
what makes the difference between "misconfigured dashboard" and "broken code" visible without guessing.

## Configuration & environment variables

Everything is settable from **Admin Area → Plugins → plugged.host Ads → Settings**, which writes these
keys into `pelican/.env`.

| Variable | Default | Description |
|---|---|---|
| `ADS_ENABLED` | `true` | Master switch for every ad and the ad-block response |
| `ADS_SHOW_APP_PANEL` | `true` | Show ads on the main user panel |
| `ADS_SHOW_SERVER_PANEL` | `true` | Show ads on server management pages |
| `ADS_SHOW_ADMIN_PANEL` | `false` | Show ads in the administrator area |
| `ADS_SHOW_AUTH_PAGES` | `true` | Show ads on login and registration pages |
| `ADS_Z_INDEX` | `0` | Stacking order of the ad slot |
| `ADS_EXEMPT_ROLES` | `""` | Comma-separated role names exempt from ads |
| `ADS_ADSTERRA_BANNER_KEY` | `""` | Banner `key`; empty disables the banner |
| `ADS_ADSTERRA_BANNER_HOST` | `www.highperformanceformat.com` | Host serving the banner's `invoke.js` |
| `ADS_ADSTERRA_BANNER_SIZE` | `728x90` | One of `728x90`, `468x60`, `320x50`, `300x250`, `160x600`, `160x300` |
| `ADS_ADSTERRA_NATIVE_SCRIPT` | `""` | Native Banner script URL; empty disables it |
| `ADS_ADSTERRA_NATIVE_CONTAINER` | `""` | Native Banner container `id` (derived from the URL if omitted) |
| `ADS_ADSTERRA_NATIVE_HEIGHT` | `320` | Reserved height in px when the native unit is isolated |
| `ADS_ADSTERRA_SOCIAL_BAR_SCRIPT` | `""` | Social Bar script URL; empty disables it |
| `ADS_ADSTERRA_SOCIAL_BAR_PANEL_WIDE` | `true` | Load the Social Bar on every panel page that shows ads. Off confines the zone to the waiting room |
| `ADS_ADSTERRA_POPUNDER_SCRIPT` | `""` | Popunder script URL; empty disables it |
| `ADS_ADSTERRA_POPUNDER_PANEL_WIDE` | `false` | Load the Popunder across the panel. Leave off: the zone then runs on the waiting room only |
| `ADS_ADSTERRA_ISOLATE` | `true` | Sandbox the Native Banner |
| `ADS_ADSTERRA_BANNER_ISOLATE` | `true` | Sandbox the display Banner |
| `ADS_ADSTERRA_FRAME_SANDBOX` | `allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms` | `sandbox` attribute for isolated units |
| `ADS_ADSTERRA_GATE_SCRIPT` | `""` | Social Bar / Vignette script URL for the waiting room's own zone; empty disables it |
| `ADS_ADSTERRA_DIRECT_LINK` | `""` | Adsterra Direct Link landing URL for the waiting room's sponsor button. Must be `https://` |
| `ADS_ANTIBLOCK_ENABLED` | `false` | Turn ad-block detection on |
| `ADS_ANTIBLOCK_MODE` | `notice` | `notice`, `modal`, or `block` |
| `ADS_ANTIBLOCK_GRACE` | `4` | Seconds before the modal's "Continue anyway" unlocks |
| `ADS_ANTIBLOCK_DISMISS_HOURS` | `12` | How long a dismissal is remembered (ignored in `block`) |
| `ADS_ANTIBLOCK_PROBE` | `true` | Also test whether the ad hosts are reachable |
| `ADS_ANTIBLOCK_SKIP_CONSOLE` | `true` | Never run on the server console |
| `ADS_ANTIBLOCK_MESSAGE` | `""` | Replaces the default body text |
| `ADS_START_ENABLED` | `false` | Turn the start gate on. Needs `ADS_ENABLED` and one configured Adsterra unit |
| `ADS_START_CPU` | `100` | Gate servers above this CPU %. `0` on a server means unlimited and always gates |
| `ADS_START_MEMORY` | `2048` | Gate servers above this memory in MB |
| `ADS_START_DISK` | `10240` | Gate servers above this disk in MB |
| `ADS_START_MIN_DWELL` | `15` | Never release earlier, even once the container is up. A container that boots in three seconds would otherwise show an ad for three seconds |
| `ADS_START_MAX_DWELL` | `30` | Always release by here, container up or not. Raised to the minimum if set below it |
| `ADS_START_PASS_MINUTES` | `10` | How long restarts of the same server stay free after the gate is satisfied |
| `ADS_START_CLAIM_TTL` | `30` | Minutes an unfinished earn trip stays redeemable |
| `ADS_START_LINKVERTISE_ENABLED` | `false` | Offer Linkvertise on the earn page |
| `ADS_START_LINKVERTISE_USER_ID` | `""` | Numeric publisher id. Set = Full Script API; empty = the static link below |
| `ADS_START_LINKVERTISE_WHITELIST` | `lvr=` | Link fragments `linkvertise.js` may rewrite. **Never empty** — empty means every link on the page. No regex metacharacters |
| `ADS_START_LINKVERTISE_LINK` | `""` | Publisher link for the static-link mode. Must be `https://` |
| `ADS_START_LINKVERTISE_TOKEN` | `""` | Anti-Bypass API token. Required in both modes; without it the provider stays off |
| `ADS_START_LINKVERTISE_REWARD` | `1` | Tokens per verified completion |
| `ADS_START_LINKVERTISE_DAILY_CAP` | `5` | Verified completions per user per day. `0` means no cap, which is not recommended |
| `ADS_START_GATE_ON_NAVIGATION` | `false` | Also gate opening any page of a stopped, oversized server, not just the Start click |
| `ADS_START_GATE_BANNER` | `true` | Leaderboard on the waiting page |
| `ADS_START_GATE_NATIVE` | `true` | Native Banner on the waiting page |
| `ADS_START_GATE_SOCIAL_BAR` | `true` | Load the Social Bar zone there, panel-wide or not |
| `ADS_START_GATE_POPUNDER` | `true` | Load the Popunder zone there, panel-wide or not |
| `ADS_START_GATE_INTERSTITIAL` | `true` | Load `ADS_ADSTERRA_GATE_SCRIPT`, the waiting room's own zone |
| `ADS_START_GATE_DIRECT_LINK` | `false` | Show the sponsor button for `ADS_ADSTERRA_DIRECT_LINK` |

Free-text values are flattened to a single line and their double quotes converted to single quotes
before being written: the `.env` writer emits a value containing both quote characters in a form
Dotenv refuses to parse, which would take the whole panel down on the next request.

## Where it injects

| Render hook | Content |
|---|---|
| `CONTENT_END`, `SIMPLE_LAYOUT_END` | The ad slot: notice anchor, leaderboard and native frames |
| `PAGE_HEADER_WIDGETS_AFTER` | The `server-tools` strip on server pages (by page class: not the console or waiting pages) |
| `BODY_END` | Adsterra Social Bar and Popunder scripts; the waiting room's own zone; `linkvertise.js` on the earn page only |
| `STYLES_AFTER` | Ad-block notice/overlay CSS (unconditional while the feature is on) |
| `SCRIPTS_AFTER` | Ad-block detector, gated per page; the unit script (size picking + refresh) wherever ads show |

Every hook body is wrapped in `try/catch (Throwable) { return null; }`. An ad plugin must never be able
to take a page down.

The waiting room is a page, not a hook: `StartGatePage` in the server panel, slug `start`, never in the
navigation. It carries its own ad stack, and its CSS — including the rules that hide the sidebar — sits in
a `<style>` inside its body rather than at a head hook: `wire:navigate` swaps the body but never removes a
`<style>` from `<head>`, so page-scoped CSS injected into the head would follow the user around the panel
for the rest of the SPA session, and a hidden sidebar is exactly the kind of leak that would not be
noticed until a customer reported it. Both entries to this page and the exit from it are full loads
(`navigate: false`), so a body-scoped block cannot leak either way. The earn page is the counterpart:
`EarnTokensPage` in the app panel, slug `tokens`, and the one route where `linkvertise.js` is
allowed to load.

## Operational notes

- After changing plugin code or `.env`: `sudo -u www-data php artisan optimize:clear`. Do **not** run
  `config:cache` — plugin configs are read at runtime and settings live in `.env`.
- This plugin ships two migrations, so a deploy needs `sudo -u www-data php artisan migrate`. `001_`
  creates four tables under their original names; `002_` renames three of them —
  `ads_start_credits` → `user_tokens` (and its `credits` column → `tokens`),
  `ads_start_credit_transactions` → `token_transactions`, `ads_start_credit_claims` → `token_claims` —
  handing the first two to `plugged-host-economy` and keeping `token_claims` here. `RENAME TABLE` rather
  than a copy, so the foreign keys and `ads_claims_provider_token_unique` survive; that index is the only
  thing standing between a replayed provider token and a double payout, which is also why
  `TokenEarnService::isDuplicate()` still matches it by that name. Every step in both files is guarded on
  the current shape, so a half-applied run is finished off by running it again. `ads_start_gate_events` is
  deliberately left alone — it never held a balance and its `outcome` column still carries historical
  `credit` rows. Until it exists the gate still works, because the event write is inside the same fail-open
  `try/catch` as everything else, so a forgotten migration costs the measurement rather than the feature.
  Earning is not so forgiving: without `token_claims` no claim can be opened and nobody can earn.
- No `yarn build` is needed; this plugin ships no Vite assets.
- The gate is enforced in Livewire's `call` hook, registered with `Livewire\before('call', …)` — before
  `SupportEvents`, which handles `__dispatch` from an ordinary `on('call')` listener and invokes the
  listener method itself, so a later registration would run after the power signal had already gone out.
  The interceptor then sends the power action itself; only a dispatch that fails leaves an intent in the
  cache for the waiting room to re-issue on release.
- `ADS_START_GATE_ON_NAVIGATION` is checked inside the non-persistent tenant middleware that is always
  registered on the server panel; with the flag off it returns immediately. Non-persistent matters:
  persistent middleware also runs on `POST /livewire/update`, where a redirect is swallowed as component
  HTML and would kill the poll that releases the user.
- With the ad-block response in `modal` or `block` mode, its overlay will also appear on the gate page —
  a blocked user then sees two things asking for the same thing. Ship the gate with `notice`.
- Turning the gate on has prerequisites outside this plugin: forcing ad views as a condition of service
  has to be disclosed, and so does sending users to a third-party link shortener to earn tokens. That
  means editing `terms.html` / `privacy.html` in the noprofit.space site (repo noprofit-space/site)
  **and** bumping the Terms and Privacy versions on noprofit.space (Admin › Settings), so the new
  versions are recorded as accepted.
- `ads_start_gate_events` is the measurement, not decoration. Its `outcome` column (`ads`, `exempt`,
  `failopen`, plus historical `credit` rows) is what tells you whether the mechanic earns anything and how
  often a node being unreachable is letting people straight through. The release rows also carry
  `dispatched` = `click` | `release` | `none` in their metadata, which is how the start-on-click path stays
  distinguishable from a dispatch that had to be replayed.
- There is no ad-free start any more, by design. Every gated start is paid for with the room; a token
  balance changes where its owner sits in the start queue and nothing else. If you want the old "one free
  start a day" behaviour back, it is a new feature, not a setting that was hidden.
- Linkvertise needs Anti-Bypass switched on in the publisher dashboard, not just a token pasted here.
  Without it the return trip carries no hash, `redeem()` answers `no_token`, and nobody can ever earn.
- The panel vhost's CSP has no `script-src`, so Adsterra needs no nginx change **on the panel**. The
  static site (`plugged.host`) sets `default-src 'self'; script-src 'self'; style-src 'self'`, so
  putting Adsterra there would require loosening that CSP — deliberately not done here.
- Adsterra rotates serving hosts. A unit that suddenly stops filling is usually a stale
  `ADS_ADSTERRA_*_HOST`/`*_SCRIPT` value, not a bug.

## Unit markup

Layout — margins, centring, z-index — lives on the enclosing slot, so every unit stacks consistently
and each unit's own markup is just the frame. Every one of them carries `data-ph-unit`, and that
attribute is what the collapse signal in the ad-block detector counts. Keep it on anything you add,
or a blocked page will look like a page with no ads on it.

## a-ads is gone

Version 1.2.0 removed the a-ads unit; Adsterra is the only network. `ADS_PROVIDER`, `ADS_UNIT_ID` and
`ADS_IFRAME_WIDTH` are no longer read, so leaving them in `.env` is harmless but pointless — delete
them. If nothing was ever pasted into the Adsterra fields, removing a-ads means the slot now renders
nothing at all: `hasUnit()` is exactly "Adsterra is configured", and with it false the slot, the
detector and the start gate all stand down together.
