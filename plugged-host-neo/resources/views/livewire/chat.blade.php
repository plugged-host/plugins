@php
    $s = fn (string $key, array $replace = []) => trans('plugged-host-neo::strings.' . $key, $replace);
    $icon = fn (string $name, string $class = 'neo-i') => svg($name, $class)->toHtml();

    // One glyph per kind of tool, so a run of steps reads at a glance.
    $toolIcon = fn (?string $tool): string => match (true) {
        in_array($tool, ['console_read', 'send_command'], true) => 'tabler-terminal-2',
        in_array($tool, ['list_files', 'search_files', 'create_directory'], true) => 'tabler-folder',
        $tool === 'read_file' => 'tabler-file-text',
        in_array($tool, ['write_file', 'edit_file', 'rename_file', 'set_startup_variable', 'rename_server'], true) => 'tabler-edit',
        in_array($tool, ['compress_files', 'decompress_file'], true) => 'tabler-file-zip',
        str_starts_with((string) $tool, 'delete_') || $tool === 'remove_content' => 'tabler-trash',
        $tool === 'power' => 'tabler-power',
        str_contains((string) $tool, 'backup') => 'tabler-archive',
        in_array($tool, ['search_content', 'content_versions', 'list_installed_content', 'toggle_content'], true) => 'tabler-puzzle',
        str_starts_with((string) $tool, 'install_') || $tool === 'switch_server_software' => 'tabler-download',
        $tool === 'read_mclogs' => 'tabler-file-search',
        $tool === 'minecraft_info' => 'tabler-world',
        in_array($tool, ['get_startup', 'set_docker_image', 'reinstall_server', 'resize_server'], true) => 'tabler-adjustments',
        in_array($tool, ['account_overview', 'list_create_options', 'unlock_paid_locations'], true) => 'tabler-user-circle',
        str_contains((string) $tool, 'server') => 'tabler-server',
        default => 'tabler-tool',
    };

    $stateIcon = fn (string $status): string => match ($status) {
        'executing' => $icon('tabler-loader-2', 'neo-i-sm neo-spin'),
        'done' => $icon('tabler-check', 'neo-i-sm neo-ok'),
        'error' => $icon('tabler-alert-circle', 'neo-i-sm neo-bad'),
        'denied' => $icon('tabler-ban', 'neo-i-sm neo-bad'),
        'skipped' => $icon('tabler-minus', 'neo-i-sm neo-dim'),
        default => $icon('tabler-clock', 'neo-i-sm neo-dim'),
    };

    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $suggestions = (array) trans('plugged-host-neo::strings.chat.suggestions.' . ($serverName ?? null ? 'server' : 'app'));
