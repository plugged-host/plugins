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
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="fi min-h-screen">

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
        <main class="fi-simple-main my-8 w-full px-6 py-10 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 sm:rounded-2xl sm:px-10 sm:max-w-lg">
            <div class="fi-simple-page">
                <section class="grid auto-cols-fr gap-y-6">
                    {{-- Logo --}}
                    @if (filled($appLogo))
                        <div class="flex justify-center">
                            <img src="{{ $appLogo }}" alt="{{ $appName }}" class="h-9 w-auto object-contain" />
                        </div>
                    @endif

                    {{-- Status Icon & Badge --}}
                    <div class="flex flex-col items-center gap-3">
                        <div class="flex size-14 items-center justify-center rounded-full bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <x-filament::loading-indicator class="size-6 text-primary-600 dark:text-primary-400" />
                        </div>

                        <x-filament::badge color="primary" size="sm" class="rounded-full">
                            {{ trans('plugged-host-core::strings.maintenance.status_active') }}
                        </x-filament::badge>
                    </div>

                    {{-- Header & Subtitle --}}
                    <header class="fi-simple-header flex flex-col items-center text-center">
                        <h1 class="fi-simple-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                            {{ trans('plugged-host-core::strings.maintenance.heading') }}
                        </h1>

                        <p class="fi-simple-header-subheading mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ $customMessage ?? trans('plugged-host-core::strings.maintenance.subheading') }}
                        </p>
                    </header>

                    {{-- Auto-refresh counter --}}
                    <div class="flex items-center justify-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <x-filament::icon icon="tabler-clock" class="size-4 text-gray-400 dark:text-gray-500" />
                        <span>{{ trans('plugged-host-core::strings.maintenance.refresh_auto', ['seconds' => '']) }}</span>
                        <span class="font-semibold tabular-nums text-gray-950 dark:text-white"><span id="countdown">{{ $retrySeconds }}</span>s</span>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-center">
                        <x-filament::button
                            id="refresh-btn"
                            color="primary"
                            icon="tabler-refresh"
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
                                icon="tabler-brand-discord"
                                class="w-full sm:w-auto"
                            >
                                {{ trans('plugged-host-core::strings.maintenance.discord') }}
                            </x-filament::button>
                        @endif
                    </div>

                    {{-- Public Status Link --}}
                    <div class="border-t border-gray-100 pt-4 text-center dark:border-white/10">
                        <a href="{{ $statusUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 transition hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
                            <x-filament::icon icon="tabler-activity-heartbeat" class="size-4" />
                            <span>{{ trans('plugged-host-core::strings.maintenance.status_page') }}</span>
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
