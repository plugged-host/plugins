{{--
    Shared by the Resource Store and the Token Store. Colour, type and surfaces read Theme v2's
    --phv2-* tokens, so nothing here depends on Filament's light/dark switch. Layout utilities
    come from plugins/plugged-host-core/resources/css/plugged-host.css (the only Tailwind entry
    that scans plugins/**): a class no plugin view used before needs `yarn build`.

    Phone first: every list is a column of full-width rows whose action drops under its text
    below 640px.

    The meter (Resource Store) is one bar for three numbers: the solid part is what your servers
    use, the tinted part is the rest of your limit, and the empty track runs up to the most you
    can buy. Over the limit, the solid part turns red.
--}}
<style>
    .ph-eco-card { display: flex; flex-direction: column; gap: 1.25rem; padding: 1rem; border-radius: 1rem; background-color: var(--phv2-raise); }
    .ph-eco-list { display: flex; flex-direction: column; padding: 0.25rem 1rem; border-radius: 1rem; background-color: var(--phv2-raise); }
    .ph-eco-row { display: flex; flex-direction: column; gap: 1rem; padding-block: 1rem; }
    .ph-eco-row + .ph-eco-row { border-top: 1px solid var(--phv2-hairline); }
    .ph-eco-row-action { display: flex; flex-direction: column; gap: 0.5rem; width: 100%; }
    @media (min-width: 640px) {
        .ph-eco-card { padding: 1.5rem; }
        .ph-eco-list { padding-inline: 1.5rem; }
        .ph-eco-row { flex-direction: row; align-items: center; justify-content: space-between; gap: 1.5rem; }
        .ph-eco-row-action { width: 15rem; flex-shrink: 0; }
    }

    .ph-eco-heading { font-size: 1rem; font-weight: 600; line-height: 1.5rem; color: var(--phv2-text); }
    .ph-eco-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--phv2-text); }
    .ph-eco-text { font-size: 0.875rem; line-height: 1.375rem; color: var(--phv2-text-nav); }
    .ph-eco-muted { font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
    .ph-eco-small { font-size: 0.75rem; line-height: 1.125rem; color: var(--phv2-text-muted); }
    .ph-eco-value { font-size: 1.5rem; font-weight: 600; line-height: 2rem; font-variant-numeric: tabular-nums; color: var(--phv2-accent-bright); }
    .ph-eco-num { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--phv2-text); }
    .ph-eco-danger { color: var(--danger-400); }
    .ph-eco-link { font-weight: 500; color: var(--phv2-accent-bright); }
    .ph-eco-link:hover { color: var(--phv2-accent-glow); text-decoration: underline; }

    .ph-eco-meter { position: relative; height: 0.5rem; border-radius: 9999px; overflow: hidden; background-color: var(--phv2-raise); }
    .ph-eco-meter > span { position: absolute; inset-block: 0; left: 0; border-radius: 9999px; }
    .ph-eco-meter-limit { background-color: var(--phv2-accent-tint); }
    .ph-eco-meter-used { background-color: var(--phv2-accent-bright); }
    .ph-eco-meter-over { background-color: var(--danger-400); }

    .ph-eco-amount { flex-shrink: 0; font-size: 0.875rem; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .ph-eco-amount-in { color: var(--success-400); }
    .ph-eco-amount-out { color: var(--phv2-text-muted); }

    .ph-eco-tx { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-block: 0.75rem; }
    .ph-eco-tx + .ph-eco-tx { border-top: 1px solid var(--phv2-hairline); }
    .ph-eco-empty { padding-block: 1rem; }
</style>
