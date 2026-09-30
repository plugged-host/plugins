{{-- Installed plugins/mods, embedded at the top of the ServerContent page (above the browse
     table). Reads the page's public $rows and renders its row actions (delete, enable/disable,
     update) with the jar name as the argument; the page re-checks everything server-side.
     Scoped plain CSS on theme-v2's --phv2-* tokens, so it needs no asset build. One card, rows
     split by a hairline; where a jar came from is plain text in the meta line, badges are kept
     for state (disabled, update available, missing dependency). --}}
@php
    $m = fn (string $key, array $replace = []) => trans('plugged-host-minecraft::strings.mods.' . $key, $replace);
    $icon = fn (string $name, string $class = 'phm-i') => svg($name, $class)->toHtml();
    $human = function (int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < 3) { $size /= 1024; $i++; }

        return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    };
    $platforms = [
        'modrinth' => 'Modrinth',
        'curseforge' => 'CurseForge',
        'spigotmc' => 'SpigotMC',
    ];
    $pending = collect($this->rows)->where('pending', true)->count();
@endphp
<style>
    .phm-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .75rem; }
    .phm-head h3 { font-size: 1rem; font-weight: 600; color: var(--phv2-text); }
    .phm-count { margin-inline-start: .25rem; font-weight: 500; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
    .phm-list { display: flex; flex-direction: column; border-radius: 1rem; background: var(--phv2-raise); overflow: hidden; }
    .phm-row { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; }
    .phm-row + .phm-row { border-top: 1px solid var(--phv2-hairline); }
    .phm-icon { width: 2.5rem; height: 2.5rem; flex: none; border-radius: .5rem; display: grid; place-items: center; background: var(--phv2-raise); color: var(--phv2-text-faint); overflow: hidden; }
    .phm-icon img { width: 100%; height: 100%; object-fit: cover; }
    .phm-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .25rem; }
    .phm-line { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .5rem; }
    .phm-name { font-weight: 600; font-size: .875rem; color: var(--phv2-text); }
    .phm-name a:hover { color: var(--phv2-accent-glow); }
    .phm-version { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .75rem; color: var(--phv2-text-muted); }
    .phm-meta { font-size: .75rem; color: var(--phv2-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .phm-badge { display: inline-flex; align-items: center; gap: .25rem; font-size: .75rem; line-height: 1rem; font-weight: 600; padding: .125rem .5rem; border-radius: .5rem; background: var(--phv2-raise-2); color: #f3f4f6; }
    .phm-update { background: var(--phv2-accent-tint); color: var(--phv2-accent-bright); }
    .phm-warn { background: color-mix(in oklab, #ef4444 15%, transparent); color: #f87171; }
    .phm-actions { display: flex; align-items: center; gap: .25rem; flex: none; }
    .phm-i { width: .875rem; height: .875rem; flex: none; }
    .phm-i-lg { width: 1.25rem; height: 1.25rem; }
    .phm-spin { animation: phm-spin .9s linear infinite; }
    @keyframes phm-spin { to { transform: rotate(360deg); } }
    .phm-empty { border-radius: 1rem; background: var(--phv2-raise); padding: 1.5rem; text-align: center; font-size: .875rem; color: var(--phv2-text-muted); }
    .phm-progress { display: inline-flex; align-items: center; gap: .375rem; font-size: .75rem; color: var(--phv2-text-muted); }
    @media (max-width: 640px) {
        .phm-row { align-items: flex-start; gap: .625rem; padding: .75rem; }
        .phm-icon { width: 2.25rem; height: 2.25rem; }
    }
</style>
<div>
    <div class="phm-head">
        <h3>{{ $m('installed_heading') }}<span class="phm-count">{{ count($this->rows) ?: '' }}</span></h3>
        @if($pending > 0)
            <span class="phm-progress">{!! $icon('tabler-loader-2', 'phm-i phm-spin') !!}{{ $m('identifying', ['count' => $pending]) }}</span>
            {{-- A fresh key per round re-runs x-init, which asks for the next batch. --}}
            <span wire:key="phm-identify-{{ $this->identifyRound }}" x-init="$wire.identifyPending()"></span>
        @endif
    </div>

    @if (count($this->rows) === 0)
        <div class="phm-empty">{{ $m('empty') }}</div>
    @else
        <div class="phm-list">
            @foreach ($this->rows as $row)
                @php
                    $source = $platforms[$row['platform'] ?? ''] ?? null;
                    $meta = array_filter([
                        $row['authors'] !== [] ? implode(', ', array_slice($row['authors'], 0, 2)) : null,
                        $row['file'],
                        $row['size'] > 0 ? $human((int) $row['size']) : null,
                    ]);
                    $sourceLabel = $source !== null
                        ? $source . ($row['confidence'] === 'name' ? ' ~' : '')
                        : (!$row['pending'] && $row['confidence'] === 'unknown' ? $m('unknown') : null);
                    $sourceTitle = $source !== null ? $m('confidence_' . $row['confidence']) : $m('identify_unknown');
                @endphp
                <div class="phm-row" wire:key="phm-{{ md5($row['file']) }}">
                    <div class="phm-icon">
                        @if($row['pending'])
                            {!! $icon('tabler-loader-2', 'phm-i-lg phm-spin') !!}
                        @elseif(filled($row['icon']))
                            <img src="{{ $row['icon'] }}" alt="" loading="lazy">
                        @else
                            {!! $icon($row['kind'] === 'mod' ? 'tabler-box' : 'tabler-puzzle', 'phm-i-lg') !!}
                        @endif
                    </div>

                    <div class="phm-main">
                        <div class="phm-line">
                            <span class="phm-name">
                                @if(filled($row['url']))
                                    <a href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                            </span>
                            @if(filled($row['version']))
                                <span class="phm-version">{{ $row['version'] }}</span>
                            @endif
                            @if($row['disabled'])
                                <span class="phm-badge">{{ $m('disabled') }}</span>
                            @endif
                            @if($row['update'])
                                <span class="phm-badge phm-update" title="{{ $m('update', ['version' => $row['update']['number']]) }}">{!! $icon('tabler-arrow-up', 'phm-i') !!}{{ $row['update']['number'] }}</span>
                            @endif
                            @foreach($row['missing'] as $dependency)
                                <span class="phm-badge phm-warn" title="{{ $m('missing_hint', ['name' => $dependency]) }}">{{ $m('missing', ['name' => $dependency]) }}</span>
                            @endforeach
                        </div>
                        <div class="phm-meta" title="{{ $row['description'] }}">
                            @if($sourceLabel !== null)
                                <span title="{{ $sourceTitle }}">{{ $sourceLabel }}</span>{{ $meta !== [] ? ' ·' : '' }}
                            @endif
                            {{ implode(' · ', $meta) }}
                        </div>
                    </div>

                    <div class="phm-actions">
                        @unless($row['pending'])
                            {{ ($this->updateJarAction)(['file' => $row['file']]) }}
                            {{ ($this->toggleJarAction)(['file' => $row['file']]) }}
                            {{ ($this->deleteJarAction)(['file' => $row['file']]) }}
                            <button type="button" class="fi-icon-btn fi-size-md" wire:click="identify(@js($row['file']))" x-tooltip="{ content: @js($m('identify')), theme: $store.theme }" aria-label="{{ $m('identify') }}">{!! $icon('tabler-refresh', 'phm-i-lg') !!}</button>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
