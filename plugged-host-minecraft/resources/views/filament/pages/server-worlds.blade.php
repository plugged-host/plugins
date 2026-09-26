{{--
    The Worlds page (ServerWorlds): one full-width row per world with its actions, rendered as
    Filament actions so their modals, permissions and confirmation come for free. The active
    world shows its generation settings as tiles and a drop zone for uploads; the upload goes
    from the browser straight to the node (a signed wings URL from uploadUrl(), as the file
    manager does), so a large world never passes through the panel, and importWorld() queues
    the rest. Scoped plain CSS on neutral colours, so it needs no asset build.
--}}
@php
    $w = fn (string $key, array $replace = []) => trans('plugged-host-minecraft::strings.worlds.' . $key, $replace);
    $icon = fn (string $name, string $class = 'phw-i') => svg($name, $class)->toHtml();
    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $canUpload = user()?->can(\App\Enums\SubuserPermission::FileCreate, $this->getServer()) && user()?->can(\App\Enums\SubuserPermission::FileDelete, $this->getServer());
    $canFiles = user()?->can(\App\Enums\SubuserPermission::FileRead, $this->getServer());
    $p = $this->properties;
    $when = fn (?string $time): ?string => filled($time) ? \Carbon\Carbon::parse($time)->setTimezone($tz)->isoFormat('lll') : null;
    $tiles = [
        ['tabler-seedling', $w('seed'), filled($p['level-seed'] ?? null) ? $p['level-seed'] : $w('seed_random'), true],
        ['tabler-map', $w('type'), \Illuminate\Support\Str::after($p['level-type'] ?? 'minecraft:normal', ':'), false],
        ['tabler-flame', $w('difficulty'), ucfirst($p['difficulty'] ?? 'easy'), false],
        ['tabler-sword', $w('gamemode'), ucfirst($p['gamemode'] ?? 'survival') . (($p['hardcore'] ?? 'false') === 'true' ? ' · ' . $w('hardcore') : ''), false],
        ['tabler-building-castle', $w('structures'), ($p['generate-structures'] ?? 'true') === 'false' ? $w('off') : $w('on'), false],
    ];
