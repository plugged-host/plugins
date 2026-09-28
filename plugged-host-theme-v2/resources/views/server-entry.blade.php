{{--
    The server card on the server list (ThemeV2ServerEntry). Also its lazy placeholder, with
    $loading set and no numbers yet.

    Laid out by a container query on .phv2-server (theme.css), so it follows the card's own
    width rather than the window's: narrow cards get one line per resource, medium cards three
    compact columns with the address underneath, wide cards all four in a row.

    The whole card opens the console; the power menu and the address stop that click.
--}}
<div
    @unless ($loading) wire:poll.15s @endunless
    class="phv2-server"
    x-on:click="{{ $component->redirectUrl() }}"
    x-on:auxclick.prevent="if ($event.button === 1) {{ $component->redirectUrl(true) }}"
>
    <span class="phv2-server-status-bar fi-color fi-color-{{ $color }} fi-bg-color-600" style="background-color: var(--bg);"></span>

    <div class="phv2-server-head">
        @if ($icon)
            <img class="phv2-server-icon" src="{{ $icon }}" alt="" loading="lazy">
        @endif

        <div class="phv2-server-title">
            <h2 class="phv2-server-name">{{ $server->name }}</h2>
            <p class="phv2-server-state">
                @if ($loading)
                    <x-filament::loading-indicator class="phv2-server-spinner" />
                @endif
                {{ $status }}
            </p>
        </div>

        @if ($actions?->isVisible())
            <div class="phv2-server-actions" x-on:click.stop>
                {{ $actions }}
            </div>
        @endif
    </div>

    @if ($description)
        <p class="phv2-server-description">{{ $description }}</p>
    @endif

    <div class="phv2-server-stats">
        @foreach ($meters as $meter)
            <div class="phv2-server-stat">
                <div class="phv2-server-stat-info">
                    <span class="phv2-server-stat-name">{{ $meter['label'] }}</span>
                    <span class="phv2-server-stat-value">
                        @if ($loading)
                            <span class="phv2-server-stat-limit">—</span>
                        @else
                            <span @class(['phv2-server-stat-used', 'phv2-server-stat-' . $meter['level']])>{{ $meter['used'] }}</span>
                            <span class="phv2-server-stat-limit">/ {{ $meter['limit'] }}</span>
                        @endif
                    </span>
                </div>
                <div
                    class="phv2-server-track"
                    role="progressbar"
                    aria-label="{{ $meter['label'] }}: {{ $meter['used'] }} / {{ $meter['limit'] }}"
                    aria-valuenow="{{ $meter['percent'] }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <span class="phv2-server-bar phv2-server-bar-{{ $meter['level'] }}" style="width: {{ $loading ? 0 : $meter['percent'] }}%"></span>
                </div>
            </div>
        @endforeach

        <div class="phv2-server-address" x-on:click.stop>
            <span class="phv2-server-stat-name">{{ trans('server/dashboard.network') }}</span>
            <span class="phv2-server-address-value" title="{{ $address }}">{{ $address ?? trans('server/dashboard.none') }}</span>
        </div>
    </div>
</div>
