{{--
    Host for the neo chat, rendered from NeoPluginProvider's BODY_END hook inside a try/catch
    (a render hook that throws takes the whole panel down, so it must render there, not later).
    No `key:` on @livewire, as with theme-v2's balance host: a key makes Livewire look for a
    parent component that does not exist here.

    Styles are plain CSS on theme-v2's --phv2-* tokens (with fallbacks) rather than Tailwind
    classes, so the plugin needs no asset build.
--}}
<style>
    .neo-root { position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 40; font-size: .875rem; }
    .neo-launcher { display: flex; align-items: center; gap: .5rem; padding: .6rem 1rem; border-radius: 9999px; border: 1px solid var(--phv2-accent-edge, rgba(59,130,246,.5)); background: var(--phv2-accent, #2563eb); color: #fff; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,.45); cursor: pointer; }
    .neo-launcher:hover { background: var(--phv2-accent-hover, #1d4ed8); }
    .neo-dot { width: .5rem; height: .5rem; border-radius: 9999px; background: #fbbf24; }
    .neo-panel { position: absolute; right: 0; bottom: 3.5rem; width: min(26rem, calc(100vw - 2rem)); height: min(38rem, calc(100vh - 7rem)); display: flex; flex-direction: column; border-radius: .9rem; border: 1px solid var(--phv2-hairline, rgba(255,255,255,.08)); background: var(--phv2-modal, #12141a); color: var(--phv2-text, #f4f4f5); box-shadow: 0 20px 50px rgba(0,0,0,.55); overflow: hidden; }
    .neo-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .75rem .9rem; border-bottom: 1px solid var(--phv2-hairline, rgba(255,255,255,.08)); }
    .neo-title { font-weight: 700; letter-spacing: -.01em; }
    .neo-sub { font-size: .75rem; color: var(--phv2-text-muted, #a1a1aa); }
    .neo-head-actions { display: flex; gap: .25rem; }
    .neo-icon-btn { border-radius: .4rem; padding: .3rem .45rem; color: var(--phv2-text-muted, #a1a1aa); font-size: .75rem; }
    .neo-icon-btn:hover { background: var(--phv2-raise, rgba(255,255,255,.06)); color: var(--phv2-text, #fff); }
    .neo-usage { padding: .35rem .9rem; font-size: .7rem; color: var(--phv2-text-faint, #71717a); border-bottom: 1px solid var(--phv2-hairline, rgba(255,255,255,.06)); }
    .neo-body { flex: 1; overflow-y: auto; padding: .9rem; display: flex; flex-direction: column; gap: .6rem; }
    .neo-empty { color: var(--phv2-text-muted, #a1a1aa); font-size: .8125rem; line-height: 1.5; }
    .neo-msg-user { align-self: flex-end; max-width: 85%; padding: .5rem .75rem; border-radius: .75rem .75rem .2rem .75rem; background: var(--phv2-accent-soft, rgba(37,99,235,.25)); white-space: pre-wrap; word-break: break-word; }
    .neo-msg-ai { max-width: 100%; line-height: 1.55; word-break: break-word; }
    .neo-msg-ai p { margin: 0 0 .5rem; } .neo-msg-ai p:last-child { margin-bottom: 0; }
    .neo-msg-ai ul, .neo-msg-ai ol { margin: .25rem 0 .5rem 1.1rem; list-style: disc; } .neo-msg-ai ol { list-style: decimal; }
    .neo-msg-ai code { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .78rem; padding: .05rem .3rem; border-radius: .25rem; background: var(--phv2-sunken, rgba(0,0,0,.35)); }
    .neo-msg-ai pre { margin: .4rem 0; padding: .6rem; border-radius: .4rem; background: var(--phv2-sunken, rgba(0,0,0,.35)); overflow-x: auto; }
    .neo-msg-ai pre code { padding: 0; background: none; }
    .neo-msg-ai a { color: var(--phv2-accent-bright, #60a5fa); text-decoration: underline; }
    .neo-tool { border: 1px solid var(--phv2-hairline, rgba(255,255,255,.08)); border-radius: .6rem; padding: .45rem .6rem; background: var(--phv2-raise, rgba(255,255,255,.03)); font-size: .78rem; }
    .neo-tool-row { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .neo-tool-name { font-weight: 600; }
    .neo-badge { font-size: .68rem; padding: .05rem .4rem; border-radius: 9999px; background: var(--phv2-raise-2, rgba(255,255,255,.08)); color: var(--phv2-text-muted, #a1a1aa); white-space: nowrap; }
    .neo-badge-warn { background: rgba(251,191,36,.15); color: #fcd34d; }
    .neo-badge-bad { background: rgba(244,63,94,.15); color: #fda4af; }
    .neo-badge-ok { background: rgba(34,197,94,.15); color: #86efac; }
    .neo-tool-detail { margin-top: .3rem; color: var(--phv2-text-muted, #a1a1aa); }
    .neo-preview { margin-top: .4rem; max-height: 9rem; overflow: auto; padding: .45rem; border-radius: .35rem; background: var(--phv2-sunken, rgba(0,0,0,.35)); font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .72rem; white-space: pre-wrap; word-break: break-word; }
    .neo-approval { margin-top: .5rem; display: flex; gap: .4rem; }
    .neo-btn { border-radius: .45rem; padding: .35rem .7rem; font-weight: 600; font-size: .75rem; border: 1px solid var(--phv2-hairline, rgba(255,255,255,.1)); }
    .neo-btn-primary { background: var(--phv2-accent, #2563eb); color: #fff; border-color: var(--phv2-accent-edge, transparent); }
    .neo-btn-primary:hover { background: var(--phv2-accent-hover, #1d4ed8); }
    .neo-btn-ghost { color: var(--phv2-text, #fff); background: transparent; }
    .neo-btn-ghost:hover { background: var(--phv2-raise, rgba(255,255,255,.06)); }
    .neo-btn:disabled { opacity: .5; cursor: not-allowed; }
    .neo-notice { font-size: .78rem; padding: .5rem .65rem; border-radius: .5rem; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.25); color: #fde68a; }
    .neo-foot { border-top: 1px solid var(--phv2-hairline, rgba(255,255,255,.08)); padding: .6rem; display: flex; gap: .45rem; align-items: flex-end; }
    .neo-input { flex: 1; resize: none; min-height: 2.4rem; max-height: 8rem; border-radius: .55rem; padding: .5rem .65rem; background: var(--phv2-sunken, rgba(0,0,0,.3)); border: 1px solid var(--phv2-hairline, rgba(255,255,255,.1)); color: var(--phv2-text, #fff); font-size: .8125rem; }
    .neo-input:focus { outline: none; border-color: var(--phv2-accent, #3b82f6); }
    .neo-working { font-size: .75rem; color: var(--phv2-text-muted, #a1a1aa); padding: 0 .9rem .5rem; }
    .neo-consent { padding: 1rem; display: flex; flex-direction: column; gap: .75rem; line-height: 1.5; font-size: .8125rem; color: var(--phv2-text-muted, #d4d4d8); }
    .neo-consent h3 { font-weight: 700; color: var(--phv2-text, #fff); font-size: .95rem; }
    @media (max-width: 640px) { .neo-root { right: .75rem; bottom: .75rem; } .neo-panel { height: calc(100vh - 6rem); } }
</style>
@livewire('plugged-host-neo.chat')
