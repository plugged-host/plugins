{{--
    Host for the neo chat, rendered from NeoPluginProvider's BODY_END hook inside a try/catch
    (a render hook that throws takes the whole panel down, so it must render there, not later).
    No `key:` on @livewire, as with theme-v2's balance host: a key makes Livewire look for a
    parent component that does not exist here.

    Styles are plain CSS on theme-v2's --phv2-* tokens (with fallbacks) rather than Tailwind
    classes, so the plugin needs no asset build. Mobile first in behaviour: under 640px the
    chat is a full-screen sheet with safe-area padding and an icon-only launcher.
--}}
<style>
    .neo-root { position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 40; font-size: .875rem; line-height: 1.45;
        --neo-line: var(--phv2-hairline, rgba(255,255,255,.08)); --neo-text: var(--phv2-text, #f4f4f5);
        --neo-muted: var(--phv2-text-muted, #a1a1aa); --neo-faint: var(--phv2-text-faint, #71717a);
        --neo-raise: var(--phv2-raise, rgba(255,255,255,.04)); --neo-raise-2: var(--phv2-raise-2, rgba(255,255,255,.08));
        --neo-sunken: var(--phv2-sunken, rgba(0,0,0,.35)); --neo-accent: var(--phv2-accent, #2563eb);
        --neo-grad: linear-gradient(135deg, #22d3ee 0%, #3b82f6 55%, #1d4ed8 100%); }
    .neo-i { width: 1.05rem; height: 1.05rem; flex: none; }
    .neo-i-sm { width: .85rem; height: .85rem; flex: none; }
    .neo-spin { animation: neo-spin .9s linear infinite; }
    @keyframes neo-spin { to { transform: rotate(360deg); } }
    @keyframes neo-pulse { 50% { opacity: .35; } }
    @keyframes neo-dot { 0%, 80%, 100% { opacity: .25; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-2px); } }

    .neo-launcher { position: relative; display: inline-flex; align-items: center; gap: .45rem; height: 2.9rem; padding: 0 1.15rem 0 1rem; border-radius: 9999px; border: 1px solid rgba(255,255,255,.16); background: var(--neo-grad); color: #fff; font-weight: 700; letter-spacing: -.01em; box-shadow: 0 10px 30px rgba(0,0,0,.45); cursor: pointer; transition: transform .15s ease, box-shadow .15s ease; }
    .neo-launcher:hover { transform: translateY(-1px); box-shadow: 0 14px 36px rgba(0,0,0,.5); }
    .neo-launcher .neo-i { width: 1.25rem; height: 1.25rem; }
    .neo-launcher-dot { position: absolute; top: .1rem; right: .1rem; width: .7rem; height: .7rem; border-radius: 9999px; box-shadow: 0 0 0 2px #0b1220; }
    .neo-dot-approve { background: #fbbf24; animation: neo-pulse 1.2s ease-in-out infinite; }
    .neo-dot-done { background: #4ade80; }
    .neo-launcher-off { width: 2.5rem; height: 2.5rem; padding: 0; justify-content: center; background: var(--neo-raise-2); border-color: var(--neo-line); color: var(--neo-muted); box-shadow: none; opacity: .55; }
    .neo-launcher-off:hover { opacity: 1; transform: none; box-shadow: none; }
    .neo-launcher-off .neo-i { width: 1.1rem; height: 1.1rem; }
    .neo-grip { position: absolute; top: 0; left: 0; width: 1.25rem; height: 1.25rem; z-index: 2; cursor: nwse-resize; touch-action: none; }
    .neo-grip::before { content: ''; position: absolute; top: .38rem; left: .38rem; width: .5rem; height: .5rem; border-top: 2px solid var(--neo-faint); border-left: 2px solid var(--neo-faint); border-top-left-radius: .25rem; opacity: .45; transition: opacity .12s; }
    .neo-grip:hover::before { opacity: 1; }
    html.neo-resizing, html.neo-resizing * { user-select: none !important; cursor: nwse-resize !important; }

    .neo-panel { position: absolute; right: 0; bottom: 3.6rem; width: var(--neo-w, 24rem); height: var(--neo-h, min(36rem, calc(100vh - 7rem))); max-width: calc(100vw - 2rem); max-height: calc(100vh - 6rem); display: flex; flex-direction: column; border-radius: 1.1rem; border: 1px solid var(--neo-line); background: var(--phv2-modal, #111318); color: var(--neo-text); box-shadow: 0 24px 60px rgba(0,0,0,.55); overflow: hidden; }
    .neo-t { transition: opacity .16s ease, transform .16s ease; }
    .neo-t-from { opacity: 0; transform: translateY(10px) scale(.985); }
    .neo-t-to { opacity: 1; transform: none; }

    .neo-head { display: flex; align-items: center; gap: .6rem; padding: .65rem .6rem .65rem .8rem; border-bottom: 1px solid var(--neo-line); }
    .neo-mark { width: 2.1rem; height: 2.1rem; flex: none; border-radius: .7rem; display: grid; place-items: center; background: var(--neo-grad); color: #fff; box-shadow: inset 0 0 0 1px rgba(255,255,255,.15); }
    .neo-mark-off { background: var(--neo-raise-2); color: var(--neo-muted); }
    .neo-head-text { flex: 1; min-width: 0; }
    .neo-title { font-weight: 800; font-size: 1rem; letter-spacing: -.03em; line-height: 1.1; background: linear-gradient(90deg, #7dd3fc, #60a5fa); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .neo-sub { display: flex; align-items: center; gap: .25rem; font-size: .72rem; color: var(--neo-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .neo-iconbtn { width: 2.15rem; height: 2.15rem; flex: none; display: grid; place-items: center; border-radius: .65rem; color: var(--neo-muted); transition: background .12s, color .12s; }
    .neo-iconbtn:hover { background: var(--neo-raise-2); color: var(--neo-text); }
    .neo-iconbtn:disabled { opacity: .35; pointer-events: none; }

    .neo-body { flex: 1; overflow-y: auto; overscroll-behavior: contain; padding: .9rem .85rem; display: flex; flex-direction: column; gap: .5rem; }
    .neo-empty { margin: auto 0; display: flex; flex-direction: column; align-items: center; text-align: center; gap: .8rem; padding: 1rem .25rem; color: var(--neo-muted); font-size: .8125rem; }
    .neo-empty .neo-mark { width: 3.1rem; height: 3.1rem; border-radius: 1rem; }
    .neo-empty .neo-mark .neo-i { width: 1.5rem; height: 1.5rem; }
    .neo-greet { font-weight: 700; font-size: .95rem; color: var(--neo-text); margin-bottom: .15rem; }
    .neo-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem; }
    .neo-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .42rem .75rem; border-radius: 9999px; border: 1px solid var(--neo-line); background: var(--neo-raise); color: var(--neo-text); font-size: .78rem; transition: border-color .12s, background .12s; }
    .neo-chip:hover { border-color: var(--phv2-accent-edge, rgba(59,130,246,.55)); background: var(--neo-raise-2); }
    .neo-chip .neo-i-sm { color: #60a5fa; }

    .neo-msg-user { align-self: flex-end; max-width: 85%; padding: .5rem .8rem; border-radius: 1rem 1rem .3rem 1rem; background: var(--neo-accent); color: #fff; white-space: pre-wrap; word-break: break-word; }
    .neo-msg-ai { max-width: 100%; word-break: break-word; }
    .neo-msg-ai p { margin: 0 0 .5rem; } .neo-msg-ai p:last-child { margin-bottom: 0; }
    .neo-msg-ai ul, .neo-msg-ai ol { margin: .25rem 0 .5rem 1.1rem; list-style: disc; } .neo-msg-ai ol { list-style: decimal; }
    .neo-msg-ai code { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .78rem; padding: .05rem .3rem; border-radius: .3rem; background: var(--neo-sunken); }
    .neo-msg-ai pre { margin: .4rem 0; padding: .6rem; border-radius: .5rem; background: var(--neo-sunken); overflow-x: auto; }
    .neo-msg-ai pre code { padding: 0; background: none; }
    .neo-msg-ai a { color: #60a5fa; text-decoration: underline; }
    .neo-msg-ai strong { color: #fff; }

    .neo-tile { display: flex; align-items: center; gap: .5rem; padding: .36rem .55rem; border-radius: .6rem; border: 1px solid var(--neo-line); background: var(--neo-raise); font-size: .78rem; color: var(--neo-muted); }
    .neo-tile-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--neo-text); }
    .neo-ok { color: #4ade80; } .neo-bad { color: #fb7185; } .neo-warn { color: #fbbf24; } .neo-dim { color: var(--neo-faint); }
    .neo-card { border: 1px solid rgba(251,191,36,.4); background: rgba(251,191,36,.06); border-radius: .75rem; padding: .5rem .55rem .55rem; display: flex; flex-direction: column; gap: .45rem; }
    .neo-card .neo-tile { border: 0; background: none; padding: 0; }
    .neo-preview summary { cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: .3rem; font-size: .72rem; color: var(--neo-muted); }
    .neo-preview summary::-webkit-details-marker { display: none; }
    .neo-preview pre { margin-top: .35rem; max-height: 10rem; overflow: auto; padding: .5rem; border-radius: .45rem; background: var(--neo-sunken); font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .72rem; white-space: pre-wrap; word-break: break-word; }
    .neo-row { display: flex; gap: .4rem; }
    .neo-btn { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; min-height: 2rem; border-radius: .6rem; padding: .35rem .75rem; font-weight: 600; font-size: .78rem; border: 1px solid var(--neo-line); color: var(--neo-text); transition: background .12s; }
    .neo-btn-primary { background: var(--neo-accent); border-color: transparent; color: #fff; }
    .neo-btn-primary:hover { background: var(--phv2-accent-hover, #1d4ed8); }
    .neo-btn-ghost:hover { background: var(--neo-raise-2); }
    .neo-btn:disabled { opacity: .45; pointer-events: none; }
    .neo-grow { flex: 1; }

    .neo-notice { display: flex; gap: .5rem; align-items: flex-start; font-size: .78rem; padding: .55rem .65rem; border-radius: .65rem; background: rgba(251,191,36,.07); border: 1px solid rgba(251,191,36,.25); color: #fde68a; }
    .neo-notice-body { flex: 1; display: flex; flex-direction: column; gap: .45rem; align-items: flex-start; }
    .neo-typing { display: inline-flex; gap: .25rem; padding: .55rem .7rem; border-radius: .9rem; background: var(--neo-raise); border: 1px solid var(--neo-line); align-self: flex-start; }
    .neo-typing i { width: .38rem; height: .38rem; border-radius: 9999px; background: #60a5fa; animation: neo-dot 1.1s infinite ease-in-out; }
    .neo-typing i:nth-child(2) { animation-delay: .15s; } .neo-typing i:nth-child(3) { animation-delay: .3s; }

    .neo-foot { border-top: 1px solid var(--neo-line); padding: .5rem .6rem .6rem; display: flex; flex-direction: column; gap: .45rem; }
    .neo-meter { display: flex; gap: .8rem; padding: 0 .2rem; font-size: .66rem; font-weight: 600; color: var(--neo-faint); }
    .neo-meter-item { flex: 1; min-width: 0; display: flex; align-items: center; gap: .4rem; }
    .neo-bar { flex: 1; height: .32rem; border-radius: 9999px; background: var(--neo-raise-2); overflow: hidden; }
    .neo-bar > i { display: block; height: 100%; min-width: .32rem; border-radius: inherit; background: linear-gradient(90deg, #22d3ee, #3b82f6); }
    .neo-bar.neo-bar-warn > i { background: #f59e0b; } .neo-bar.neo-bar-full > i { background: #f43f5e; }
    .neo-flash { display: flex; align-items: center; gap: .4rem; font-size: .74rem; color: #fda4af; padding: 0 .2rem; }
    .neo-compose { display: flex; align-items: flex-end; gap: .35rem; padding: .3rem .3rem .3rem .4rem; border-radius: .95rem; border: 1px solid var(--neo-line); background: var(--neo-sunken); transition: border-color .12s; }
    .neo-compose:focus-within { border-color: var(--phv2-accent-edge, rgba(59,130,246,.6)); }
    .neo-root .neo-input { flex: 1; resize: none; border: 0; background: transparent; color: var(--neo-text); padding: .45rem .35rem; font-size: .875rem; line-height: 1.4; min-height: 2.2rem; max-height: 10rem; outline: none; box-shadow: none; }
    .neo-root .neo-input:focus { outline: none; box-shadow: none; }
    .neo-send { width: 2.2rem; height: 2.2rem; flex: none; display: grid; place-items: center; border-radius: .7rem; background: var(--neo-accent); color: #fff; transition: background .12s, opacity .12s; }
    .neo-send:hover { background: var(--phv2-accent-hover, #1d4ed8); }
    .neo-send:disabled { opacity: .35; }
    .neo-send-stop { background: var(--neo-raise-2); color: var(--neo-text); }
    .neo-send-stop:hover { background: rgba(244,63,94,.25); color: #fda4af; }

    .neo-settings { flex: 1; overflow-y: auto; overscroll-behavior: contain; padding: .85rem; display: flex; flex-direction: column; gap: .65rem; }
    .neo-set-card { display: flex; flex-direction: column; gap: .7rem; padding: .75rem .8rem; border-radius: .85rem; border: 1px solid var(--neo-line); background: var(--neo-raise); }
    .neo-set-row { display: flex; align-items: flex-start; gap: .65rem; }
    .neo-set-row > .neo-i { color: #60a5fa; margin-top: .1rem; }
    .neo-set-text { flex: 1; min-width: 0; }
    .neo-set-title { font-weight: 600; font-size: .84rem; color: var(--neo-text); }
    .neo-set-hint { font-size: .74rem; color: var(--neo-muted); line-height: 1.45; }
    .neo-switch { position: relative; width: 2.5rem; height: 1.45rem; flex: none; border-radius: 9999px; background: var(--neo-raise-2); border: 1px solid var(--neo-line); transition: background .15s; }
    .neo-switch::after { content: ''; position: absolute; top: .15rem; left: .15rem; width: 1.05rem; height: 1.05rem; border-radius: 9999px; background: #fff; transition: transform .15s; }
    .neo-switch[aria-checked="true"] { background: var(--neo-accent); border-color: transparent; }
    .neo-switch[aria-checked="true"]::after { transform: translateX(1.05rem); }
    .neo-usage { display: flex; flex-direction: column; gap: .3rem; }
    .neo-usage-head { display: flex; justify-content: space-between; gap: .5rem; font-size: .74rem; color: var(--neo-muted); }
    .neo-bar.neo-bar-lg { height: .45rem; flex: none; }
    .neo-btn-danger { color: #fda4af; border-color: rgba(244,63,94,.35); }
    .neo-btn-danger:hover { background: rgba(244,63,94,.12); }
    .neo-small { display: flex; gap: .4rem; font-size: .72rem; line-height: 1.5; color: var(--neo-faint); padding: 0 .2rem; }
    .neo-consent { flex: 1; overflow-y: auto; padding: 1.4rem 1.1rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: .85rem; font-size: .8125rem; color: var(--neo-muted); }
    .neo-consent h3 { font-weight: 800; font-size: 1.05rem; color: var(--neo-text); }
    .neo-consent ul { display: flex; flex-direction: column; gap: .55rem; text-align: left; }
    .neo-consent li { display: flex; gap: .55rem; align-items: flex-start; }
    .neo-consent li .neo-i { color: #60a5fa; margin-top: .1rem; }

    html.neo-lock, html.neo-lock body { overflow: hidden; }
    @media (max-width: 640px) {
        .neo-root { right: .85rem; bottom: calc(.85rem + env(safe-area-inset-bottom)); }
        .neo-launcher { width: 3.2rem; height: 3.2rem; padding: 0; justify-content: center; }
        .neo-launcher-label { display: none; }
        .neo-panel { position: fixed; inset: 0; width: 100%; height: 100dvh; max-width: none; max-height: none; border: 0; border-radius: 0; box-shadow: none; }
        .neo-grip { display: none; }
        .neo-switch { width: 2.9rem; height: 1.7rem; }
        .neo-switch::after { width: 1.3rem; height: 1.3rem; }
        .neo-switch[aria-checked="true"]::after { transform: translateX(1.2rem); }
        .neo-head { padding-top: calc(.65rem + env(safe-area-inset-top)); }
        .neo-foot { padding-bottom: calc(.6rem + env(safe-area-inset-bottom)); }
        .neo-iconbtn { width: 2.6rem; height: 2.6rem; }
        .neo-root .neo-input { font-size: 16px; }
        .neo-send { width: 2.6rem; height: 2.6rem; }
        .neo-btn { min-height: 2.5rem; }
        .neo-chip { padding: .55rem .85rem; }
        .neo-msg-user { max-width: 90%; }
    }
</style>
@livewire('plugged-host-neo.chat')
