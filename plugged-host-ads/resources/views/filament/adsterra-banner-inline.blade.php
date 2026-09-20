{{-- The banner in the top document, for when the sandbox is what stops it filling.

     Adsterra's banner snippet reaches the page through document.write. That is
     fine while a page is parsing, but this script is re-run by Livewire after a
     wire:navigate body swap, and document.write on a loaded document implies
     document.open() — which blanks the panel. So the write is intercepted for as
     long as the ad script needs it and its output is appended to this unit's own
     container instead. atOptions carries format:'iframe', so what the ad writes
     is an iframe, which insertAdjacentHTML inserts happily.

     Units run one at a time. Both levers this depends on — window.atOptions and
     document.write — are global, so two banners initialising together would race:
     the second would overwrite the first's options and could capture its output.
     The waiting room renders several banners, so each one queues behind the last
     and the interception window never covers more than a single unit.

     Everything interpolated here is machine-built: $unit['options'] is
     json_encode with JSON_HEX_TAG|HEX_AMP|HEX_APOS|HEX_QUOT (so no raw <, >, &,
     quote or apostrophe survives inside it) and $unit['src'] passed
     AdsterraService::cleanScriptUrl(). --}}
<div data-ph-unit="adsterra-{{ $unit['format'] }}"
     data-ph-id="{{ $unit['id'] ?? $unit['format'] }}"
     data-ph-banner
     style="margin:0.75rem auto 0 auto; max-width:100%; min-height:{{ $unit['height'] }}px;">
    <script data-plugged-host="adsterra-banner">
    (() => {
        'use strict';

        const container = document.currentScript.parentElement;

        // Livewire re-runs this on navigation, and the ad below is appended to the
        // container rather than written into it, so a second pass would stack a
        // second banner.
        if (!container || container.dataset.phFilled === '1') {
            return;
        }

        container.dataset.phFilled = '1';

        const options = {!! $unit['options'] !!};
        const src = @js($unit['src']);

        const fill = () => new Promise((done) => {
            window.atOptions = options;

            const realWrite = document.write;
            const realWriteln = document.writeln;
            let restored = false;

            const restore = () => {
                if (restored) {
                    return;
                }

                restored = true;
                document.write = realWrite;
                document.writeln = realWriteln;
                done();
            };

            // Nothing else on a panel page calls document.write, and the window is
            // bounded by the script's own load event plus a hard timeout, so the
            // interception cannot outlive the ad it exists for.
            document.write = document.writeln = (...parts) => {
                container.insertAdjacentHTML('beforeend', parts.join(''));
            };

            const script = document.createElement('script');
            script.src = src;
            script.addEventListener('load', restore);
            script.addEventListener('error', restore);
            container.appendChild(script);

            window.setTimeout(restore, 8000);
        });

        // The tail of the chain is kept on window, so units rendered in separate
        // <script> blocks — or by separate render hooks — still serialise. A
        // rejection anywhere must not strand the queue, hence the catch.
        window.phAdsterraBannerQueue = (window.phAdsterraBannerQueue || Promise.resolve())
            .catch(() => {})
            .then(fill);
    })();
    </script>
</div>
