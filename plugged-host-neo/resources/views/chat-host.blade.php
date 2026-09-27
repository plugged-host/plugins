{{--
    Host for the neo chat, rendered from NeoPluginProvider's BODY_END hook inside a try/catch
    (a render hook that throws takes the whole panel down, so it must render there, not later).
    No `key:` on @livewire, as with theme-v2's balance host: a key makes Livewire look for a
    parent component that does not exist here.

    Styles are plain CSS on theme-v2's --phv2-* tokens (the fallbacks are the same values) rather
    than Tailwind classes, so the plugin needs no asset build. Mobile first in behaviour: under 640px the
    chat is a full-screen sheet with safe-area padding and an icon-only launcher.
--}}
<style>
    /* Every colour is a theme-v2 token (fallbacks are the same values, for a panel without the theme).
       Flat surfaces only: the launcher is a neutral pill (it sits next to each page's own primary
       button), the open panel is an overlay and carries the modal elevation, the send button is the
       one accent fill inside the chat. */
    .neo-root { position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 40; font-size: .875rem; line-height: 1.45;
        --neo-accent: var(--phv2-accent, #1d4ed8); --neo-accent-hover: var(--phv2-accent-hover, #1e40af);
        --neo-accent-text: var(--phv2-accent-bright, #3b82f6); --neo-accent-text-hover: var(--phv2-accent-glow, #60a5fa);
        --neo-accent-soft: var(--phv2-accent-soft, rgb(29 78 216 / .1));
        --neo-line: var(--phv2-hairline, rgb(255 255 255 / .05)); --neo-text: var(--phv2-text, #fff);
        --neo-nav: var(--phv2-text-nav, #d1d5db); --neo-muted: var(--phv2-text-muted, #9ca3af); --neo-faint: var(--phv2-text-faint, #71717a);
        --neo-raise: var(--phv2-raise, rgb(255 255 255 / .05)); --neo-raise-2: var(--phv2-raise-2, rgb(255 255 255 / .1));
        --neo-raise-3: var(--phv2-raise-3, rgb(255 255 255 / .15)); --neo-sunken: var(--phv2-sunken, rgb(15 17 22 / .5));
        --neo-modal: var(--phv2-modal, #1a1c20); --neo-dropdown: var(--phv2-dropdown, #181a1e);
        --neo-success: #22c55e; --neo-warning: #f59e0b; --neo-warning-text: #fbbf24; --neo-danger: #ef4444; --neo-danger-text: #f87171; }
    .neo-i { width: 1.25rem; height: 1.25rem; flex: none; }
    .neo-i-sm { width: .875rem; height: .875rem; flex: none; }
    .neo-spin { animation: neo-spin .9s linear infinite; }
    @keyframes neo-spin { to { transform: rotate(360deg); } }
    @keyframes neo-dot { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }

    .neo-launcher { position: relative; display: inline-flex; align-items: center; gap: .5rem; min-height: 2.75rem; padding: 0 1.25rem 0 1rem; border-radius: 9999px; border: 1px solid var(--neo-line); background: var(--neo-dropdown); color: var(--neo-text); font-weight: 600; cursor: pointer; transition: background-color .15s ease, border-color .15s ease, transform .1s ease; }
    .neo-launcher:hover { background: color-mix(in srgb, var(--neo-dropdown), #fff 5%); border-color: var(--neo-raise-2); }
    .neo-launcher:active { transform: scale(.97); }
    .neo-launcher-dot { position: absolute; top: .25rem; right: .25rem; width: .5rem; height: .5rem; border-radius: 9999px; outline: 2px solid var(--neo-dropdown); }
    .neo-dot-approve { background: var(--neo-warning); }
    .neo-dot-done { background: var(--neo-success); }
    .neo-launcher-off { width: 2.5rem; min-height: 2.5rem; height: 2.5rem; padding: 0; justify-content: center; color: var(--neo-muted); opacity: .55; }
    .neo-launcher-off:hover { opacity: 1; }
    .neo-grip { position: absolute; top: 0; left: 0; width: 1.25rem; height: 1.25rem; z-index: 2; cursor: nwse-resize; touch-action: none; }
    .neo-grip::before { content: ''; position: absolute; top: .375rem; left: .375rem; width: .5rem; height: .5rem; border-top: 2px solid var(--neo-faint); border-left: 2px solid var(--neo-faint); border-top-left-radius: .25rem; opacity: .45; transition: opacity .15s; }
    .neo-grip:hover::before { opacity: 1; }
    html.neo-resizing, html.neo-resizing * { user-select: none !important; cursor: nwse-resize !important; }

    .neo-panel { position: absolute; right: 0; bottom: 3.6rem; width: var(--neo-w, 24rem); height: var(--neo-h, min(36rem, calc(100vh - 7rem))); max-width: calc(100vw - 2rem); max-height: calc(100vh - 6rem); display: flex; flex-direction: column; border-radius: 1rem; border: 1px solid var(--neo-line); background: var(--neo-modal); color: var(--neo-text); box-shadow: 0 25px 50px -12px rgb(0 0 0 / .5); overflow: hidden; }
    .neo-t { transition: opacity .16s ease, transform .16s ease; }
    .neo-t-from { opacity: 0; transform: translateY(8px); }
    .neo-t-to { opacity: 1; transform: none; }

    .neo-head { display: flex; align-items: center; gap: .25rem; min-height: 3.5rem; padding: .5rem .5rem .5rem 1rem; border-bottom: 1px solid var(--neo-line); }
    .neo-head > .neo-iconbtn:first-child { margin-left: -.5rem; }
    .neo-head-text { flex: 1; min-width: 0; }
    .neo-title { font-weight: 600; font-size: 1rem; line-height: 1.25; color: var(--neo-text); }
    .neo-sub { font-size: .75rem; color: var(--neo-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .neo-iconbtn { width: 2.25rem; height: 2.25rem; flex: none; display: grid; place-items: center; border-radius: .5rem; color: var(--neo-muted); transition: background-color .15s ease, color .15s ease; }
    .neo-iconbtn:hover { background: var(--neo-raise); color: var(--neo-text); }
    .neo-iconbtn:disabled { opacity: .35; pointer-events: none; }

    .neo-body { flex: 1; overflow-y: auto; overscroll-behavior: contain; padding: 1rem; display: flex; flex-direction: column; gap: .5rem; }
    .neo-empty { margin: auto 0; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1rem; padding: 1rem .25rem; color: var(--neo-muted); font-size: .8125rem; }
    .neo-greet { font-weight: 600; font-size: 1rem; color: var(--neo-text); margin-bottom: .25rem; }
    .neo-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem; }
    .neo-chip { display: inline-flex; align-items: center; min-height: 2.125rem; padding: .375rem .875rem; border-radius: 9999px; background: var(--neo-raise-2); color: var(--neo-text); font-size: .8125rem; font-weight: 500; transition: background-color .15s ease; }
    .neo-chip:hover { background: var(--neo-raise-3); }

    .neo-msg-user { align-self: flex-end; max-width: 85%; padding: .5rem .75rem; border-radius: .75rem .75rem .25rem .75rem; background: var(--neo-raise-2); color: var(--neo-text); white-space: pre-wrap; word-break: break-word; }
    .neo-msg-ai { max-width: 100%; word-break: break-word; color: var(--neo-nav); }
    .neo-msg-ai p { margin: 0 0 .5rem; } .neo-msg-ai p:last-child { margin-bottom: 0; }
    .neo-msg-ai ul, .neo-msg-ai ol { margin: .25rem 0 .5rem 1.125rem; list-style: disc; } .neo-msg-ai ol { list-style: decimal; }
    .neo-msg-ai code { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .75rem; padding: .0625rem .25rem; border-radius: .25rem; background: var(--neo-sunken); }
    .neo-msg-ai pre { margin: .5rem 0; padding: .625rem; border-radius: .5rem; background: var(--neo-sunken); overflow-x: auto; }
    .neo-msg-ai pre code { padding: 0; background: none; }
    .neo-msg-ai a { color: var(--neo-accent-text); text-decoration: underline; }
    .neo-msg-ai a:hover { color: var(--neo-accent-text-hover); }
    .neo-msg-ai strong { color: var(--neo-text); }

    .neo-tile { display: flex; align-items: center; gap: .5rem; padding: .375rem .625rem; border-radius: .5rem; background: var(--neo-raise); font-size: .75rem; color: var(--neo-muted); }
    .neo-tile-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--neo-nav); }
    .neo-ok { color: var(--neo-success); } .neo-bad { color: var(--neo-danger-text); } .neo-warn { color: var(--neo-warning-text); } .neo-dim { color: var(--neo-faint); }
    .neo-card { border-radius: .75rem; padding: .625rem .75rem .75rem; display: flex; flex-direction: column; gap: .5rem; background: color-mix(in oklab, var(--neo-warning) 12%, transparent); }
    .neo-card .neo-tile { background: none; padding: 0; }
    .neo-card .neo-tile-label { color: var(--neo-text); font-weight: 500; }
    .neo-preview summary { cursor: pointer; list-style: none; font-size: .75rem; color: var(--neo-muted); transition: color .15s ease; }
    .neo-preview summary:hover { color: var(--neo-text); }
    .neo-preview summary::-webkit-details-marker { display: none; }
    .neo-preview pre { margin-top: .375rem; max-height: 10rem; overflow: auto; padding: .5rem; border-radius: .5rem; background: var(--neo-sunken); font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .75rem; white-space: pre-wrap; word-break: break-word; }
    .neo-row { display: flex; gap: .5rem; }
    {{-- .neo-btn on its own is the secondary button; -primary is the accent fill, -ghost the text-only one. --}}
    .neo-btn { display: inline-flex; align-items: center; justify-content: center; gap: .375rem; min-height: 2.125rem; border-radius: 9999px; padding: .375rem .875rem; font-weight: 600; font-size: .8125rem; background: var(--neo-raise-2); color: var(--neo-text); transition: background-color .15s ease, color .15s ease, transform .1s ease; }
    .neo-btn:hover { background: var(--neo-raise-3); }
    .neo-btn:active { transform: scale(.97); }
    .neo-btn-primary { background: var(--neo-accent); color: #fff; }
    .neo-btn-primary:hover { background: var(--neo-accent-hover); }
    .neo-btn-ghost { background: transparent; color: var(--neo-muted); }
    .neo-btn-ghost:hover { background: var(--neo-raise); color: var(--neo-text); }
    .neo-btn:disabled { opacity: .45; pointer-events: none; }
    .neo-grow { flex: 1; }

    .neo-notice { display: flex; gap: .5rem; align-items: flex-start; font-size: .8125rem; padding: .625rem .75rem; border-radius: .75rem; background: color-mix(in oklab, var(--neo-warning) 12%, transparent); color: var(--neo-nav); }
    .neo-notice > .neo-i-sm { color: var(--neo-warning-text); margin-top: .1875rem; }
    .neo-notice-body { flex: 1; display: flex; flex-direction: column; gap: .5rem; align-items: flex-start; }
    .neo-typing { display: inline-flex; gap: .25rem; padding: .625rem .75rem; border-radius: .75rem; background: var(--neo-raise); align-self: flex-start; }
    .neo-typing i { width: .375rem; height: .375rem; border-radius: 9999px; background: var(--neo-muted); animation: neo-dot 1.1s infinite ease-in-out; }
    .neo-typing i:nth-child(2) { animation-delay: .15s; } .neo-typing i:nth-child(3) { animation-delay: .3s; }

    .neo-foot { border-top: 1px solid var(--neo-line); padding: .625rem .75rem .75rem; display: flex; flex-direction: column; gap: .5rem; }
    .neo-meter { display: flex; gap: .75rem; padding: 0 .25rem; font-size: .6875rem; font-weight: 600; color: var(--neo-faint); font-variant-numeric: tabular-nums; }
    .neo-meter-item { flex: 1; min-width: 0; display: flex; align-items: center; gap: .5rem; }
    .neo-bar { flex: 1; height: .25rem; border-radius: 9999px; background: var(--neo-raise); overflow: hidden; }
    .neo-bar > i { display: block; height: 100%; min-width: .25rem; border-radius: inherit; background: var(--neo-accent-text); }
    .neo-bar.neo-bar-warn > i { background: var(--neo-warning); } .neo-bar.neo-bar-full > i { background: var(--neo-danger); }
    .neo-flash { display: flex; align-items: center; gap: .375rem; font-size: .75rem; color: var(--neo-danger-text); padding: 0 .25rem; }
    .neo-compose { display: flex; align-items: flex-end; gap: .375rem; padding: .3rem .3rem .3rem .4rem; border-radius: .75rem; border: 1px solid var(--neo-line); background: var(--neo-sunken); transition: border-color .15s ease; }
    .neo-compose:focus-within { border-color: var(--neo-accent-text); }
    .neo-root .neo-input { flex: 1; resize: none; border: 0; background: transparent; color: var(--neo-text); padding: .45rem .35rem; font-size: .875rem; line-height: 1.4; min-height: 2.2rem; max-height: 10rem; outline: none; box-shadow: none; }
    .neo-root .neo-input::placeholder { color: var(--neo-faint); }
    .neo-root .neo-input:focus { outline: none; box-shadow: none; }
    .neo-send { width: 2.25rem; height: 2.25rem; flex: none; display: grid; place-items: center; border-radius: 9999px; background: var(--neo-accent); color: #fff; transition: background-color .15s ease, opacity .15s ease, transform .1s ease; }
    .neo-send:hover { background: var(--neo-accent-hover); }
    .neo-send:active { transform: scale(.97); }
    .neo-send:disabled { opacity: .35; }
    .neo-send-stop { background: var(--neo-raise-2); color: var(--neo-text); }
    .neo-send-stop:hover { background: color-mix(in oklab, var(--neo-danger) 15%, transparent); color: var(--neo-danger-text); }

    .neo-settings { flex: 1; overflow-y: auto; overscroll-behavior: contain; padding: .5rem .75rem 1rem; display: flex; flex-direction: column; gap: .25rem; }
    .neo-set-card { display: flex; flex-direction: column; gap: .75rem; padding: 1rem .25rem; }
    .neo-set-card + .neo-set-card { border-top: 1px solid var(--neo-line); }
    .neo-set-row { display: flex; align-items: flex-start; gap: .75rem; }
    .neo-set-text { flex: 1; min-width: 0; }
    .neo-set-title { font-weight: 600; font-size: .875rem; color: var(--neo-text); }
    .neo-set-hint { font-size: .75rem; color: var(--neo-muted); line-height: 1.45; }
    .neo-switch { position: relative; width: 2.5rem; height: 1.5rem; flex: none; border-radius: 9999px; background: var(--neo-raise-3); border: 1px solid var(--neo-line); transition: background-color .2s ease; }
    .neo-switch::after { content: ''; position: absolute; top: .1875rem; left: .1875rem; width: 1rem; height: 1rem; border-radius: 9999px; background: #fff; transition: transform .2s ease; }
    .neo-switch[aria-checked="true"] { background: var(--neo-accent); border-color: transparent; }
    .neo-switch[aria-checked="true"]::after { transform: translateX(1rem); }
    .neo-usage { display: flex; flex-direction: column; gap: .375rem; }
    .neo-usage-head { display: flex; justify-content: space-between; gap: .5rem; font-size: .75rem; color: var(--neo-muted); font-variant-numeric: tabular-nums; }
    .neo-bar.neo-bar-lg { height: .375rem; flex: none; }
    .neo-small { font-size: .75rem; line-height: 1.5; color: var(--neo-faint); padding: .75rem .25rem 0; }
    .neo-hist { display: flex; align-items: center; gap: .25rem; padding-right: .25rem; border-radius: .75rem; transition: background-color .15s ease; }
    .neo-hist:hover { background: var(--neo-raise); }
    .neo-hist-current, .neo-hist-current:hover { background: var(--neo-accent-soft); }
    .neo-hist-open { flex: 1; min-width: 0; display: flex; align-items: center; padding: .625rem .75rem; text-align: left; }
    .neo-hist-text { min-width: 0; display: flex; flex-direction: column; gap: .125rem; }
    .neo-hist-title { font-size: .8125rem; font-weight: 600; color: var(--neo-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .neo-hist-meta { font-size: .75rem; color: var(--neo-faint); }
    .neo-consent { flex: 1; overflow-y: auto; padding: 1.5rem 1.25rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 1rem; font-size: .8125rem; color: var(--neo-muted); }
    .neo-consent h3 { font-weight: 600; font-size: 1rem; color: var(--neo-text); }
    .neo-consent ul { display: flex; flex-direction: column; gap: .75rem; text-align: left; }

    html.neo-lock, html.neo-lock body { overflow: hidden; }
    @media (max-width: 640px) {
        .neo-root { right: .85rem; bottom: calc(.85rem + env(safe-area-inset-bottom)); }
        .neo-launcher { width: 3rem; min-height: 3rem; height: 3rem; padding: 0; justify-content: center; }
        .neo-launcher-label { display: none; }
        .neo-panel { position: fixed; inset: 0; width: 100%; height: 100dvh; max-width: none; max-height: none; border: 0; border-radius: 0; box-shadow: none; }
        .neo-grip { display: none; }
        .neo-switch { width: 2.875rem; height: 1.75rem; }
        .neo-switch::after { width: 1.25rem; height: 1.25rem; }
        .neo-switch[aria-checked="true"]::after { transform: translateX(1.125rem); }
        .neo-head { padding-top: calc(.5rem + env(safe-area-inset-top)); }
        .neo-foot { padding-bottom: calc(.75rem + env(safe-area-inset-bottom)); }
        .neo-iconbtn { width: 2.75rem; height: 2.75rem; }
        .neo-root .neo-input { font-size: 16px; }
        .neo-send { width: 2.75rem; height: 2.75rem; }
        .neo-btn { min-height: 2.75rem; }
        .neo-chip { min-height: 2.75rem; padding: .5rem 1rem; }
        .neo-msg-user { max-width: 90%; }
    }
</style>
@livewire('plugged-host-neo.chat')
