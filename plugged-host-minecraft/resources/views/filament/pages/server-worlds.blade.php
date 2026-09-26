{{--
    The Worlds page (ServerWorlds): one card per world with its actions, rendered as Filament
    actions so their modals, permissions and confirmation come for free. The upload goes from
    the browser straight to the node (a signed wings URL from uploadUrl(), as the file manager
    does), so a large world never passes through the panel; importWorld() then queues the rest.
    Scoped plain CSS on neutral colours, so it needs no asset build.
--}}
@php
    $w = fn (string $key, array $replace = []) => trans('plugged-host-minecraft::strings.worlds.' . $key, $replace);
    $icon = fn (string $name, string $class = 'phw-i') => svg($name, $class)->toHtml();
    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $canUpload = user()?->can(\App\Enums\SubuserPermission::FileCreate, $this->getServer()) && user()?->can(\App\Enums\SubuserPermission::FileDelete, $this->getServer());
    $canFiles = user()?->can(\App\Enums\SubuserPermission::FileRead, $this->getServer());
@endphp
<x-filament-panels::page>
    <style>
        .phw-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 24rem), 1fr)); }
        .phw-card { border-radius: 1rem; border: 1px solid rgba(127,127,127,.2); background: rgba(127,127,127,.05); padding: 1rem; display: flex; flex-direction: column; gap: .9rem; }
        .phw-card.phw-active { border-color: rgba(59,130,246,.45); box-shadow: inset 0 0 0 1px rgba(59,130,246,.15); }
        .phw-top { display: flex; align-items: center; gap: .85rem; }
        .phw-globe { width: 3rem; height: 3rem; flex: none; border-radius: .9rem; display: grid; place-items: center; background: linear-gradient(135deg, #16a34a, #0e7490); color: #fff; }
        .phw-globe .phw-i { width: 1.6rem; height: 1.6rem; }
        .phw-name { font-weight: 700; font-size: 1.05rem; word-break: break-all; }
        .phw-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .25rem; }
        .phw-chip { display: inline-flex; align-items: center; gap: .25rem; font-size: .7rem; font-weight: 600; padding: .1rem .5rem; border-radius: 9999px; background: rgba(127,127,127,.15); }
        .phw-chip-active { background: rgba(59,130,246,.15); color: #60a5fa; }
        .phw-chip-new { background: rgba(234,179,8,.15); color: #d4a106; }
        .phw-facts { display: grid; grid-template-columns: auto 1fr; gap: .25rem .75rem; font-size: .8rem; }
        .phw-facts dt { opacity: .6; display: inline-flex; align-items: center; gap: .3rem; }
        .phw-facts dd { font-family: 'JetBrains Mono', ui-monospace, monospace; word-break: break-all; }
        .phw-actions { display: flex; flex-wrap: wrap; gap: .4rem; }
        .phw-i { width: 1rem; height: 1rem; flex: none; }
        .phw-note { display: flex; align-items: center; gap: .6rem; padding: .75rem 1rem; border-radius: .8rem; border: 1px solid rgba(234,179,8,.35); background: rgba(234,179,8,.08); font-size: .85rem; }
        .phw-upload { border-radius: .8rem; border: 1px dashed rgba(127,127,127,.35); padding: .85rem; display: flex; flex-direction: column; gap: .6rem; font-size: .8rem; }
        .phw-upload input[type=file] { font-size: .8rem; max-width: 100%; }
        .phw-bar { height: .45rem; border-radius: 9999px; background: rgba(127,127,127,.2); overflow: hidden; }
        .phw-bar > i { display: block; height: 100%; background: linear-gradient(90deg, #22c55e, #0ea5e9); transition: width .2s; }
        .phw-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .phw-btn { display: inline-flex; align-items: center; gap: .35rem; border-radius: .5rem; padding: .35rem .75rem; font-size: .8rem; font-weight: 600; background: #16a34a; color: #fff; }
        .phw-btn:disabled { opacity: .45; }
        .phw-link { display: inline-flex; align-items: center; gap: .35rem; font-size: .8rem; font-weight: 600; padding: .35rem .7rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.3); }
        .phw-link:hover { background: rgba(127,127,127,.1); }
    </style>

    @unless($this->offline)
        <div class="phw-note">{!! $icon('tabler-player-stop') !!}<span>{{ $w('stop_first_long') }}</span></div>
    @endunless

    <div class="phw-grid">
        @foreach($this->worlds as $world)
            <div class="phw-card {{ $world['active'] ? 'phw-active' : '' }}" wire:key="phw-{{ md5($world['name']) }}">
                <div class="phw-top">
                    <div class="phw-globe">{!! $icon('tabler-world') !!}</div>
                    <div style="min-width: 0;">
                        <div class="phw-name">{{ $world['name'] }}</div>
                        <div class="phw-chips">
                            @if($world['active'])
                                <span class="phw-chip phw-chip-active">{!! $icon('tabler-check') !!}{{ $w('active') }}</span>
                            @endif
                            @unless($world['exists'])
                                <span class="phw-chip phw-chip-new" title="{{ $w('not_generated_hint') }}">{!! $icon('tabler-sparkles') !!}{{ $w('not_generated') }}</span>
                            @endunless
                            @if(in_array($world['name'] . '_nether', $world['folders'], true))
                                <span class="phw-chip">{{ $w('nether') }}</span>
                            @endif
                            @if(in_array($world['name'] . '_the_end', $world['folders'], true))
                                <span class="phw-chip">{{ $w('the_end') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($world['active'])
                    <dl class="phw-facts">
                        <dt>{!! $icon('tabler-seedling') !!}{{ $w('seed') }}</dt>
                        <dd>{{ filled($this->properties['level-seed'] ?? null) ? $this->properties['level-seed'] : $w('seed_random') }}</dd>
                        <dt>{!! $icon('tabler-map') !!}{{ $w('type') }}</dt>
                        <dd>{{ $this->properties['level-type'] ?? 'minecraft:normal' }}</dd>
                        @if(filled($world['modified']))
                            <dt>{!! $icon('tabler-clock') !!}{{ $w('modified') }}</dt>
                            <dd>{{ \Carbon\Carbon::parse($world['modified'])->setTimezone($tz)->isoFormat('lll') }}</dd>
                        @endif
                    </dl>
                @elseif(filled($world['modified']))
                    <dl class="phw-facts">
                        <dt>{!! $icon('tabler-clock') !!}{{ $w('modified') }}</dt>
                        <dd>{{ \Carbon\Carbon::parse($world['modified'])->setTimezone($tz)->isoFormat('lll') }}</dd>
                    </dl>
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
                            limit: {{ $this->getUploadLimitMb() }} * 1024 * 1024,
                            pick(e) {
                                this.error = null;
                                this.file = e.target.files[0] || null;
                                if (this.file && this.file.size > this.limit) {
                                    this.error = @js($w('too_large', ['limit' => $this->getUploadLimitMb()]));
                                    this.file = null;
                                }
                            },
                            async start() {
                                if (!this.file || this.progress !== null) { return; }
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
                        <div class="phw-row">{!! $icon('tabler-upload') !!}<strong>{{ $w('upload') }}</strong><span style="opacity: .65;">{{ $w('upload_hint', ['limit' => $this->getUploadLimitMb()]) }}</span></div>
                        <input type="file" x-ref="picker" accept=".zip,.tar,.tar.gz,.tgz" x-on:change="pick($event)" @disabled(!$this->offline)>
                        <label class="phw-row"><input type="checkbox" x-model="keepCopy"> {{ $w('keep_copy') }}</label>
                        <div class="phw-bar" x-show="progress !== null" x-cloak><i x-bind:style="'width: ' + progress + '%'"></i></div>
                        <div class="phw-row" x-show="error" x-cloak style="color: #fb7185;">{!! $icon('tabler-alert-circle') !!}<span x-text="error"></span></div>
                        <div class="phw-row">
                            <button type="button" class="phw-btn" x-on:click="start()" x-bind:disabled="!file || progress !== null" @disabled(!$this->offline)>
                                {!! $icon('tabler-upload') !!}<span x-text="progress !== null ? progress + '%' : @js($w('upload_replace'))"></span>
                            </button>
                            <span style="opacity: .65;">{{ $w('deletes_current') }}</span>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
