{{--
    Brand wordmark, injected at TOPBAR_LOGO_AFTER.

    APP_LOGO is set, so Filament's logo component takes its <img> branch and never renders
    brandName at all — this puts the name back beside the mark. It lives in a render hook rather
    than being folded into brandLogo() as an Htmlable, because that route lands inside Filament's
    `.fi-logo` wrapper, which is styled with a fixed height for an image and would have to be
    fought with CSS.

    The hook sits inside `.fi-topbar-start`, outside the x-persist container, so unlike the
    balance chips this is safe as plain static markup.

    No responsive classes of its own: `.fi-topbar-start` is `me-6 hidden items-center lg:flex`
    upstream, so this and the logo it sits next to are already absent below `lg` — a phone gets
    its branding from the sidebar header instead. An extra `hidden sm:inline-block` here only
    looked like it was doing something.
--}}
<span class="fi-ph-brand truncate text-base font-semibold tracking-tight text-gray-950 dark:text-white">
    {{ config('app.name') }}
</span>
