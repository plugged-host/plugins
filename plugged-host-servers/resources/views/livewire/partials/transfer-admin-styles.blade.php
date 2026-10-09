{{-- Styles shared by the admin Transfers tabs (server page: AdminTransfers, node page: NodeTransfers). --}}
<style>
    .pha { display: flex; flex-direction: column; font-size: .875rem; color: var(--phv2-text); }
    .pha-block { display: flex; flex-direction: column; gap: 1rem; padding: 1.5rem 0; }
    .pha-block:first-of-type { padding-top: 0; }
    .pha-block:last-child { padding-bottom: 0; }
    .pha-block + .pha-block { border-top: 1px solid var(--phv2-hairline); }
    .pha-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
    .pha-title { display: flex; align-items: center; gap: .5rem; font-size: 1rem; font-weight: 600; }
    .pha-title-warning { color: var(--warning-400); }
    .pha-pct { font-weight: 600; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
    .pha-route { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; font-size: .8125rem; color: var(--phv2-text-nav); }
    .pha-arrow { color: var(--phv2-text-faint); }
    .pha-bar { height: 6px; border-radius: 9999px; background: var(--phv2-raise); overflow: hidden; }
    .pha-bar > i { display: block; height: 100%; border-radius: 9999px; background: var(--phv2-accent-bright); transition: width .6s ease; }
    .pha-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1rem; }
    .pha-kv { min-width: 0; }
    .pha-kv small { display: block; font-size: .75rem; color: var(--phv2-text-muted); }
    .pha-kv strong { display: block; margin-top: .125rem; font-size: .875rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .pha-err { padding: .625rem .75rem; border-radius: .5rem; background: var(--phv2-sunken); color: var(--danger-400); font-size: .75rem; line-height: 1.125rem; word-break: break-word; }
    .pha-note { font-size: .8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
    .pha-btns { display: flex; gap: .5rem; flex-wrap: wrap; }
    .pha-log { display: flex; flex-direction: column; gap: .25rem; max-height: 14rem; overflow-y: auto; }
    .pha-log div { display: flex; gap: .75rem; font-size: .8125rem; color: var(--phv2-text-nav); }
    .pha-log time { flex: none; color: var(--phv2-text-faint); }
    .pha-log .pha-lv-warning { color: var(--warning-400); } .pha-log .pha-lv-danger { color: var(--danger-400); } .pha-log .pha-lv-success { color: var(--success-400); }
    .pha-table { width: 100%; border-collapse: collapse; }
    .pha-table th { text-align: left; font-size: .75rem; font-weight: 600; color: var(--phv2-text-muted); padding: .375rem .5rem; }
    .pha-table td { padding: .625rem .5rem; border-top: 1px solid var(--phv2-hairline); vertical-align: top; color: var(--phv2-text-nav); }
    .pha-table th:first-child, .pha-table td:first-child { padding-left: 0; }
    .pha-empty { color: var(--phv2-text-muted); }
    .pha-table .pha-empty { margin-top: .25rem; font-size: .75rem; }
    .pha-moves { display: flex; flex-direction: column; gap: 1rem; }
    .pha-move { display: flex; flex-direction: column; gap: .5rem; min-width: 0; }
    .pha-move a { font-weight: 600; color: var(--phv2-text); }
    .pha-move a:hover { color: var(--phv2-accent-bright); }
    .pha-table a { color: var(--phv2-text); }
    .pha-table a:hover { color: var(--phv2-accent-bright); }
    @media (max-width: 700px) { .pha-hide-sm { display: none; } }
</style>