@endphp
<div class="neo-root"
     x-data="{
        open: false,
        working: false,
        pendingLog: null,
        flash: null,
        init() {
            try { this.open = localStorage.getItem('plugged-host-neo.open') === '1'; } catch (e) {}
            this.$watch('open', () => this.lock());
            this.lock();
            if (this.open) { this.load(); }
        },
        {{-- The sheet covers the page on phones: keep the page behind it from scrolling. --}}
        lock() {
            document.documentElement.classList.toggle('neo-lock', this.open && window.matchMedia('(max-width: 640px)').matches);
        },
        toggle() {
            this.open = !this.open;
            try { localStorage.setItem('plugged-host-neo.open', this.open ? '1' : '0'); } catch (e) {}
            if (this.open) { this.load(); } else { $wire.close(); }
        },
        load() {
            $wire.open().then((r) => { if (r && r.resume) { this.run(() => $wire.step()); } });
        },
        {{-- 'Ask neo' on a mclo.gs upload (dispatched from OfferNeoForMclogsUpload's notification). --}}
        async ask(detail) {
            const log = detail && typeof detail.log === 'string' ? detail.log : null;
            if (!log) { return; }
            this.pendingLog = log;
            this.open = true;
            try { localStorage.setItem('plugged-host-neo.open', '1'); } catch (e) {}
            await $wire.open();
            const start = async () => {
                const r = await $wire.askAboutLog(log);
                if (!r || r.state !== 'blocked') { this.pendingLog = null; }
                return r;
            };
            if (!this.working) { return this.run(start); }
            {{-- A turn is still looping: it picks the new chat up on its next step. --}}
            const r = await start();
            if (!this.working && r && r.state === 'continue') { this.run(() => $wire.step()); }
        },
        acknowledge() {
            $wire.acknowledge().then(() => { if (this.pendingLog) { this.ask({ log: this.pendingLog }); } });
        },
        submit() {
            if (this.working) { return; }
            this.run(() => $wire.send(), true);
        },
        suggest(text) {
            $wire.draft = text;
            this.submit();
        },
        grow(el) {
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
        },
        {{-- Enter sends on a keyboard; on touch screens it is a new line and the button sends. --}}
        enter(e) {
            if (e.shiftKey || window.matchMedia('(pointer: coarse)').matches) { return; }
            e.preventDefault();
            this.submit();
        },
        async run(first, flashErrors = false) {
            if (this.working) { return; }
            this.working = true;
            this.flash = null;
            try {
                let r = await first();
                if (flashErrors && r && r.message && (r.state === 'error' || r.state === 'blocked')) {
                    this.flash = r.message;
                    setTimeout(() => { this.flash = null; }, 6000);
                }
                if (this.$refs.input) { this.$refs.input.style.height = ''; }
                for (let i = 0; r && (r.state === 'continue' || r.state === 'busy') && i < 80; i++) {
                    if (r.state === 'busy') {
                        await new Promise((done) => setTimeout(done, Math.min(10, r.retry_after || 2) * 1000));
                    }
                    r = await $wire.step();
                }
            } catch (e) {
            } finally {
                this.working = false;
            }
        },
     }"
     x-on:plugged-host-neo-ask.window="ask($event.detail)"
     x-cloak>
    <div class="neo-panel" x-show="open"
         x-transition:enter="neo-t" x-transition:enter-start="neo-t-from" x-transition:enter-end="neo-t-to"
         x-transition:leave="neo-t" x-transition:leave-start="neo-t-to" x-transition:leave-end="neo-t-from">
        @if($conversation)
            <div class="neo-head">
                <div class="neo-mark">{!! $icon('tabler-sparkles') !!}</div>
                <div class="neo-head-text">
                    <div class="neo-title">neo</div>
                    <div class="neo-sub">
                        @if($serverName)
                            {!! $icon('tabler-server', 'neo-i-sm') !!}<span>{{ $serverName }}</span>
                        @else
                            <span>{{ $s('chat.subtitle_app') }}</span>
                        @endif
                    </div>
                </div>
                <button type="button" class="neo-iconbtn" wire:click="newChat" x-bind:disabled="working" title="{{ $s('chat.new_chat') }}" aria-label="{{ $s('chat.new_chat') }}">{!! $icon('tabler-message-plus') !!}</button>
                @if($profileUrl)
                    <a class="neo-iconbtn" href="{{ $profileUrl }}" title="{{ $s('chat.settings') }}" aria-label="{{ $s('chat.settings') }}">{!! $icon('tabler-settings') !!}</a>
                @endif
                <button type="button" class="neo-iconbtn" x-on:click="toggle()" title="{{ $s('chat.close') }}" aria-label="{{ $s('chat.close') }}">{!! $icon('tabler-x') !!}</button>
            </div>

            @if(!$acknowledged)
                <div class="neo-consent">
                    <div class="neo-mark">{!! $icon('tabler-shield-check') !!}</div>
                    <h3>{{ $s('consent.title') }}</h3>
                    <ul>
                        <li>{!! $icon('tabler-bolt') !!}<span>{{ $s('consent.body') }}</span></li>
                        <li>{!! $icon('tabler-hand-stop') !!}<span>{{ $s('consent.approvals') }}</span></li>
                    </ul>
                    <button type="button" class="neo-btn neo-btn-primary" x-on:click="acknowledge()">{!! $icon('tabler-check', 'neo-i-sm') !!}{{ $s('consent.accept') }}</button>
                </div>
            @else
                <div class="neo-body" x-init="new MutationObserver(() => { $el.scrollTop = $el.scrollHeight; }).observe($el, { childList: true, subtree: true }); $el.scrollTop = $el.scrollHeight;">
                    @if($messages->isEmpty())
                        <div class="neo-empty">
                            <div class="neo-mark">{!! $icon('tabler-sparkles') !!}</div>
                            <div>
                                <div class="neo-greet">{{ $serverName ? $s('chat.greeting_server', ['server' => $serverName]) : $s('chat.greeting_app') }}</div>
                                <div>{{ $serverName ? $s('chat.empty_server') : $s('chat.empty_app') }}</div>
                            </div>
                            <div class="neo-chips">
                                @foreach($suggestions as $suggestion)
                                    <button type="button" class="neo-chip" x-bind:disabled="working" x-on:click="suggest(@js($suggestion['prompt'] ?? ''))">
                                        {!! $icon($suggestion['icon'] ?? 'tabler-sparkles', 'neo-i-sm') !!}{{ $suggestion['label'] ?? '' }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @foreach($messages as $message)
                        @if($message->role === 'user')
                            <div class="neo-msg-user" wire:key="neo-{{ $message->id }}">{{ $message->content }}</div>
                        @elseif($message->role === 'assistant')
                            @if(filled($message->content))
                                <div class="neo-msg-ai" wire:key="neo-{{ $message->id }}">{!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                            @endif
                        @elseif($message->role === 'tool')
                            @php
                                $status = (string) $message->status;
                                $label = $message->meta['summary'] ?? $message->tool_name;
                                $auto = ($message->meta['approval'] ?? null) === 'auto';
                            @endphp
                            @if($status === 'pending_approval')
                                <div class="neo-card" wire:key="neo-{{ $message->id }}">
                                    <div class="neo-tile">
                                        {!! $icon($toolIcon($message->tool_name), 'neo-i-sm neo-warn') !!}
                                        <span class="neo-tile-label">{{ $label }}</span>
                                        <span title="{{ $s('chat.status.pending_approval') }}">{!! $icon('tabler-hand-stop', 'neo-i-sm neo-warn') !!}</span>
                                    </div>
                                    @if(filled($message->meta['preview'] ?? null))
                                        <details class="neo-preview">
                                            <summary>{!! $icon('tabler-eye', 'neo-i-sm') !!}{{ $s('chat.show_change') }}</summary>
                                            <pre>{{ $message->meta['preview'] }}</pre>
                                        </details>
                                    @endif
                                    <div class="neo-row">
                                        <button type="button" class="neo-btn neo-btn-primary neo-grow" x-bind:disabled="working" x-on:click="run(() => $wire.approve({{ $message->id }}))">{!! $icon('tabler-check', 'neo-i-sm') !!}{{ $s('chat.approve') }}</button>
                                        <button type="button" class="neo-btn neo-btn-ghost neo-grow" x-bind:disabled="working" x-on:click="run(() => $wire.deny({{ $message->id }}))">{!! $icon('tabler-x', 'neo-i-sm') !!}{{ $s('chat.deny') }}</button>
                                    </div>
                                </div>
                            @else
                                <div class="neo-tile" wire:key="neo-{{ $message->id }}" title="{{ $s('chat.status.' . $status) }}{{ $auto ? ' · ' . $s('chat.auto') : '' }}">
                                    {!! $icon($toolIcon($message->tool_name), 'neo-i-sm') !!}
                                    <span class="neo-tile-label">{{ $label }}</span>
                                    @if($auto)
                                        {!! $icon('tabler-bolt', 'neo-i-sm neo-dim') !!}
                                    @endif
                                    {!! $stateIcon($status) !!}
                                </div>
                            @endif
                        @else
                            @php
                                $kind = $message->meta['kind'] ?? null;
                                $noticeIcon = match ($kind) {
                                    'limited' => 'tabler-hourglass-high',
                                    'unlinked' => 'tabler-link',
                                    'error' => 'tabler-alert-triangle',
                                    default => 'tabler-info-circle',
                                };
                            @endphp
                            <div class="neo-notice" wire:key="neo-{{ $message->id }}">
                                {!! $icon($noticeIcon, 'neo-i-sm') !!}
                                <div class="neo-notice-body">
                                    <span>{{ $message->content }}</span>
                                    @if($kind === 'unlinked')
                                        <a class="neo-btn neo-btn-primary" href="{{ route('plugged-host.sso.link') }}">{!! $icon('tabler-link', 'neo-i-sm') !!}{{ $s('chat.activate') }}</a>
                                    @elseif(in_array($kind, ['error', 'limited'], true) && $loop->last)
                                        <button type="button" class="neo-btn neo-btn-ghost" x-on:click="run(() => $wire.retry())">{!! $icon('tabler-refresh', 'neo-i-sm') !!}{{ $s('chat.retry') }}</button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach

                    @if($pending > 1)
                        <button type="button" class="neo-btn neo-btn-primary" x-bind:disabled="working" x-on:click="run(() => $wire.approveAll())">{!! $icon('tabler-checks', 'neo-i-sm') !!}{{ $s('chat.approve_all', ['count' => $pending]) }}</button>
                    @endif

                    <div class="neo-typing" x-show="working" aria-label="{{ $s('chat.working') }}"><i></i><i></i><i></i></div>
                </div>

                <div class="neo-foot">
                    <div class="neo-flash" x-show="flash" x-cloak>{!! $icon('tabler-alert-circle', 'neo-i-sm') !!}<span x-text="flash"></span></div>

                    @if($usage)
                        <div class="neo-meter">
                            @foreach(['short' => $s('chat.window_short'), 'long' => $s('chat.window_long')] as $window => $windowLabel)
                                @php
                                    $percent = max(0, min(100, (int) ($usage[$window]['percent'] ?? 0)));
                                    $resets = filled($usage[$window]['resets_at'] ?? null)
                                        ? \Carbon\Carbon::parse($usage[$window]['resets_at'])->setTimezone($tz)->isoFormat($window === 'short' ? 'HH:mm' : 'ddd HH:mm')
                                        : $s('profile.not_started');
                                @endphp
                                <div class="neo-meter-item" title="{{ $s('chat.usage_title', ['window' => $windowLabel, 'percent' => $percent, 'time' => $resets]) }}">
                                    <span>{{ $windowLabel }}</span>
                                    <div class="neo-bar {{ $percent >= 95 ? 'neo-bar-full' : ($percent >= 80 ? 'neo-bar-warn' : '') }}"><i style="width: {{ $percent }}%"></i></div>
                                    <span>{{ $percent }}%</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <form class="neo-compose" x-on:submit.prevent="submit()">
                        <textarea class="neo-input" rows="1" x-ref="input" maxlength="{{ (int) config('plugged-host-neo.max_message_chars', 4000) }}"
                                  wire:model="draft"
                                  placeholder="{{ $s('chat.placeholder') }}"
                                  aria-label="{{ $s('chat.placeholder') }}"
                                  x-on:input="grow($el)"
                                  x-on:keydown.enter="enter($event)"></textarea>
                        <button type="submit" class="neo-send" x-show="!working" title="{{ $s('chat.send') }}" aria-label="{{ $s('chat.send') }}">{!! $icon('tabler-arrow-up') !!}</button>
                        <button type="button" class="neo-send neo-send-stop" x-show="working" x-cloak x-on:click="$wire.cancel()" title="{{ $s('chat.stop') }}" aria-label="{{ $s('chat.stop') }}">{!! $icon('tabler-player-stop-filled') !!}</button>
                    </form>
                </div>
            @endif
        @else
            <div class="neo-empty">{!! $icon('tabler-loader-2', 'neo-i neo-spin') !!}</div>
        @endif
    </div>

    <button type="button" class="neo-launcher" x-on:click="toggle()" x-show="!open" title="{{ $s('chat.open') }}" aria-label="{{ $s('chat.open') }}">
        {!! $icon('tabler-sparkles') !!}
        <span class="neo-launcher-label">{{ $s('chat.open') }}</span>
        <span class="neo-launcher-busy" x-show="working" x-cloak></span>
    </button>
</div>
