{{--
    The Server configuration page (ServerConfigPage): the settings form, then the three lists
    (resources, group memberships, access control) as plain rows in the same aside sections,
    then the one Save, start-aligned. Lists past FOLD rows render the first ones and a "Show all"
    (server-side, so hidden rows cost nothing): a txAdmin server.cfg easily has a few hundred
    add_ace lines. When txAdmin has not set the
    server up yet there is no server.cfg to edit, and the page points at the two ways to get one.
--}}
@php
    $c = fn (string $key, array $replace = []) => trans('plugged-host-fivem::strings.config.' . $key, $replace);
    $canWrite = user()?->can(\App\Enums\SubuserPermission::FileUpdate, $this->getServer());
    $fold = \PluggedHost\FiveM\Filament\Server\Pages\ServerConfigPage::FOLD;
    $x = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    $lists = [
        'resources' => [['name', $c('resource'), 'chat']],
        'principals' => [['child', $c('principal_child'), 'identifier.fivem:1'], ['parent', $c('principal_parent'), 'group.admin']],
        'aces' => [['principal', $c('ace_principal'), 'group.admin'], ['object', $c('ace_object'), 'command']],
    ];
    $adds = ['resources' => 'add_resource', 'principals' => 'add_principal', 'aces' => 'add_ace'];
@endphp
<x-filament-panels::page>
    <style>
        .phf-form { display: flex; flex-direction: column; gap: 1.5rem; }
        .phf-rows { display: flex; flex-direction: column; gap: .5rem; }
        .phf-line { display: grid; align-items: center; gap: .5rem; }
        .phf-line.phf-l-resources { grid-template-columns: minmax(0, 1fr) auto auto; }
        .phf-line.phf-l-principals { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; }
        .phf-line.phf-l-aces { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 7rem auto; }
        .phf-head { font-size: .75rem; font-weight: 600; color: var(--phv2-text-muted); }
        .phf-check { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; color: var(--phv2-text-nav); white-space: nowrap; }
        .phf-list-foot { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: .25rem; }
        .phf-muted { font-size: .8125rem; color: var(--phv2-text-muted); }
        .phf-x { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: .5rem; color: var(--phv2-text-muted); }
        .phf-x:hover { color: var(--phv2-text); background: var(--phv2-raise-2); }
        .phf-x:focus-visible { outline: 2px solid var(--phv2-accent-bright); outline-offset: 1px; }
        @media (max-width: 640px) {
            .phf-line.phf-l-principals, .phf-line.phf-l-aces { grid-template-columns: minmax(0, 1fr) auto; }
            .phf-line.phf-l-principals > :nth-child(2), .phf-line.phf-l-aces > :nth-child(2), .phf-line.phf-l-aces > :nth-child(3) { grid-column: 1; }
            .phf-line.phf-l-aces > :last-child, .phf-line.phf-l-principals > :last-child { grid-column: 2; grid-row: 1; }
            .phf-head { display: none; }
        }
    </style>

    @if($this->unreachable)
        <x-filament::section>
            <x-slot name="heading">{{ $c('unreachable') }}</x-slot>
            {{ $c('unreachable_body') }}
        </x-filament::section>
    @elseif($this->path === null)
        <x-filament::section>
            <x-slot name="heading">{{ $c('empty') }}</x-slot>
            <p class="phf-muted">{{ $c('empty_body') }}</p>
            @if(\PluggedHost\FiveM\Filament\Server\Pages\FrameworksPage::canAccess())
                <div style="margin-top: 1rem">
                    <x-filament::button tag="a" :href="\PluggedHost\FiveM\Filament\Server\Pages\FrameworksPage::getUrl()">{{ $c('empty_frameworks') }}</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @else
        <form wire:submit="save" class="phf-form">
            {{ $this->form }}

            @foreach($lists as $list => $columns)
                @php $rows = $this->lists[$list]; @endphp
                <x-filament::section :aside="true">
                    <x-slot name="heading">{{ $c($list) }}</x-slot>
                    <x-slot name="description">{{ $c($list . '_hint') }}</x-slot>

                    @php
                        $shown = in_array($list, $this->expanded, true) ? $rows : array_slice($rows, 0, $fold, true);
                        $wrap = 'fi-input-wrp' . ($canWrite ? '' : ' fi-disabled');
                    @endphp
                    <div class="phf-rows">
                        @if($rows !== [])
                            <div class="phf-line phf-l-{{ $list }}">
                                @foreach($columns as [, $label])
                                    <span class="phf-head">{{ $label }}</span>
                                @endforeach
                                @if($list === 'aces')<span class="phf-head">{{ $c('ace_access') }}</span>@endif
                                @if($list === 'resources')<span class="phf-head">{{ $c('enabled') }}</span>@endif
                                <span></span>
                            </div>
                        @endif
                        {{-- Plain markup on Filament's input classes: a server.cfg can have hundreds of rows. --}}
                        @foreach($shown as $i => $row)
                            <div class="phf-line phf-l-{{ $list }}" wire:key="phf-{{ $list }}-{{ $row['uid'] ?? $i }}">
                                @foreach($columns as [$field, $label, $placeholder])
                                    <div class="{{ $wrap }}"><div class="fi-input-wrp-content-ctn"><input class="fi-input" type="text" wire:model="lists.{{ $list }}.{{ $i }}.{{ $field }}" placeholder="{{ $placeholder }}" aria-label="{{ $label }}" @disabled(!$canWrite)></div></div>
                                @endforeach
                                @if($list === 'aces')
                                    <div class="{{ $wrap }}"><div class="fi-input-wrp-content-ctn"><select class="fi-select-input" wire:model="lists.aces.{{ $i }}.access" aria-label="{{ $c('ace_access') }}" @disabled(!$canWrite)><option value="allow">{{ $c('allow') }}</option><option value="deny">{{ $c('deny') }}</option></select></div></div>
                                @endif
                                @if($list === 'resources')
                                    <label class="phf-check"><input type="checkbox" class="fi-checkbox-input" wire:model="lists.resources.{{ $i }}.enabled" @disabled(!$canWrite)> {{ $c('enabled') }}</label>
                                @endif
                                @if($canWrite)
                                    <button type="button" class="phf-x" wire:click="removeRow('{{ $list }}', {{ $i }})" aria-label="{{ $c('remove') }}" title="{{ $c('remove') }}">{!! $x !!}</button>
                                @else
                                    <span></span>
                                @endif
                            </div>
                        @endforeach

                        <div class="phf-list-foot">
                            @if($canWrite)
                                <x-filament::button color="gray" size="sm" wire:click="addRow('{{ $list }}')">{{ $c($adds[$list]) }}</x-filament::button>
                            @endif
                            @if(count($shown) < count($rows))
                                <x-filament::link tag="button" type="button" wire:click="expand('{{ $list }}')">{{ $c('show_all', ['count' => count($rows)]) }}</x-filament::link>
                            @endif
                            @if($rows === [])
                                <span class="phf-muted">{{ $c('list_empty') }}</span>
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            @endforeach

            @if($canWrite)
                <div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $c('save') }}</x-filament::button>
                </div>
            @endif
        </form>
    @endif
</x-filament-panels::page>
