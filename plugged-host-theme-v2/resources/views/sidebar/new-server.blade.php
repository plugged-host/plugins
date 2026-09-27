{{--
    The sidebar's one call to action, injected at SIDEBAR_NAV_START directly below the
    notification button — in place of the Compute group, which held this same link plus an
    SFTP Credentials deep link and was replaced by request.

    UIExample's /servers/new anchor, stretched to the sidebar's full 16rem, but styled as the
    theme's gray (secondary) button and without the plus: it is on every page, so the page's
    own primary stays the only filled button, and the label already says what it does.

    An anchor rather than a Filament action: a render hook is outside any Action, so
    there is nothing to configure and none of .fi-btn's fi-color-primary custom
    properties would be set. The active state is deliberately absent too — the page it
    leads to is the only place this button is not useful, and UIExample's CTA has none.
--}}
@php
    use function Filament\Support\generate_href_html;
@endphp

<a
    {{ generate_href_html($url) }}
    class="phv2-new-server"
>
    {{ trans('plugged-host-theme-v2::strings.nav.new_server') }}
</a>
