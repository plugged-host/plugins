{{--
    The Enhanced page (EnhancedPage): the edition the server runs, with the one switch (the
    page's only filled button), then what changes for players, resources and txAdmin as plain
    rows of text. A switch that has not reached the server yet says so under the edition.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.enhanced.' . $key, $replace);
    $installedEdition = $this->installed['edition'] ?? null;
    $pending = $installedEdition !== null && $installedEdition !== $this->edition;
    $shown = $installedEdition ?? $this->edition;
@endphp
<x-filament-panels::page>
    <style>
        .phf-stack { display: flex; flex-direction: column; gap: 1.5rem; }
        .phf-now { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 1rem 1.5rem; padding: 1.25rem 1.5rem; border-radius: 1rem; background: var(--phv2-raise); }
        .phf-big { font-size: 1.125rem; font-weight: 600; color: var(--phv2-text); }
        .phf-muted { font-size: .8125rem; color: var(--phv2-text-muted); margin-top: .125rem; }
        .phf-pending { margin-top: .5rem; font-size: .8125rem; color: #fbbf24; }
        .phf-facts { display: flex; flex-direction: column; }
        .phf-facts-title { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--phv2-text-muted); padding: 0 .25rem .5rem; }
        .phf-fact { padding: 1rem .25rem; border-top: 1px solid var(--phv2-hairline); }
        .phf-facts-title + .phf-fact { border-top: 0; }
        .phf-fact strong { display: block; font-size: .9375rem; font-weight: 600; color: var(--phv2-text); margin-bottom: .25rem; }
        .phf-fact p { font-size: .875rem; line-height: 1.55; color: var(--phv2-text-muted); }
        @media (max-width: 640px) {
            .phf-now { grid-template-columns: minmax(0, 1fr); padding: 1rem; }
        }
    </style>

    <div class="phf-stack">
        @if($this->redm)
            <x-filament::callout color="info" :description="$t('redm')" />
        @else
            @if($this->available())
                <x-filament::callout color="warning" :description="$t('early_access')" />
            @else
                <x-filament::callout color="info" :description="$t('unavailable')" />
            @endif

            <div class="phf-now">
                <div>
                    <div class="phf-big">{{ $t('edition_' . $shown) }}</div>
                    <div class="phf-muted">
                        @if($shown === \PluggedHost\FiveM\Support\FiveM::ENHANCED)
                            {{ $this->installed['build'] ?? null ? $t('runs_enhanced_build', ['build' => $this->installed['build']]) : $t('runs_enhanced') }}
                        @else
                            {{ $t('runs_legacy') }}
                        @endif
                    </div>
                    @if($pending)
                        <div class="phf-pending">{{ $t('pending_' . $this->edition) }}</div>
                    @endif
                </div>
                <div>{{ $this->switchAction }}</div>
            </div>
        @endif

        <div class="phf-facts">
            <div class="phf-facts-title">{{ $t('compatibility') }}</div>
            @foreach(['client', 'scripts', 'assets', 'network', 'switching', 'txadmin'] as $fact)
                <div class="phf-fact">
                    <strong>{{ $t('fact_' . $fact . '_title') }}</strong>
                    <p>{{ $t('fact_' . $fact . '_body', ['build' => $this->enhancedBuild() ?? '?']) }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