@endphp
<x-filament-panels::page>
    <style>
        .phw-list { display: flex; flex-direction: column; gap: 1rem; }
        .phw-card { display: grid; grid-template-columns: auto minmax(0, 1fr) 11rem; grid-template-areas: "globe head actions" "stats stats actions" "upload upload upload"; align-items: start; gap: 1rem 1.5rem; padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(127,127,127,.2); background: rgba(127,127,127,.05); }
        .phw-card.phw-active { border-color: rgba(59,130,246,.45); background: linear-gradient(135deg, rgba(59,130,246,.07), rgba(34,197,94,.04)); }
        .phw-card.phw-other { grid-template-areas: "globe head actions"; align-items: center; padding: 1rem 1.25rem; }
        .phw-globe { grid-area: globe; width: 4rem; height: 4rem; border-radius: 1rem; display: grid; place-items: center; background: linear-gradient(135deg, #16a34a, #0e7490); color: #fff; box-shadow: 0 8px 20px rgba(14,116,144,.25); }
        .phw-globe .phw-i { width: 2rem; height: 2rem; }
        .phw-other .phw-globe { width: 3rem; height: 3rem; border-radius: .85rem; background: rgba(127,127,127,.18); color: inherit; box-shadow: none; }
        .phw-other .phw-globe .phw-i { width: 1.5rem; height: 1.5rem; }
        .phw-head { grid-area: head; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: .45rem; min-height: 4rem; }
        .phw-name { font-weight: 700; font-size: 1.3rem; line-height: 1.2; word-break: break-all; }
        .phw-other .phw-name { font-size: 1.05rem; }
        .phw-chips { display: flex; flex-wrap: wrap; gap: .35rem; }
        .phw-chip { display: inline-flex; align-items: center; gap: .25rem; font-size: .7rem; font-weight: 600; padding: .12rem .55rem; border-radius: 9999px; background: rgba(127,127,127,.15); }
        .phw-chip-active { background: rgba(59,130,246,.15); color: #60a5fa; }
        .phw-chip-new { background: rgba(234,179,8,.15); color: #d4a106; }
        .phw-chip-nether { background: rgba(239,68,68,.13); color: #f87171; }
        .phw-chip-end { background: rgba(168,85,247,.14); color: #c084fc; }
        .phw-stats { grid-area: stats; display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: .6rem; }
        .phw-tile { display: flex; flex-direction: column; gap: .3rem; padding: .7rem .8rem; border-radius: .8rem; background: rgba(127,127,127,.08); border: 1px solid rgba(127,127,127,.12); min-width: 0; }
        .phw-tile-label { display: inline-flex; align-items: center; gap: .35rem; font-size: .72rem; opacity: .65; }
        .phw-tile-value { font-weight: 600; font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .phw-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .82rem; }
        .phw-actions { grid-area: actions; display: flex; flex-direction: column; gap: .45rem; }
        .phw-actions > * { width: 100%; }
        .phw-i { width: 1rem; height: 1rem; flex: none; }
        .phw-note { display: flex; align-items: center; gap: .6rem; padding: .75rem 1rem; border-radius: .8rem; border: 1px solid rgba(234,179,8,.35); background: rgba(234,179,8,.08); font-size: .85rem; }
        .phw-upload { grid-area: upload; }
        .phw-drop { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.1rem; border-radius: .9rem; border: 2px dashed rgba(127,127,127,.3); transition: border-color .15s, background .15s; cursor: pointer; }
        .phw-drop:hover, .phw-drop.phw-over { border-color: rgba(34,197,94,.6); background: rgba(34,197,94,.06); }
        .phw-drop.phw-locked { cursor: not-allowed; opacity: .6; }
        .phw-drop-icon { width: 2.75rem; height: 2.75rem; flex: none; border-radius: .8rem; display: grid; place-items: center; background: rgba(34,197,94,.14); color: #22c55e; }
        .phw-drop-icon .phw-i { width: 1.4rem; height: 1.4rem; }
        .phw-drop-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .15rem; font-size: .8rem; }
        .phw-drop-text strong { font-size: .9rem; }
        .phw-drop-text span { opacity: .65; }
        .phw-upload-foot { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-top: .6rem; font-size: .8rem; }
        .phw-bar { height: .45rem; border-radius: 9999px; background: rgba(127,127,127,.2); overflow: hidden; margin-top: .6rem; }
        .phw-bar > i { display: block; height: 100%; background: linear-gradient(90deg, #22c55e, #0ea5e9); transition: width .2s; }
        .phw-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .phw-btn { display: inline-flex; align-items: center; gap: .35rem; border-radius: .5rem; padding: .45rem .9rem; font-size: .8rem; font-weight: 600; background: #16a34a; color: #fff; }
        .phw-btn:disabled { opacity: .45; }
        .phw-link { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; font-size: .8rem; font-weight: 600; padding: .35rem .7rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.3); }
        .phw-link:hover { background: rgba(127,127,127,.1); }
        .phw-help { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; margin-top: .5rem; }
        .phw-help-card { display: flex; gap: .85rem; padding: 1rem 1.1rem; border-radius: 1rem; border: 1px solid rgba(127,127,127,.15); background: rgba(127,127,127,.04); font-size: .8rem; line-height: 1.5; }
        .phw-help-card strong { display: block; font-size: .875rem; margin-bottom: .2rem; }
        .phw-help-card p { opacity: .7; }
        .phw-help-icon { width: 2.25rem; height: 2.25rem; flex: none; border-radius: .7rem; display: grid; place-items: center; background: rgba(59,130,246,.12); color: #60a5fa; }
        @media (max-width: 900px) {
            .phw-card, .phw-card.phw-other { grid-template-columns: auto minmax(0, 1fr); grid-template-areas: "globe head" "stats stats" "actions actions" "upload upload"; padding: 1rem; }
            .phw-card.phw-other { grid-template-areas: "globe head" "actions actions"; }
            .phw-globe { width: 3rem; height: 3rem; border-radius: .85rem; }
            .phw-globe .phw-i { width: 1.6rem; height: 1.6rem; }
            .phw-head { min-height: 3rem; }
            .phw-actions { flex-direction: row; flex-wrap: wrap; }
            .phw-actions > * { width: auto; flex: 1 1 8rem; }
            .phw-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>

    @unless($this->offline)
        <div class="phw-note">{!! $icon('tabler-player-stop') !!}<span>{{ $w('stop_first_long') }}</span></div>
    @endunless

    <div class="phw-list">
        @foreach($this->worlds as $world)
            <div class="phw-card {{ $world['active'] ? 'phw-active' : 'phw-other' }}" wire:key="phw-{{ md5($world['name']) }}">
                <div class="phw-globe">{!! $icon('tabler-world') !!}</div>

                <div class="phw-head">
                    <div class="phw-name">{{ $world['name'] }}</div>
                    <div class="phw-chips">
                        @if($world['active'])
                            <span class="phw-chip phw-chip-active">{!! $icon('tabler-check') !!}{{ $w('active') }}</span>
                        @endif
                        @unless($world['exists'])
                            <span class="phw-chip phw-chip-new" title="{{ $w('not_generated_hint') }}">{!! $icon('tabler-sparkles') !!}{{ $w('not_generated') }}</span>
                        @endunless
                        @if(in_array($world['name'] . '_nether', $world['folders'], true))
                            <span class="phw-chip phw-chip-nether">{!! $icon('tabler-flame') !!}{{ $w('nether') }}</span>
                        @endif
                        @if(in_array($world['name'] . '_the_end', $world['folders'], true))
                            <span class="phw-chip phw-chip-end">{!! $icon('tabler-planet') !!}{{ $w('the_end') }}</span>
                        @endif
                        @if(!$world['active'] && $when($world['modified']))
                            <span class="phw-chip">{!! $icon('tabler-clock') !!}{{ $when($world['modified']) }}</span>
                        @endif
                    </div>
                </div>

                @if($world['active'])
                    <div class="phw-stats">
                        @foreach($tiles as [$tileIcon, $tileLabel, $tileValue, $mono])
                            <div class="phw-tile">
                                <span class="phw-tile-label">{!! $icon($tileIcon) !!}{{ $tileLabel }}</span>
                                <span class="phw-tile-value {{ $mono ? 'phw-mono' : '' }}" title="{{ $tileValue }}">{{ $tileValue }}</span>
                            </div>
                        @endforeach
                        <div class="phw-tile">
                            <span class="phw-tile-label">{!! $icon('tabler-clock') !!}{{ $w('modified') }}</span>
                            <span class="phw-tile-value">{{ $when($world['modified']) ?? $w('never') }}</span>
                        </div>
                    </div>
                @endif

                <div class="phw-actions">
                    {{ ($this->downloadAction)(['world' => $world['name']]) }}
                    {{ ($this->optionsAction)(['world' => $world['name']]) }}
                    {{ ($this->generateAction)(['world' => $world['name']]) }}
                    {{ ($this->activateAction)(['world' => $world['name']]) }}
                    @if($canFiles && $world['exists'])
                        <a class="phw-link" href="{{ $this->filesUrl($world['name']) }}" wire:navigate>{!! $icon('tabler-folder') !!}{{ $w('files') }}</a>
                    @endif
                    {{ ($this->deleteWorldAction)(['world' => $world['name']]) }}
                </div>

                @if($world['active'] && $canUpload)
                    <div class="phw-upload"
                         x-data="{
                            file: null,
                            keepCopy: true,
                            progress: null,
                            error: null,
                            over: false,
                            locked: {{ $this->offline ? 'false' : 'true' }},
                            limit: {{ $this->getUploadLimitMb() }} * 1024 * 1024,
                            choose(file) {
                                this.error = null;
                                this.file = null;
                                if (!file || this.locked) { return; }
                                if (!/\.(zip|tar|tar\.gz|tgz)$/i.test(file.name)) {
                                    this.error = @js($w('error_archive_missing'));
                                } else if (file.size > this.limit) {
                                    this.error = @js($w('too_large', ['limit' => $this->getUploadLimitMb()]));
                                } else {
                                    this.file = file;
                                }
                            },
                            async start() {
                                if (!this.file || this.progress !== null || this.locked) { return; }
                                this.error = null;
                                this.progress = 0;
                                try {
                                    const url = new URL(await $wire.uploadUrl());
                                    url.searchParams.append('directory', '/{{ \PluggedHost\Minecraft\Services\WorldService::UPLOAD_DIRECTORY }}');
                                    await new Promise((resolve, reject) => {
                                        const xhr = new XMLHttpRequest();
                                        const form = new FormData();
                                        form.append('files', this.file);
                                        xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) { this.progress = Math.round(e.loaded / e.total * 100); } });
                                        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300 ? resolve() : reject());
                                        xhr.onerror = () => reject();
                                        xhr.open('POST', url.toString());
                                        xhr.send(form);
                                    });
                                    await $wire.importWorld(this.file.name, this.keepCopy);
                                    this.file = null;
                                    this.$refs.picker.value = '';
                                } catch (e) {
                                    this.error = @js($w('upload_failed'));
                                } finally {
                                    this.progress = null;
                                }
                            },
                         }">
                        <div class="phw-drop" x-bind:class="{ 'phw-over': over, 'phw-locked': locked }"
                             x-on:click="if (!locked && progress === null) { $refs.picker.click(); }"
                             x-on:dragover.prevent="over = !locked"
                             x-on:dragleave.prevent="over = false"
                             x-on:drop.prevent="over = false; choose($event.dataTransfer.files[0])">
                            <div class="phw-drop-icon">{!! $icon('tabler-cloud-upload') !!}</div>
                            <div class="phw-drop-text">
                                <strong x-text="file ? file.name : @js($w('upload'))">{{ $w('upload') }}</strong>
                                <span x-show="!locked">{{ $w('drop_hint', ['limit' => $this->getUploadLimitMb()]) }}</span>
                                <span x-show="locked" x-cloak>{{ $w('stop_first') }}</span>
                            </div>
                            <input type="file" x-ref="picker" accept=".zip,.tar,.tar.gz,.tgz" hidden x-on:change="choose($event.target.files[0])">
                        </div>
                        <div class="phw-bar" x-show="progress !== null" x-cloak><i x-bind:style="'width: ' + progress + '%'"></i></div>
                        <div class="phw-row" x-show="error" x-cloak style="color: #fb7185; margin-top: .6rem;">{!! $icon('tabler-alert-circle') !!}<span x-text="error"></span></div>
                        <div class="phw-upload-foot" x-show="file" x-cloak>
                            <label class="phw-row"><input type="checkbox" x-model="keepCopy"> {{ $w('keep_copy') }}</label>
                            <div class="phw-row">
                                <span style="opacity: .65;">{{ $w('deletes_current') }}</span>
                                <button type="button" class="phw-btn" x-on:click="start()" x-bind:disabled="!file || progress !== null || locked">
                                    {!! $icon('tabler-upload') !!}<span x-text="progress !== null ? progress + '%' : @js($w('upload_replace'))"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="phw-help">
        @foreach([['tabler-device-desktop-up', 'help_upload'], ['tabler-shield-check', 'help_safe'], ['tabler-arrows-exchange', 'help_switch']] as [$helpIcon, $help])
            <div class="phw-help-card">
                <div class="phw-help-icon">{!! $icon($helpIcon) !!}</div>
                <div>
                    <strong>{{ $w($help . '_title') }}</strong>
                    <p>{{ $w($help . '_body') }}</p>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
