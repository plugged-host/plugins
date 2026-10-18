{{--
    The Artifacts page (ArtifactsPage): what is installed and how updates work on top, as one
    row with a three-way switch (follow Recommended, follow Latest, stay on this build), then
    every build Cfx offers as a full-width row with its marks and a gray Install. No filled
    button on the page: installing is a confirmed, per-row action.
--}}
@php
    $t = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.artifacts.' . $key, $replace);
    $legacy = $this->edition === \PluggedHost\FiveM\Support\FiveM::LEGACY;
    $installedBuild = ($this->installed['edition'] ?? null) === \PluggedHost\FiveM\Support\FiveM::LEGACY ? ($this->installed['build'] ?? null) : null;
    $mode = ctype_digit($this->version) ? 'pin' : $this->version;
    $pending = $this->pending();
    $builds = $legacy ? $this->builds() : [];
    $canChange = $this->canChange();
    $tz = auth()->user()?->timezone ?: config('app.timezone');
@endphp
<x-filament-panels::page>
    <style>
        .phf-stack { display: flex; flex-direction: column; gap: 1.5rem; }
        .phf-now { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 1rem 1.5rem; padding: 1.25rem 1.5rem; border-radius: 1rem; background: var(--phv2-raise); }
        .phf-label { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--phv2-text-muted); }
        .phf-big { font-size: 1.125rem; font-weight: 600; color: var(--phv2-text); font-variant-numeric: tabular-nums; }
        .phf-muted { font-size: .8125rem; color: var(--phv2-text-muted); }
        .phf-seg { display: inline-flex; flex-wrap: wrap; gap: .25rem; padding: .25rem; border-radius: .75rem; background: var(--phv2-sunken); }
        .phf-seg button { padding: .375rem .75rem; border-radius: .5rem; font-size: .8125rem; font-weight: 600; color: var(--phv2-text-nav); }
        .phf-seg button:hover:not(:disabled) { color: var(--phv2-text); }
        .phf-seg button.phf-on { background: var(--phv2-raise-2); color: var(--phv2-text); }
        .phf-seg button:disabled { cursor: default; }
        .phf-pending { margin-top: .5rem; font-size: .8125rem; color: #fbbf24; }
        .phf-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
        .phf-search { width: 100%; max-width: 20rem; }
        .phf-list { display: flex; flex-direction: column; gap: .5rem; }
        .phf-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: .75rem 1rem; padding: .75rem 1.25rem; border-radius: .75rem; background: var(--phv2-raise); }
        .phf-build { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .phf-num { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-weight: 600; font-size: .9375rem; color: var(--phv2-text); }
        .phf-badge { display: inline-flex; align-items: center; font-size: .75rem; line-height: 1rem; font-weight: 600; padding: .125rem .5rem; border-radius: .5rem; background: var(--phv2-raise-2); color: #f3f4f6; }
        .phf-badge-accent { background: var(--phv2-accent-tint); color: var(--phv2-accent-bright); }
        .phf-badge-ok { background: color-mix(in oklab, var(--success-400) 15%, transparent); color: var(--success-400); }
        .phf-badge-warn { background: color-mix(in oklab, #f59e0b 15%, transparent); color: #fbbf24; }
        .phf-note { font-size: .8125rem; line-height: 1.5; color: var(--phv2-text-muted); }
        .phf-empty { padding: 2.5rem 1rem; text-align: center; border-radius: .75rem; background: var(--phv2-raise); font-size: .875rem; color: var(--phv2-text-muted); }
        @media (max-width: 640px) {
            .phf-now { grid-template-columns: minmax(0, 1fr); padding: 1rem; }
            .phf-row { padding: .75rem 1rem; }
            .phf-search { max-width: none; }
        }
    </style>

    <div class="phf-stack">
        @unless($legacy)
            <x-filament::callout color="info" :description="$t('enhanced_note')">
                <x-slot name="footer">
                    <x-filament::link :href="\PluggedHost\FiveM\Filament\Server\Pages\EnhancedPage::getUrl()">{{ $t('enhanced_link') }}</x-filament::link>
                </x-slot>
            </x-filament::callout>
        @endunless

        <div class="phf-now">
            <div>
                <div class="phf-label">{{ $t('installed') }}</div>
                <div class="phf-big">
                    @if($this->unreachable)
                        {{ $t('installed_unreachable') }}
                    @elseif($this->installed === null)
                        {{ $t('installed_unknown') }}
                    @elseif($legacy && $installedBuild)
                        {{ $t('build', ['build' => $installedBuild]) }}
                    @else
                        {{ trans('plugged-host-fivem::strings.enhanced.edition_' . $this->installed['edition']) }} · {{ $t('build', ['build' => $this->installed['build']]) }}
                    @endif
                </div>
                @if($this->installed['installed_at'] ?? null)
                    <div class="phf-muted">{{ $t('installed_at', ['when' => \Carbon\Carbon::parse($this->installed['installed_at'])->setTimezone($tz)->isoFormat('lll')]) }}</div>
                @elseif($this->installed === null && !$this->unreachable)
                    <div class="phf-muted">{{ $t('installed_unknown_body') }}</div>
                @endif
                @if($pending && !$this->unreachable)
                    <div class="phf-pending">{{ $t('pending', ['build' => $pending]) }}</div>
                @endif
            </div>
            @if($legacy)
                <div>
                    <div class="phf-label" style="margin-bottom: .375rem">{{ $t('updates') }}</div>
                    <div class="phf-seg" role="group" aria-label="{{ $t('updates') }}">
                        @foreach(['recommended', 'latest', 'pin'] as $option)
                            <button type="button"
                                    class="{{ $mode === $option ? 'phf-on' : '' }}"
                                    aria-pressed="{{ $mode === $option ? 'true' : 'false' }}"
                                    title="{{ $t('mode_' . $option . '_hint') }}"
                                    @disabled(!$canChange || $mode === $option || ($option === 'pin' && !$installedBuild))
                                    wire:click="mountAction('follow', @js(['mode' => $option]))">
                                {{ $t('mode_' . $option) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if($legacy)
            <div class="phf-tools">
                <div class="phf-search">
                    <x-filament::input.wrapper prefix-icon="tabler-search">
                        <x-filament::input type="search" inputmode="numeric" wire:model.live.debounce.300ms="search" :placeholder="$t('search')" />
                    </x-filament::input.wrapper>
                </div>
                <span class="phf-muted">{{ trans_choice('plugged-host-fivem::strings.artifacts.count', count($builds), ['count' => count($builds)]) }}</span>
            </div>

            @if($builds === [])
                <div class="phf-empty">{{ $this->search !== '' ? $t('no_match') : $t('cfx_unreachable') }}</div>
            @else
                <div class="phf-list">
                    @foreach($builds as $row)
                        <div class="phf-row" wire:key="phf-build-{{ $row['build'] }}">
                            <div>
                                <div class="phf-build">
                                    <span class="phf-num">{{ $t('build', ['build' => $row['build']]) }}</span>
                                    @if($row['recommended'])<span class="phf-badge phf-badge-ok">{{ $t('recommended') }}</span>@endif
                                    @if($row['latest'])<span class="phf-badge phf-badge-accent">{{ $t('latest') }}</span>@endif
                                    @if($row['installed'])<span class="phf-badge">{{ $t('installed_badge') }}</span>@endif
                                    @if($row['ends'] && $row['ends']->isPast())<span class="phf-badge phf-badge-warn">{{ $t('unsupported') }}</span>@endif
                                </div>
                                @if($row['ends'] && !$row['ends']->isPast())
                                    <div class="phf-muted">{{ $t('supported_until', ['date' => $row['ends']->setTimezone($tz)->isoFormat('ll')]) }}</div>
                                @endif
                            </div>
                            @unless($row['installed'] && $row['selected'])
                                {{ ($this->installAction)(['build' => $row['build']]) }}
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        <p class="phf-note">{{ $t('source_note', ['app' => config('app.name')]) }}</p>
    </div>
</x-filament-panels::page>
