@php
    $panel = null;
    try {
        $panel = \Filament\Facades\Filament::getCurrentPanel() ?? \Filament\Facades\Filament::getPanel('app');
        if ($panel) {
            \Filament\Facades\Filament::setCurrentPanel($panel);
            \Filament\Support\Facades\FilamentColor::register($panel->getColors());
        }
    } catch (\Throwable) {}

    $appName = config('app.name', 'plugged.host');
    $appLogo = config('app.logo');
    $appFavicon = config('app.favicon', '/pelican.ico');
    $discordUrl = config('plugged-host-discord.invite_url', 'https://discord.gg/pluggedhost');
    $statusUrl = 'https://status.plugged.host';

    $customMessage = null;
    if (isset($exception) && is_object($exception) && method_exists($exception, 'getMessage') && filled($exception->getMessage())) {
        $customMessage = $exception->getMessage();
    }

    $retrySeconds = isset($retryAfter) && is_numeric($retryAfter) ? (int) $retryAfter : 30;
    $title = trans('plugged-host-core::strings.maintenance.title') ?? 'Maintenance';

    $adsEnabled = config('plugged-host-ads.enabled', true);
    $adUnitId = config('plugged-host-ads.unit_id', '2453391');
    $adIframeWidth = config('plugged-host-ads.iframe_width', '70%');

    $themeHtml = null;
    $fontHtml = null;
    $fontFamily = 'Inter Variable';
    $themeMode = 'system';

    try {
        if ($panel) {
            $themeHtml = $panel->getTheme()?->getHtml();
            $fontHtml = $panel->getFontHtml();
            $fontFamily = $panel->getFontFamily() ?? 'Inter Variable';
            $themeMode = $panel->getDefaultThemeMode()?->value ?? 'system';
        }
    } catch (\Throwable) {}

    $appCssUrl = null;
    $pluginCssUrl = null;
    try {
        $appCssUrl = \Illuminate\Support\Facades\Vite::asset('resources/css/app.css');
    } catch (\Throwable) {}

    try {
        $pluginCssUrl = \Illuminate\Support\Facades\Vite::asset('plugins/plugged-host-core/resources/css/plugged-host.css');
    } catch (\Throwable) {}
