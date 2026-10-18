{{--
    The page every error view renders into: errors/layout (upstream's per-code views and the
    4xx/5xx fallbacks), errors/minimal (Laravel's 402/419/429) and errors/503.

    Standalone like the migrate and sso-connect pages, on the same styles: an error page cannot
    count on the panel theme, which needs the Vite manifest, a resolved panel and the plugin CSS
    that broke the old layout (global-search-modal's white-background utility outranked Filament's
    dark card, so the card went white under white text). Don't spell that class out here: core's
    plugged-host.css scans this file, and the name alone makes Tailwind ship the utility again.

    Expects $heading and $message (and optionally $documentTitle for the tab); sections
    `ph-error-actions` (in the card, under the message), `ph-error-after` (below the card) and
    `ph-error-scripts`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- Favicon, name and logo resolve as Pelican's panels do (PanelProvider). --}}
    <link rel="icon" href="{{ config('app.favicon', '/pelican.ico') }}" />
    <title>{{ $documentTitle ?? $heading }} &bull; {{ config('app.name', 'Pelican') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap">
    @include('plugged-host-core::partials.standalone-auth-styles')
    <style>
        /* The card and whatever sits below it (the 503's ad slot) stack in the centred column. */
        .ph-error {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
            width: 100%;
            max-width: 27.5rem;
        }
        .ph-error > * {
            width: 100%;
        }
        .ph-error-release {
            text-align: center;
            font-size: 0.75rem;
            color: var(--phv2-text-faint, #71717a);
        }
    </style>
</head>
<body>
    <main class="ph-error">
        <div class="auth-card">
            <div class="auth-header">
                @if (filled(config('app.logo')))
                    <img src="{{ config('app.logo') }}" alt="{{ config('app.name', 'Pelican') }}" class="auth-logo">
                @endif
                <h1 class="auth-title">{{ $heading }}</h1>
                @if (filled($message))
                    <p class="auth-subtitle">{{ $message }}</p>
                @endif
            </div>

            <div class="auth-actions">
                @yield('ph-error-actions')
            </div>
        </div>

        @yield('ph-error-after')

        {{-- Which release answered, for anyone reporting the error. Guarded: an error page must not fail. --}}
        @php($release = rescue(fn () => \PluggedHost\Core\Support\Release::label(), '', false))
        @if ($release !== '')
            <p class="ph-error-release">{{ $release }}</p>
        @endif
    </main>

    @yield('ph-error-scripts')
</body>
</html>
