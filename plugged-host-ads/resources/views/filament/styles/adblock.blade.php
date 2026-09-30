{{-- Styling for the ad-block notice and overlay. Injected unconditionally while
     the feature is on, never per-page: Livewire's wire:navigate merges <head> on
     the first full page load only, so CSS gated on "this page shows an ad" would
     be missing for the rest of the SPA session whenever the entry page happened
     to be an exempt one. It only ever targets this plugin's own elements, so
     shipping it to a page that has no notice costs nothing.

     Almost nothing visual is declared here. The notice and the dialog are real
     `fi-section` surfaces, their text uses Filament's section-header classes and
     their buttons carry the same classes a Filament Action renders, so both
     follow the panel's palette and its light/dark switch without this file
     naming a single colour. What is left is layout, plus the modal backdrop —
     copied from Filament's own `fi-modal-close-overlay` values, because a real
     fi-modal needs Alpine to drive it — and the dialog's modal surface, taken
     from Theme v2's --phv2-modal token. --}}
@verbatim
<style data-plugged-host="adblock-notice">
    /* Whatever fi-section does with display, the hidden attribute has to win. */
    [data-ph-notice][hidden] {
        display: none;
    }

    .ph-support-notice {
        margin: 0 auto 1rem auto;
        max-width: 42rem;
        text-align: start;
    }

    .ph-support-overlay {
        position: fixed;
        inset: 0;
        z-index: 40;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background-color: var(--gray-950, #030712);
    }

    .dark .ph-support-overlay {
        background-color: var(--gray-950, #030712);
    }

    @supports (color: color-mix(in oklab, red, red)) {
        .ph-support-overlay {
            background-color: color-mix(in oklab, var(--gray-950) 50%, transparent);
        }

        .dark .ph-support-overlay {
            background-color: color-mix(in oklab, var(--gray-950) 75%, transparent);
        }
    }

    .ph-support-dialog {
        width: 100%;
        max-width: 30rem;
        text-align: start;
        animation: ph-support-in .2s ease-out;
    }

    /* The dialog stands in for a Filament modal, so it takes Theme v2's modal
       surface and elevation rather than the translucent card surface it would
       otherwise inherit as an fi-section. The selector outranks the theme's
       `.fi-section:not(…):not(…)` rule on specificity, so load order is moot. */
    .ph-support-overlay > .ph-support-dialog.fi-section.fi-section-has-header {
        background-color: var(--phv2-modal, var(--gray-900));
        box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.5), 0 0 0 1px var(--phv2-hairline, transparent);
    }

    .ph-support-locked {
        overflow: hidden;
    }

    @keyframes ph-support-in {
        from {
            opacity: 0;
            transform: translateY(.5rem);
        }

        to {
            opacity: 1;
            transform: none;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .ph-support-dialog {
            animation: none;
        }
    }
</style>
@endverbatim