@endphp
<!DOCTYPE html>
{{-- `dark` unconditionally: theme v2 is dark-only (the panel forces dark mode), and this page
     does not go through the panel layout that would add it. --}}
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="fi dark min-h-screen">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />

    <link rel="icon" href="{{ $appFavicon }}" />

    <title>
        {{ $title }} - {{ $appName }}
    </title>

    @filamentStyles

    @if ($appCssUrl)
        <link rel="stylesheet" href="{{ $appCssUrl }}" />
    @endif

    @if ($pluginCssUrl)
        <link rel="stylesheet" href="{{ $pluginCssUrl }}" />
    @endif

    @if ($themeHtml)
        {!! $themeHtml !!}
    @endif

    @if ($fontHtml)
        {!! $fontHtml !!}
    @endif

    <style>
        :root {
            --font-family: '{!! $fontFamily !!}';
        }

        /*
            Theme v2 tokens, with their literal values as fallbacks: this page is rendered by the
            exception handler, and when no panel can be resolved the theme stylesheet is absent.
            Floor, card and text match the panel's simple layout; nothing decorative on top.
        */
        .fi-body {
            background-color: var(--phv2-bg-bottom, #0b0d10);
            background-image: linear-gradient(to bottom, var(--phv2-bg-top, #101319), var(--phv2-bg-bottom, #0b0d10));
            background-attachment: fixed;
            color: var(--phv2-text, #ffffff);
        }

        .ph-503-heading {
            font-size: 1.25rem;
            line-height: 1.75rem;
            font-weight: 600;
            color: var(--phv2-text, #ffffff);
        }

        .ph-503-text {
            font-size: 0.875rem;
            line-height: 1.375rem;
            color: var(--phv2-text-muted, #9ca3af);
        }

        .ph-503-text.text-xs {
            font-size: 0.75rem;
            line-height: 1rem;
        }

        .ph-503-count {
            font-weight: 600;
            color: var(--phv2-text, #ffffff);
        }

        .ph-503-divider {
            border-top: 1px solid var(--phv2-hairline, rgb(255 255 255 / 0.05));
        }

        .ph-503-link {
            color: var(--phv2-text-muted, #9ca3af);
            transition: color 150ms ease;
        }

        .ph-503-link:hover {
            color: var(--phv2-text, #ffffff);
        }
    </style>

    <script>
        const theme = localStorage.getItem('theme') ?? @js($themeMode);

        if (
            theme === 'dark' ||
            (theme === 'system' &&
                window.matchMedia('(prefers-color-scheme: dark)')
                    .matches)
        ) {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>

<body class="fi-body min-h-screen antialiased">
<div class="fi-simple-layout flex min-h-screen flex-col items-center justify-center p-4">
    <div class="fi-simple-main-ctn flex w-full flex-grow flex-col items-center justify-center">
        <main class="fi-simple-main my-8 w-full px-6 py-10 sm:rounded-2xl sm:px-10 sm:max-w-lg">
            <div class="fi-simple-page">
                <section class="grid auto-cols-fr gap-y-6">
                    {{-- Logo --}}
                    @if (filled($appLogo))
                        <div class="flex justify-center">
                            <img src="{{ $appLogo }}" alt="{{ $appName }}" class="h-9 w-auto object-contain" />
                        </div>
                    @endif

                    {{-- Heading and message. The heading is the status; no badge or spinner
                         repeating it. --}}
                    <header class="fi-simple-header flex flex-col items-center gap-2 text-center">
                        <h1 class="fi-simple-header-heading ph-503-heading">
                            {{ trans('plugged-host-core::strings.maintenance.heading') }}
                        </h1>

                        <p class="fi-simple-header-subheading ph-503-text">
                            {{ $customMessage ?? trans('plugged-host-core::strings.maintenance.subheading') }}
                        </p>
                    </header>

                    {{-- Auto-refresh counter --}}
                    <p class="ph-503-text text-center text-xs">
                        {{ trans('plugged-host-core::strings.maintenance.refresh_auto', ['seconds' => '']) }}
                        <span class="ph-503-count font-mono tabular-nums"><span id="countdown">{{ $retrySeconds }}</span>s</span>
                    </p>

                    {{-- Actions: one primary, one secondary. --}}
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-center">
                        <x-filament::button
                            id="refresh-btn"
                            color="primary"
                            onclick="triggerRefresh()"
                            class="w-full sm:w-auto"
                        >
                            <span id="refresh-label">{{ trans('plugged-host-core::strings.maintenance.refresh_now') }}</span>
                        </x-filament::button>

                        @if (filled($discordUrl))
                            <x-filament::button
                                tag="a"
                                :href="$discordUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                color="gray"
                                class="w-full sm:w-auto"
                            >
                                {{ trans('plugged-host-core::strings.maintenance.discord') }}
                            </x-filament::button>
                        @endif
                    </div>

                    {{-- Public status link --}}
                    <div class="ph-503-divider pt-4 text-center">
                        <a href="{{ $statusUrl }}" target="_blank" rel="noopener noreferrer" class="ph-503-link text-xs font-medium">
                            {{ trans('plugged-host-core::strings.maintenance.status_page') }}
                        </a>
                    </div>
                </section>
            </div>
        </main>

        {{-- Ads Slot --}}
        @if ($adsEnabled)
            <div class="ph-support-slot w-full max-w-lg text-center">
                <div id="frame" class="plugged-host-aads-container">
                    <iframe data-aa="{{ $adUnitId }}"
                            data-ph-unit="aads"
                            src="//acceptable.a-ads.com/{{ $adUnitId }}/?size=Adaptive"
                            style="border:0; padding:0; width:{{ $adIframeWidth }}; max-width: 100%; min-height: 90px; height:auto; overflow:hidden; display: block; margin: auto;"
                            title="Advertisement"
                            loading="lazy"></iframe>
                </div>
            </div>
        @endif
    </div>
</div>

<script>
    let secondsLeft = {{ $retrySeconds }};
    const countdownEl = document.getElementById('countdown');
    const refreshBtn = document.getElementById('refresh-btn');
    const refreshLabel = document.getElementById('refresh-label');

    function triggerRefresh() {
        if (refreshBtn) {
            refreshBtn.setAttribute('disabled', 'disabled');
            if (refreshLabel) {
                refreshLabel.textContent = 'Checking...';
            }
        }
        window.location.reload();
    }

    if (secondsLeft > 0) {
        const timer = setInterval(function() {
            secondsLeft--;
            if (countdownEl) {
                countdownEl.textContent = secondsLeft;
            }
            if (secondsLeft <= 0) {
                clearInterval(timer);
                triggerRefresh();
            }
        }, 1000);
    }
</script>
</body>
</html>
