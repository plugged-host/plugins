{{--
    The Frameworks page (FrameworksPage): txAdmin's official recipes as full-width rows (logo,
    name, version and author, what it is, a gray Install), and while an install runs, its
    progress in their place, polled. The page has no filled button; installing is the row action
    and its confirmation.
--}}
@php
    $f = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.frameworks.' . $key, $replace);
    $progress = $this->progress();
    $running = ($progress['status'] ?? null) === 'running';
    $total = max(1, (int) ($progress['total'] ?? 1));
    $step = min($total, (int) ($progress['step'] ?? 0));
    $recipes = $progress === null ? $this->recipes() : [];
@endphp
<x-filament-panels::page>
    <style>
        .phf-stack { display: flex; flex-direction: column; gap: 1rem; }
        .phf-current { font-size: .875rem; color: var(--phv2-text-muted); }
        .phf-current code { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .8125rem; color: var(--phv2-text); }
        .phf-recipe { display: grid; grid-template-columns: 3rem minmax(0, 1fr) auto; align-items: center; gap: 1rem 1.25rem; padding: 1.125rem 1.5rem; border-radius: 1rem; background: var(--phv2-raise); }
        .phf-who { min-width: 0; }
        .phf-logo { width: 3rem; height: 3rem; border-radius: .75rem; object-fit: cover; background: var(--phv2-raise-2); }
        .phf-name { font-weight: 600; font-size: 1rem; color: var(--phv2-text); }
        .phf-meta { font-size: .8125rem; color: var(--phv2-text-muted); margin-top: .125rem; }
        .phf-meta code { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .75rem; }
        .phf-desc { font-size: .875rem; line-height: 1.5; color: var(--phv2-text-nav); margin-top: .375rem; }
        .phf-run { display: flex; flex-direction: column; gap: 1rem; padding: 1.5rem; border-radius: 1rem; background: var(--phv2-raise); }
        .phf-run-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem 1rem; }
        .phf-run-title { font-weight: 600; font-size: 1rem; color: var(--phv2-text); }
        .phf-run-count { font-size: .8125rem; color: var(--phv2-text-muted); font-variant-numeric: tabular-nums; }
        .phf-bar { height: .375rem; border-radius: 9999px; background: var(--phv2-sunken); overflow: hidden; }
        .phf-bar > i { display: block; height: 100%; border-radius: inherit; background: var(--phv2-accent-bright); transition: width .3s; }
        .phf-step { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .8125rem; color: var(--phv2-text-nav); overflow-wrap: anywhere; }
        .phf-ok { color: var(--success-400); font-size: .875rem; line-height: 1.5; }
        .phf-err { color: var(--danger-400); font-size: .875rem; line-height: 1.5; overflow-wrap: anywhere; }
        .phf-log { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .75rem; color: var(--phv2-text-muted); }
        .phf-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .phf-note { font-size: .8125rem; line-height: 1.5; color: var(--phv2-text-muted); }
        .phf-empty { padding: 2.5rem 1rem; text-align: center; border-radius: 1rem; background: var(--phv2-raise); font-size: .875rem; color: var(--phv2-text-muted); }
        @media (max-width: 640px) {
            .phf-recipe { grid-template-columns: 2.5rem minmax(0, 1fr); padding: 1rem; }
            .phf-logo { width: 2.5rem; height: 2.5rem; }
            .phf-recipe > :last-child { grid-column: 1 / -1; }
            .phf-run { padding: 1rem; }
        }
    </style>

    <div class="phf-stack" @if($running) wire:poll.2s @endif>
        @if($this->unreachable)
            <div class="phf-empty">{{ $f('unreachable') }}</div>
        @elseif($progress !== null)
            <div class="phf-run">
                <div class="phf-run-head">
                    <span class="phf-run-title">{{ $f('installing', ['recipe' => $progress['recipe'] ?? '']) }}</span>
                    @if($running)
                        <span class="phf-run-count">{{ $f('step_of', ['step' => $step, 'total' => $total]) }}</span>
                    @endif
                </div>

                @if($running)
                    <div class="phf-bar"><i style="width: {{ round($step / $total * 100) }}%"></i></div>
                    <div class="phf-step">{{ $progress['current'] ?? '' }}</div>
                    <p class="phf-note">{{ $f('running_note') }}</p>
                @elseif(($progress['status'] ?? null) === 'done')
                    <p class="phf-ok">{{ $f('done', ['folder' => $progress['deploy'] ?? '']) }}</p>
                    @if($progress['needs_admin'] ?? false)
                        <p class="phf-note">{{ $f('needs_admin') }}</p>
                    @endif
                    @if($progress['previous'] ?? null)
                        <p class="phf-note">{{ $f('previous_kept', ['folder' => $progress['previous']]) }}</p>
                    @endif
                @else
                    <p class="phf-err">{{ $progress['error'] ?? $f('failed_generic') }}</p>
                    <p class="phf-note">{{ $f('failed_note') }}</p>
                @endif

                @if(($progress['log'] ?? []) !== [])
                    <div class="phf-log">
                        @foreach($progress['log'] as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </div>
                @endif

                @unless($running)
                    <div class="phf-actions">
                        @if(($progress['status'] ?? null) === 'done')
                            <x-filament::button tag="a" :href="$this->consoleUrl()" color="gray">{{ $f('open_console') }}</x-filament::button>
                        @endif
                        <x-filament::button color="gray" wire:click="dismiss">{{ $f('dismiss') }}</x-filament::button>
                    </div>
                @endunless
            </div>
        @else
            <p class="phf-current">
                @if($this->current)
                    {!! $f('current', ['folder' => '<code>' . e($this->current) . '</code>']) !!}
                @else
                    {{ $f('current_none') }}
                @endif
            </p>

            @forelse($recipes as $recipe)
                <div class="phf-recipe" wire:key="phf-recipe-{{ $recipe['id'] }}">
                    @if($recipe['logo'])
                        <img class="phf-logo" src="{{ $recipe['logo'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                    @else
                        <span class="phf-logo"></span>
                    @endif
                    <div class="phf-who">
                        <div class="phf-name">{{ $recipe['name'] }}</div>
                        @if($recipe['version'] || $recipe['author'])
                            <div class="phf-meta">
                                @if($recipe['version'])<code>v{{ ltrim($recipe['version'], 'v') }}</code>@endif
                                @if($recipe['version'] && $recipe['author']) · @endif
                                @if($recipe['author']){{ $f('by', ['author' => $recipe['author']]) }}@endif
                            </div>
                        @endif
                        <div class="phf-desc">{{ $recipe['description'] }}</div>
                        @if($recipe['needs_database'])
                            <div class="phf-meta">{{ $f('needs_database') }}</div>
                        @endif
                    </div>
                    <div>{{ ($this->installAction)(['recipe' => $recipe['id']]) }}</div>
                </div>
            @empty
                <div class="phf-empty">{{ $f('index_unreachable') }}</div>
            @endforelse

            <p class="phf-note">{{ $f('source_note', ['app' => config('app.name')]) }}</p>
        @endif
    </div>
</x-filament-panels::page>
