{{--
    The sidebar's notification button, injected at SIDEBAR_NAV_START directly under the logo.

    This is all that is left of UIExample's search row (views/components/top.ejs, "New search
    and buttons section": a grow-to-fit filter field, then a bell, then a cog). The field and
    the cog were removed by request, so the bell is no longer one 37.5px square in a row of
    three and is instead the full-width "big notification button" the sidebar was asked for.

    The cog used to deep-link to the profile page's Customization tab, and the nav had a
    Customization row for the same destination; both are gone. That tab is where the Theme v2
    opt-in toggle lives, so it is deliberately still reachable — one click further in, via the
    Profile Settings row.

    The button forwards to the real notifications trigger, which this theme hides in the
    sidebar footer: clicking a `display: none` element still dispatches, so the slide-over
    opens through exactly the same path as clicking the real bell. Both class names are
    matched because Filament renders a different trigger per position (`fi-sidebar-…` with no
    topbar, `fi-topbar-…` with one), and this row is also wanted on a panel that still has one.

    Alpine rather than an inline <script>: `wire:navigate` swaps <body> without re-executing
    inline scripts, so a script here would stop working after the first client-side navigation.
--}}
@php
    use Filament\Support\Icons\Heroicon;

    use function Filament\Support\generate_icon_html;
@endphp

<button
    type="button"
    class="phv2-notifications"
    title="{{ $notificationsLabel }}"
    aria-label="{{ $notificationsLabel }}"
    x-on:click="$dispatch('open-modal', { id: 'database-notifications' }); document.querySelector('.fi-sidebar-database-notifications-btn, .fi-topbar-database-notifications-btn')?.click();"
>
    {{ generate_icon_html(Heroicon::OutlinedBell) }}

    <span class="phv2-notifications-label">{{ trans('plugged-host-theme-v2::strings.nav.notifications') }}</span>

    {{-- Server-rendered, so it is as fresh as the page: the real bell polls every 30s, this mirror does not. --}}
    @if ($unreadNotificationsCount)
        <span class="phv2-notifications-badge">{{ $unreadNotificationsCount > 99 ? '99+' : $unreadNotificationsCount }}</span>
    @endif
</button>
