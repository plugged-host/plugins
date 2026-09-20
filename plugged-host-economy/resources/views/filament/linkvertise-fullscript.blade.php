{{--
    The Linkvertise Full Script API, on the earn page and nowhere else.

    `linkvertise.js` is not a unit that draws something. It registers one
    DOMContentLoaded handler and rewrites the `href` of every anchor it is allowed to
    match into a `link-to.net/<user_id>/…/dynamic/?r=…` trip, with `target=_blank`.
    Two things follow, and both are why this file is separate from the Adsterra
    scripts rather than another entry in that list:

    It must be loaded on this page only. Panel-wide, with the sidebar, the console
    and the file manager all being anchors, it would monetise the whole panel.
    AdsPluginServiceProvider gates it on EarnTokensPage::isCurrentRoute().

    The whitelist is the second guard and is never empty — an empty whitelist means
    "every link on the page" to the script. It is built server-side by
    LinkvertiseProvider::whitelist(), which also drops regex metacharacters, because
    the script compiles these patterns into a regex rather than matching them as
    globs.

    Inline is fine here: the panel vhost sets only `frame-ancestors 'self'`, no
    script-src. The static sites' strict CSP does not apply to panel.plugged.host.

    ────────────────────────────────────────────────────────────────────────────────
    Why `linkvertise(id, …)` is not called directly any more.

    The vendor's entry point is *only* `document.addEventListener('DOMContentLoaded',
    …)`, with no `document.readyState` check anywhere in the file. So calling it after
    that event has fired registers a listener that will never run, and rewrites
    nothing at all — silently, with no console error.

    That is precisely how this page is usually reached. Upstream turns SPA mode on for
    every panel (PanelProvider: `->spa(fn () => ! request()->routeIs(
    'filament.server.pages.console'))`) and nothing declares a `spaUrlExceptions` for
    it, so Filament renders the sidebar entry as `wire:navigate`. A navigate entry
    swaps the body and re-runs the scripts in it, but DOMContentLoaded fired on the
    first page of the SPA session — long ago. The anchor below is then delivered
    un-rewritten, the user clicks a link that goes straight back to our own return URL
    with no publisher hash on it, `settleReturn()` reports `no_token`, and the trip
    earns nothing. nginx's log was unambiguous about the scale of it: of the returns to
    this page in one day, 19 carried `hash=` with a linkvertise.com referer and 29
    carried no hash with a panel.plugged.host referer.

    Two further details make the naive call worse on that path. Livewire's
    `prepNewBodyScriptTagsToRun()` re-runs body scripts by cloning the tags, and a
    dynamically inserted `<script src>` defaults to `async`, so on a navigate entry
    the CDN tag resolves *after* this inline one — `linkvertise` can be undefined at
    the moment it would have been called. Hence the `load` fallback.

    So the handler is armed here instead of being left to the event: capture the
    listener the vendor registers, then either hand it back to the real event (a
    genuine `loading` document, i.e. a full page load — behaviour identical to before)
    or invoke it directly (anything later). `document.addEventListener` is wrapped for
    exactly one synchronous call and restored in a `finally`.

    Deliberately not `document.dispatchEvent(new Event('DOMContentLoaded'))`: Alpine,
    Livewire and Filament all listen for that event, and re-dispatching it document-
    wide to reach one third-party listener would re-initialise them.

    Re-arming is idempotent: the whitelist is the `lvr=` marker our own return URL
    carries, and an anchor already rewritten to `link-to.net/…` no longer matches it.

    If the vendor ever stops registering a DOMContentLoaded listener, `captured` stays
    null and this degrades to today's behaviour — the anchor is not rewritten on an SPA
    entry — rather than throwing.
--}}
<script src="{{ $scriptUrl }}" id="ph-linkvertise-cdn"></script>
<script>
(function () {
    var userId = {{ $userId }};
    var config = {whitelist: {!! $whitelist !!}, blacklist: []};

    function arm() {
        if (typeof linkvertise !== 'function') {
            return false;
        }

        var captured = null;
        var original = document.addEventListener;

        try {
            document.addEventListener = function (type, listener) {
                if (type === 'DOMContentLoaded' && typeof listener === 'function' && captured === null) {
                    captured = listener;

                    return;
                }

                return original.apply(document, arguments);
            };

            linkvertise(userId, config);
        } finally {
            document.addEventListener = original;
        }

        if (captured === null) {
            return false;
        }

        if (document.readyState === 'loading') {
            original.call(document, 'DOMContentLoaded', captured, false);
        } else {
            captured.call(document);
        }

        return true;
    }

    if (!arm()) {
        var tag = document.getElementById('ph-linkvertise-cdn');

        if (tag) {
            tag.addEventListener('load', arm, {once: true});
        }
    }
}());
</script>
