{{--
    The Worlds page (ServerWorlds): one full-width row per world with its actions, rendered as
    Filament actions so their modals, permissions and confirmation come for free. The active
    world shows its generation settings as tiles and a drop zone for uploads; the upload goes
    from the browser straight to the node (a signed wings URL from uploadUrl(), as the file
    manager does), so a large world never passes through the panel, and importWorld() queues
    the rest. Scoped plain CSS on theme-v2's --phv2-* tokens, so it needs no asset build. Every
    row action is secondary (gray pills, icon buttons for download/files/delete); the only
    accent fill on the page is "Upload and replace", once a file is picked.
--}}
@php
    $w = fn (string $key, array $replace = []) => trans('plugged-host-minecraft::strings.worlds.' . $key, $replace);
    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $canUpload = user()?->can(\App\Enums\SubuserPermission::FileCreate, $this->getServer()) && user()?->can(\App\Enums\SubuserPermission::FileDelete, $this->getServer());
    $canFiles = user()?->can(\App\Enums\SubuserPermission::FileRead, $this->getServer());
    $p = $this->properties;
    $when = fn (?string $time): ?string => filled($time) ? \Carbon\Carbon::parse($time)->setTimezone($tz)->isoFormat('lll') : null;
    $tiles = [
        [$w('seed'), filled($p['level-seed'] ?? null) ? $p['level-seed'] : $w('seed_random'), true],
        [$w('type'), \Illuminate\Support\Str::after($p['level-type'] ?? 'minecraft:normal', ':'), false],
        [$w('difficulty'), ucfirst($p['difficulty'] ?? 'easy'), false],
        [$w('gamemode'), ucfirst($p['gamemode'] ?? 'survival') . (($p['hardcore'] ?? 'false') === 'true' ? ' · ' . $w('hardcore') : ''), false],
        [$w('structures'), ($p['generate-structures'] ?? 'true') === 'false' ? $w('off') : $w('on'), false],
    ];
