{{--
    Maintenance (`artisan down`) and any other 503, on the shared error page. Found as errors::503
    because core's views dir is first in view.paths (MaintenancePagePluginProvider).

    The exception's message is not shown: maintenance mode always throws "Service Unavailable".
    `artisan down --retry=N` sends Retry-After, and the page re-checks on that clock (30s without).
--}}
@extends('plugged-host-core::partials.error-page')

@php
    $strings = 'plugged-host-core::strings.maintenance';

    $documentTitle = trans("$strings.title");
    $heading = trans("$strings.heading");
    $message = trans("$strings.subheading");

    $retryAfter = isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? null) : null;
    $retrySeconds = is_numeric($retryAfter) && (int) $retryAfter > 0 ? (int) $retryAfter : 30;

    $discordUrl = config('plugged-host-discord.invite_url', 'https://discord.gg/plugged-host-1502647500295635085');

    $adsEnabled = config('plugged-host-ads.enabled', true);
    $adUnitId = config('plugged-host-ads.unit_id', '2453391');
    $adIframeWidth = config('plugged-host-ads.iframe_width', '70%');
@endphp

@section('ph-error-actions')
    <button type="button" id="refresh-btn" class="btn-submit" onclick="triggerRefresh()">
        {{ trans("$strings.refresh_now") }}
    </button>

    @if (filled($discordUrl))
        <a href="{{ $discordUrl }}" target="_blank" rel="noopener noreferrer" class="btn-cancel">{{ trans("$strings.discord") }}</a>
    @endif

    <a href="https://status.plugged.host" target="_blank" rel="noopener noreferrer" class="btn-cancel">{{ trans("$strings.status_page") }}</a>

    <p class="auth-note">{{ trans("$strings.refresh_auto", ['seconds' => '']) }}<span id="countdown">{{ $retrySeconds }}</span>s</p>
@endsection

@section('ph-error-after')
    @if ($adsEnabled)
        <div class="ph-support-slot">
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
@endsection

@section('ph-error-scripts')
    <script>
        let secondsLeft = {{ $retrySeconds }};
        const countdownEl = document.getElementById('countdown');
        const refreshBtn = document.getElementById('refresh-btn');

        function triggerRefresh() {
            if (refreshBtn) {
                refreshBtn.setAttribute('disabled', 'disabled');
                refreshBtn.textContent = @js(trans("$strings.checking"));
            }
            window.location.reload();
        }

        const timer = setInterval(function () {
            secondsLeft--;
            if (countdownEl) {
                countdownEl.textContent = secondsLeft;
            }
            if (secondsLeft <= 0) {
                clearInterval(timer);
                triggerRefresh();
            }
        }, 1000);
    </script>
@endsection
