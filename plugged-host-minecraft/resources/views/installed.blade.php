{{-- Installed plugins/mods, embedded at the top of the ServerContent page (above the browse
     table). Reads the page's public $rows and renders its row actions (delete, enable/disable,
     update) with the jar name as the argument; the page re-checks everything server-side.
     Scoped plain CSS on Filament's colour variables, so it needs no asset build. --}}
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
        'modrinth' => ['Modrinth', 'phm-src-modrinth'],
        'curseforge' => ['CurseForge', 'phm-src-curseforge'],
        'spigotmc' => ['SpigotMC', 'phm-src-spigot'],
    ];
    $pending = collect($this->rows)->where('pending', true)->count();
@endphp
<style>
    .phm-list { display: flex; flex-direction: column; border-radius: .9rem; border: 1px solid rgba(127,127,127,.18); overflow: hidden; }
    .phm-row { display: flex; align-items: center; gap: .85rem; padding: .7rem .9rem; border-top: 1px solid rgba(127,127,127,.14); }
    .phm-row:first-child { border-top: 0; }
    .phm-row.phm-off { opacity: .55; }
    .phm-icon { width: 2.5rem; height: 2.5rem; flex: none; border-radius: .6rem; display: grid; place-items: center; background: rgba(127,127,127,.12); color: rgba(127,127,127,.9); overflow: hidden; }
    .phm-icon img { width: 100%; height: 100%; object-fit: cover; }
    .phm-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .2rem; }
    .phm-line { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .45rem; }
    .phm-name { font-weight: 600; font-size: .9rem; }
    .phm-name a:hover { text-decoration: underline; }
    .phm-meta { font-size: .75rem; opacity: .65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .phm-chip { display: inline-flex; align-items: center; gap: .2rem; font-size: .68rem; font-weight: 600; padding: .08rem .45rem; border-radius: 9999px; background: rgba(127,127,127,.14); }
    .phm-src-modrinth { background: rgba(16,185,129,.14); color: #10b981; }
    .phm-src-curseforge { background: rgba(249,115,22,.14); color: #f97316; }
    .phm-src-spigot { background: rgba(234,179,8,.14); color: #d4a106; }
    .phm-update { background: rgba(59,130,246,.15); color: #60a5fa; }
    .phm-warn { background: rgba(244,63,94,.14); color: #fb7185; }
    .phm-actions { display: flex; align-items: center; gap: .15rem; flex: none; }
    .phm-i { width: 1rem; height: 1rem; flex: none; }
    .phm-i-lg { width: 1.3rem; height: 1.3rem; }
    .phm-spin { animation: phm-spin .9s linear infinite; }
    @keyframes phm-spin { to { transform: rotate(360deg); } }
    .phm-empty { border-radius: .9rem; border: 1px dashed rgba(127,127,127,.3); padding: 1.5rem; text-align: center; font-size: .875rem; opacity: .7; }
    .phm-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .5rem; }
    .phm-head h3 { font-size: .875rem; font-weight: 600; }
    .phm-progress { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; opacity: .7; }
    .phm-rescan { opacity: .6; }
    .phm-rescan:hover { opacity: 1; }
    @media (max-width: 640px) {
        .phm-row { align-items: flex-start; gap: .65rem; padding: .7rem; }
        .phm-icon { width: 2.1rem; height: 2.1rem; }
    }
</style>
<div>
    <div class="phm-head">
        <h3>{{ $m('installed_heading') }} <span style="opacity: .5; font-weight: 500;">{{ count($this->rows) ?: '' }}</span></h3>
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
                @endphp
                <div class="phm-row {{ $row['disabled'] ? 'phm-off' : '' }}" wire:key="phm-{{ md5($row['file']) }}">
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
                                <span class="phm-chip">{{ $row['version'] }}</span>
                            @endif
                            @if($source)
                                <span class="phm-chip {{ $source[1] }}" title="{{ $m('confidence_' . $row['confidence']) }}">
                                    {{ $source[0] }}@if($row['confidence'] === 'name') ~@endif
                                </span>
                            @elseif(!$row['pending'] && $row['confidence'] === 'unknown')
                                <span class="phm-chip" title="{{ $m('identify_unknown') }}">{{ $m('unknown') }}</span>
                            @endif
                            @if($row['disabled'])
                                <span class="phm-chip">{!! $icon('tabler-player-pause', 'phm-i') !!}{{ $m('disabled') }}</span>
                            @endif
                            @if($row['update'])
                                <span class="phm-chip phm-update">{!! $icon('tabler-arrow-up', 'phm-i') !!}{{ $row['update']['number'] }}</span>
                            @endif
                            @foreach($row['missing'] as $dependency)
                                <span class="phm-chip phm-warn" title="{{ $m('missing_hint', ['name' => $dependency]) }}">{!! $icon('tabler-alert-triangle', 'phm-i') !!}{{ $m('missing', ['name' => $dependency]) }}</span>
                            @endforeach
                        </div>
                        <div class="phm-meta" title="{{ $row['description'] }}">{{ implode(' · ', $meta) }}</div>
                    </div>

                    <div class="phm-actions">
                        @unless($row['pending'])
                            {{ ($this->updateJarAction)(['file' => $row['file']]) }}
                            {{ ($this->toggleJarAction)(['file' => $row['file']]) }}
                            {{ ($this->deleteJarAction)(['file' => $row['file']]) }}
                            <button type="button" class="phm-rescan" wire:click="identify(@js($row['file']))" title="{{ $m('identify') }}" aria-label="{{ $m('identify') }}">{!! $icon('tabler-refresh', 'phm-i') !!}</button>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
