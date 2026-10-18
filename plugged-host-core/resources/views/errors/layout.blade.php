{{--
    Replaces Pelican's errors::layout, which upstream's 400/401/403/404/418/500 views extend (as do
    4xx/5xx here). Core's views dir is first in view.paths (MaintenancePagePluginProvider), so
    `errors::layout` resolves to this file and upstream's stay untouched. From those views it reads
    $code, $title and $subtitle; their $icon is decoration and is dropped.
--}}
@extends('plugged-host-core::partials.error-page')

@php
    use Illuminate\Support\Facades\Lang;
    use Symfony\Component\HttpFoundation\Response;

    $status = (int) ($code ?? (isset($exception) ? $exception->getStatusCode() : 500));
    $strings = 'plugged-host-core::strings.errors';

    $heading = Lang::has("$strings.titles.$status")
        ? trans("$strings.titles.$status")
        : ($title ?? Response::$statusTexts[$status] ?? trans("$strings.titles.500"));

    // Upstream's subtitle is the exception message. Keep it where it is written for the reader (a
    // 400/403 reason, e.g. core's sign-in links, and the 500 detail upstream shows root admins);
    // everywhere else it is framework text ("The route x could not be found."), so use our line.
    $detail = rescue(fn () => value($subtitle ?? null), null, false);
    $message = match (true) {
        in_array($status, [400, 403], true) && filled($detail) => $detail,
        $status === 500 && filled($detail) && rescue(fn () => (bool) user()?->isRootAdmin(), false, false) => $detail,
        Lang::has("$strings.messages.$status") => trans("$strings.messages.$status"),
        $status >= 500 => trans("$strings.messages.500"),
        default => trans("$strings.messages.default"),
    };

    // The public status page (STATUS_PAGE_URL, else STATUS_RSS_BASE_URL); no button without one.
    $statusUrl = (string) config('plugged-host-core.status_url', '');
@endphp

@section('ph-error-actions')
    <a href="{{ url('/') }}" class="btn-submit">{{ trans("$strings.home") }}</a>

    @if ($status >= 500 && filled($statusUrl))
        <a href="{{ $statusUrl }}" target="_blank" rel="noopener noreferrer" class="btn-cancel">{{ trans("$strings.status") }}</a>
    @endif

    <p class="auth-note">{{ trans("$strings.code", ['code' => $status]) }}</p>
@endsection