@endphp
<x-filament-panels::page>
    <style>
        .phw-list { display: flex; flex-direction: column; gap: 1rem; }
        .phw-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-areas: "head actions" "stats stats" "upload upload"; align-items: start; gap: 1.5rem; padding: 1.5rem; border-radius: 1rem; background: var(--phv2-raise); }
        .phw-card.phw-other { grid-template-areas: "head actions"; align-items: center; padding: 1rem 1.5rem; }
        .phw-head { grid-area: head; min-width: 0; display: flex; flex-direction: column; gap: .375rem; }
        .phw-title { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .phw-name { font-weight: 600; font-size: 1rem; line-height: 1.5rem; color: var(--phv2-text); word-break: break-all; }
        .phw-meta { font-size: .8125rem; color: var(--phv2-text-muted); }
        .phw-badge { display: inline-flex; align-items: center; font-size: .75rem; line-height: 1rem; font-weight: 600; padding: .25rem .5rem; border-radius: .5rem; background: var(--phv2-raise-2); color: #f3f4f6; }
        .phw-badge-active { background: var(--phv2-accent-tint); color: var(--phv2-accent-bright); }
        .phw-badge-new { background: color-mix(in oklab, #f59e0b 15%, transparent); color: #fbbf24; }
        .phw-stats { grid-area: stats; display: grid; grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr)); gap: 1rem 1.5rem; }
        .phw-stat { display: flex; flex-direction: column; gap: .25rem; min-width: 0; }
        .phw-stat-label { font-size: .6875rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--phv2-text-muted); }
        .phw-stat-value { font-weight: 600; font-size: .875rem; color: var(--phv2-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .phw-mono { font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace; font-size: .8125rem; font-variant-numeric: tabular-nums; }
        .phw-actions { grid-area: actions; display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .5rem; }
        .phw-upload { grid-area: upload; }
        .phw-drop { display: flex; align-items: center; padding: 1rem 1.25rem; border-radius: .75rem; border: 1px dashed var(--phv2-raise-3); transition: border-color .15s ease, background-color .15s ease; cursor: pointer; }
        .phw-drop:hover { background: var(--phv2-raise); }
        .phw-drop.phw-over { border-style: solid; border-color: var(--phv2-accent-bright); background: var(--phv2-accent-soft); }
        .phw-drop.phw-locked { cursor: not-allowed; opacity: .6; }
        .phw-drop-text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .125rem; font-size: .8125rem; color: var(--phv2-text-muted); }
        .phw-drop-text strong { font-size: .875rem; font-weight: 600; color: var(--phv2-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .phw-upload-foot { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-top: .75rem; font-size: .8125rem; color: var(--phv2-text-nav); }
        .phw-bar { height: .375rem; border-radius: 9999px; background: var(--phv2-raise); overflow: hidden; margin-top: .75rem; }
        .phw-bar > i { display: block; height: 100%; border-radius: inherit; background: var(--phv2-accent-bright); transition: width .2s; }
        .phw-row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .phw-muted { color: var(--phv2-text-muted); }
        .phw-error { margin-top: .75rem; font-size: .8125rem; color: #f87171; }
        .phw-help { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem 1.5rem; padding: .5rem .25rem 0; font-size: .8125rem; line-height: 1.5; }
        .phw-help strong { display: block; font-size: .875rem; font-weight: 600; color: var(--phv2-text); margin-bottom: .25rem; }
        .phw-help p { color: var(--phv2-text-muted); }
        @media (max-width: 900px) {
            .phw-card, .phw-card.phw-other { grid-template-columns: minmax(0, 1fr); grid-template-areas: "head" "stats" "actions" "upload"; gap: 1rem; padding: 1rem; }
            .phw-card.phw-other { grid-template-areas: "head" "actions"; }
            .phw-actions { justify-content: flex-start; }
            .phw-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>

    @unless($this->offline)
        <x-filament::callout color="warning" :description="$w('stop_first_long')" />
    @endunless

    <div class="phw-list">
        @foreach($this->worlds as $world)
            <div class="phw-card {{ $world['active'] ? 'phw-active' : 'phw-other' }}" wire:key="phw-{{ md5($world['name']) }}">
                <div class="phw-head">
                    <div class="phw-title">
                        <span class="phw-name">{{ $world['name'] }}</span>
                        @if($world['active'])
                            <span class="phw-badge phw-badge-active">{{ $w('active') }}</span>
                        @endif
                        @unless($world['exists'])
                            <span class="phw-badge phw-badge-new" title="{{ $w('not_generated_hint') }}">{{ $w('not_generated') }}</span>
                        @endunless
                        @if(in_array($world['name'] . '_nether', $world['folders'], true))
                            <span class="phw-badge">{{ $w('nether') }}</span>
                        @endif
                        @if(in_array($world['name'] . '_the_end', $world['folders'], true))
                            <span class="phw-badge">{{ $w('the_end') }}</span>
                        @endif
                    </div>
                    @if(!$world['active'] && $when($world['modified']))
                        <span class="phw-meta">{{ $w('modified') }} {{ $when($world['modified']) }}</span>
                    @endif
                </div>

                @if($world['active'])
                    <div class="phw-stats">
                        @foreach($tiles as [$tileLabel, $tileValue, $mono])
                            <div class="phw-stat">
                                <span class="phw-stat-label">{{ $tileLabel }}</span>
                                <span class="phw-stat-value {{ $mono ? 'phw-mono' : '' }}" title="{{ $tileValue }}">{{ $tileValue }}</span>
                            </div>
                        @endforeach
                        <div class="phw-stat">
                            <span class="phw-stat-label">{{ $w('modified') }}</span>
                            <span class="phw-stat-value">{{ $when($world['modified']) ?? $w('never') }}</span>
                        </div>
                    </div>
                @endif

                {{-- Labelled actions first, then the icon-only quick actions, right-aligned. --}}
                <div class="phw-actions">
                    {{ ($this->optionsAction)(['world' => $world['name']]) }}
                    {{ ($this->generateAction)(['world' => $world['name']]) }}
                    {{ ($this->activateAction)(['world' => $world['name']]) }}
                    {{ ($this->downloadAction)(['world' => $world['name']]) }}
                    @if($canFiles && $world['exists'])
                        <x-filament::icon-button tag="a" :href="$this->filesUrl($world['name'])" :spa-mode="true" icon="tabler-folder" color="gray" :label="$w('files')" :tooltip="$w('files')" />
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
                            <div class="phw-drop-text">
                                <strong x-text="file ? file.name : @js($w('upload'))">{{ $w('upload') }}</strong>
                                <span x-show="!locked">{{ $w('drop_hint', ['limit' => $this->getUploadLimitMb()]) }}</span>
                                <span x-show="locked" x-cloak>{{ $w('stop_first') }}</span>
                            </div>
                            <input type="file" x-ref="picker" accept=".zip,.tar,.tar.gz,.tgz" hidden x-on:change="choose($event.target.files[0])">
                        </div>
                        <div class="phw-bar" x-show="progress !== null" x-cloak><i x-bind:style="'width: ' + progress + '%'"></i></div>
                        <div class="phw-error" x-show="error" x-cloak x-text="error"></div>
                        <div class="phw-upload-foot" x-show="file" x-cloak>
                            <label class="phw-row"><x-filament::input.checkbox x-model="keepCopy" /> {{ $w('keep_copy') }}</label>
                            <div class="phw-row">
                                <span class="phw-muted">{{ $w('deletes_current') }}</span>
                                <x-filament::button size="sm" x-on:click="start()" x-bind:disabled="!file || progress !== null || locked">
                                    <span x-text="progress !== null ? progress + '%' : @js($w('upload_replace'))">{{ $w('upload_replace') }}</span>
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="phw-help">
        @foreach(['help_upload', 'help_safe', 'help_switch'] as $help)
            <div>
                <strong>{{ $w($help . '_title') }}</strong>
                <p>{{ $w($help . '_body') }}</p>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
