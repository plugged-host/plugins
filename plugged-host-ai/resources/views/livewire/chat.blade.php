@php
    $s = fn (string $key, array $replace = []) => trans('plugged-host-ai::strings.' . $key, $replace);
    $icon = fn (string $name, string $class = 'neo-i') => svg($name, $class)->toHtml();

    $stateIcon = fn (string $status): string => match ($status) {
        'executing' => $icon('tabler-loader-2', 'neo-i-sm neo-spin'),
        'done' => $icon('tabler-check', 'neo-i-sm neo-ok'),
        'error' => $icon('tabler-alert-circle', 'neo-i-sm neo-bad'),
        'denied' => $icon('tabler-ban', 'neo-i-sm neo-bad'),
        'skipped' => $icon('tabler-minus', 'neo-i-sm neo-dim'),
        default => $icon('tabler-clock', 'neo-i-sm neo-dim'),
    };

    $tz = auth()->user()?->timezone ?: config('app.timezone');
    $resetsAt = fn (?array $window, string $format): string => filled($window['resets_at'] ?? null)
        ? \Carbon\Carbon::parse($window['resets_at'])->setTimezone($tz)->isoFormat($format)
        : $s('profile.not_started');
    $barClass = fn (int $percent): string => $percent >= 95 ? 'neo-bar-full' : ($percent >= 80 ? 'neo-bar-warn' : '');
    $suggestions = (array) trans('plugged-host-ai::strings.chat.suggestions.' . (($serverName ?? null) ? 'server' : 'app'));
    $enabled ??= true;
    $history ??= null;
@endphp
{{-- No x-cloak on the root or the panel, only on the launcher: Livewire's morph puts x-cloak back
     on every update until Alpine takes it off again a moment later, and an ancestor of the message
     list that is hidden for that moment costs the list its scroll position (neo jumped to the top
     of the chat). The panel is hidden until Alpine starts by display: none, which x-show owns. --}}
<div class="neo-root"
     x-data="{
        open: false,
        working: false,
        pendingLog: null,
        flash: null,
        attention: null,
        pinned: true,
        init() {
            try { this.open = localStorage.getItem('plugged-host-neo.open') === '1'; } catch (e) {}
            try { this.applySize(JSON.parse(localStorage.getItem('plugged-host-neo.size') || 'null')); } catch (e) {}
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
            if (this.open) { this.attention = null; this.load(); } else { $wire.close(); }
        },
        load() {
            $wire.open().then((r) => { if (r && r.resume) { this.run(() => $wire.step()); } });
        },
        {{-- The size lives on <html>, outside Livewire's DOM, so re-renders never reset it. --}}
        applySize(size) {
            const root = document.documentElement.style;
            if (size && size.w && size.h) {
                root.setProperty('--neo-w', size.w + 'px');
                root.setProperty('--neo-h', size.h + 'px');
            } else {
                root.removeProperty('--neo-w');
                root.removeProperty('--neo-h');
            }
        },
        startResize(e) {
            const rect = this.$refs.panel.getBoundingClientRect();
            const start = { x: e.clientX, y: e.clientY, w: rect.width, h: rect.height };
            let size = null;
            const move = (ev) => {
                size = {
                    w: Math.round(Math.min(window.innerWidth - 32, Math.max(300, start.w + start.x - ev.clientX))),
                    h: Math.round(Math.min(window.innerHeight - 96, Math.max(320, start.h + start.y - ev.clientY))),
                };
                this.applySize(size);
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                document.documentElement.classList.remove('neo-resizing');
                try { if (size) { localStorage.setItem('plugged-host-neo.size', JSON.stringify(size)); } } catch (err) {}
            };
            document.documentElement.classList.add('neo-resizing');
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        resetSize() {
            this.applySize(null);
            try { localStorage.removeItem('plugged-host-neo.size'); } catch (e) {}
        },
        {{-- 'Ask neo to fix it' on the automatic log check above the console (log-check.blade.php). --}}
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
        turnOn() {
            $wire.setPreference('enabled', true).then(() => this.load());
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
        {{-- Keeps the chat on its newest message while it is read from the bottom; scrolled up, it
             stays put. Whether to follow is decided when the rows change, before the browser's own
             scroll events for that change, and the scroll waits for the next frame's settled layout. --}}
        follow(el) {
            this.pinned = true;
            let queued = false;
            const stick = () => {
                if (!this.pinned || queued) { return; }
                queued = true;
                requestAnimationFrame(() => { queued = false; el.scrollTop = el.scrollHeight; });
            };
            el.addEventListener('scroll', () => { this.pinned = el.scrollHeight - el.scrollTop - el.clientHeight < 48; }, { passive: true });
            {{-- style too: the typing dots coming up (x-show) change no rows. --}}
            new MutationObserver(stick).observe(el, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
            stick();
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
            this.pinned = true;
            this.flash = null;
            let r = null;
            try {
                r = await first();
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
                {{-- Minimized while it worked: say on the launcher how it ended. --}}
                if (!this.open && r) {
                    this.attention = r.state === 'awaiting_approval' ? 'approve' : 'done';
                }
            }
        },
     }"
     x-on:plugged-host-ai-ask.window="ask($event.detail)">
    <div class="neo-panel" x-ref="panel" x-show="open" style="display: none"
         x-transition:enter="neo-t" x-transition:enter-start="neo-t-from" x-transition:enter-end="neo-t-to"
         x-transition:leave="neo-t" x-transition:leave-start="neo-t-to" x-transition:leave-end="neo-t-from">
        <div class="neo-grip" x-on:pointerdown.prevent="startResize($event)" x-on:dblclick="resetSize()" title="{{ $s('chat.resize') }}"></div>

        @if(!$enabled)
            <div class="neo-head">
                <div class="neo-head-text">
                    <div class="neo-title">neo</div>
                    <div class="neo-sub">{{ $s('chat.off_title') }}</div>
                </div>
                <button type="button" class="neo-iconbtn" x-on:click="toggle()" title="{{ $s('chat.close') }}" aria-label="{{ $s('chat.close') }}">{!! $icon('tabler-chevron-down') !!}</button>
            </div>
            <div class="neo-empty">
                <div>
                    <div class="neo-greet">{{ $s('chat.off_title') }}</div>
                    <div>{{ $s('chat.off_body') }}</div>
                </div>
                <button type="button" class="neo-btn neo-btn-primary" x-on:click="turnOn()">{{ $s('chat.turn_on') }}</button>
            </div>
        @elseif($history !== null)
            <div class="neo-head">
                <button type="button" class="neo-iconbtn" wire:click="showChat" title="{{ $s('chat.back') }}" aria-label="{{ $s('chat.back') }}">{!! $icon('tabler-arrow-left') !!}</button>
                <div class="neo-head-text">
                    <div class="neo-title">neo</div>
                    <div class="neo-sub">{{ $s('chat.history') }}</div>
                </div>
                <button type="button" class="neo-iconbtn" wire:click="newChat" x-bind:disabled="working" title="{{ $s('chat.new_chat') }}" aria-label="{{ $s('chat.new_chat') }}">{!! $icon('tabler-message-plus') !!}</button>
                <button type="button" class="neo-iconbtn" x-on:click="toggle()" title="{{ $s('chat.minimize') }}" aria-label="{{ $s('chat.minimize') }}">{!! $icon('tabler-chevron-down') !!}</button>
            </div>
            <div class="neo-settings">
                @forelse($history as $item)
                    <div class="neo-hist {{ $item->id === $this->conversationId ? 'neo-hist-current' : '' }}" wire:key="neo-hist-{{ $item->id }}">
                        <button type="button" class="neo-hist-open" x-bind:disabled="working"
                                x-on:click="$wire.openConversation({{ $item->id }}).then((r) => { if (r && r.resume) { run(() => $wire.step()); } })">
                            <span class="neo-hist-text">
                                <span class="neo-hist-title">{{ $item->title ?: $s('chat.untitled') }}</span>
                                <span class="neo-hist-meta">{{ optional($item->last_activity_at)->locale(app()->getLocale())->diffForHumans() }} · {{ trans_choice('plugged-host-ai::strings.chat.messages_count', $item->user_messages_count, ['count' => $item->user_messages_count]) }}</span>
                            </span>
                        </button>
                        <span x-data="{ sure: false }">
                            <button type="button" class="neo-iconbtn" x-bind:class="sure ? 'neo-bad' : ''" x-bind:disabled="working"
                                    x-bind:title="sure ? @js($s('chat.confirm_delete')) : @js($s('chat.delete_chat'))" aria-label="{{ $s('chat.delete_chat') }}"
                                    x-on:click="if (sure) { $wire.deleteConversation({{ $item->id }}); } else { sure = true; setTimeout(() => sure = false, 4000); }">
                                <span x-show="!sure">{!! $icon('tabler-trash') !!}</span>
                                <span x-show="sure" x-cloak>{!! $icon('tabler-trash-x') !!}</span>
                            </button>
                        </span>
                    </div>
                @empty
                    <div class="neo-empty">
                        <div>{{ $s('chat.history_empty') }}</div>
                    </div>
                @endforelse
            </div>
        @elseif($settings)
            <div class="neo-head">
                <button type="button" class="neo-iconbtn" wire:click="showChat" title="{{ $s('chat.back') }}" aria-label="{{ $s('chat.back') }}">{!! $icon('tabler-arrow-left') !!}</button>
                <div class="neo-head-text">
                    <div class="neo-title">neo</div>
                    <div class="neo-sub">{{ $s('chat.settings') }}</div>
                </div>
                <button type="button" class="neo-iconbtn" x-on:click="toggle()" title="{{ $s('chat.minimize') }}" aria-label="{{ $s('chat.minimize') }}">{!! $icon('tabler-chevron-down') !!}</button>
            </div>
            <div class="neo-settings">
                <div class="neo-set-card">
                    @foreach(['enabled', 'auto_approve'] as $key)
                        <div class="neo-set-row">
                            <div class="neo-set-text">
                                <div class="neo-set-title">{{ $s('profile.' . $key) }}</div>
                                <div class="neo-set-hint">{{ $s('profile.' . $key . '_hint') }}</div>
                            </div>
                            <button type="button" class="neo-switch" role="switch" aria-checked="{{ $settings[$key] ? 'true' : 'false' }}" aria-label="{{ $s('profile.' . $key) }}"
                                    wire:click="setPreference('{{ $key }}', {{ $settings[$key] ? 'false' : 'true' }})"></button>
                        </div>
                    @endforeach
                </div>

                <div class="neo-set-card">
                    <div class="neo-set-row">
                        <div class="neo-set-text">
                            <div class="neo-set-title">{{ $s('profile.usage') }}</div>
                            <div class="neo-set-hint">{{ $s('profile.usage_hint') }}</div>
                        </div>
                    </div>
                    @if(!$settings['linked'])
                        <div class="neo-set-hint">{{ $s('profile.unlinked') }}</div>
                        <a class="neo-btn neo-btn-primary" href="{{ route('plugged-host.sso.link') }}">{{ $s('profile.link') }}</a>
                    @elseif(!is_array($settings['limits']))
                        <div class="neo-set-hint">{{ $s('profile.unavailable') }}</div>
                    @else
                        @foreach(['short' => [$s('profile.short_window'), 'HH:mm'], 'long' => [$s('profile.long_window'), 'ddd HH:mm']] as $window => [$windowLabel, $format])
                            @php $percent = max(0, min(100, (int) ($settings['limits'][$window]['percent'] ?? 0))); @endphp
                            <div class="neo-usage">
                                <div class="neo-usage-head">
                                    <span>{{ $windowLabel }}</span>
                                    <span>{{ $percent }}% · {{ $s('profile.resets', ['time' => $resetsAt($settings['limits'][$window] ?? null, $format)]) }}</span>
                                </div>
                                <div class="neo-bar neo-bar-lg {{ $barClass($percent) }}"><i style="width: {{ $percent }}%"></i></div>
                            </div>
                        @endforeach
                    @endif
                </div>

                <div class="neo-set-card">
                    <div class="neo-set-row">
                        <div class="neo-set-text">
                            <div class="neo-set-title">{{ $s('profile.history') }}</div>
                            <div class="neo-set-hint">{{ $s('profile.history_hint', ['days' => $settings['retention_days']]) }}</div>
                        </div>
                    </div>
                    <div x-data="{ sure: false }">
                        <button type="button" class="neo-btn"
                                x-on:click="if (sure) { sure = false; $wire.deleteHistory(); } else { sure = true; setTimeout(() => sure = false, 4000); }">
                            <span x-text="sure ? @js($s('chat.confirm_delete')) : @js($s('profile.delete_history'))"></span>
                        </button>
                    </div>
                </div>

                <p class="neo-small">{{ $s('profile.privacy') }}</p>
            </div>
        @elseif($conversation)
            <div class="neo-head">
                <div class="neo-head-text">
                    <div class="neo-title">neo</div>
                    <div class="neo-sub">{{ $serverName ?: $s('chat.subtitle_app') }}</div>
                </div>
                <button type="button" class="neo-iconbtn" wire:click="showHistory" title="{{ $s('chat.history') }}" aria-label="{{ $s('chat.history') }}">{!! $icon('tabler-history') !!}</button>
                <button type="button" class="neo-iconbtn" wire:click="newChat" x-bind:disabled="working" title="{{ $s('chat.new_chat') }}" aria-label="{{ $s('chat.new_chat') }}">{!! $icon('tabler-message-plus') !!}</button>
                <button type="button" class="neo-iconbtn" wire:click="showSettings" title="{{ $s('chat.settings') }}" aria-label="{{ $s('chat.settings') }}">{!! $icon('tabler-settings') !!}</button>
                <button type="button" class="neo-iconbtn" x-on:click="toggle()" title="{{ $s('chat.minimize') }}" aria-label="{{ $s('chat.minimize') }}">{!! $icon('tabler-chevron-down') !!}</button>
            </div>

            @if(!$acknowledged)
                <div class="neo-consent">
                    <h3>{{ $s('consent.title') }}</h3>
                    <ul>
                        <li>{{ $s('consent.body') }}</li>
                        <li>{{ $s('consent.approvals') }}</li>
                    </ul>
                    <button type="button" class="neo-btn neo-btn-primary" x-on:click="acknowledge()">{{ $s('consent.accept') }}</button>
                </div>
            @else
                <div class="neo-body" x-init="follow($el)">
                    @if($messages->isEmpty())
                        <div class="neo-empty">
                            <div>
                                <div class="neo-greet">{{ $serverName ? $s('chat.greeting_server', ['server' => $serverName]) : $s('chat.greeting_app') }}</div>
                                <div>{{ $serverName ? $s('chat.empty_server') : $s('chat.empty_app') }}</div>
                            </div>
                            <div class="neo-chips">
                                @foreach($suggestions as $suggestion)
                                    <button type="button" class="neo-chip" x-bind:disabled="working" x-on:click="suggest(@js($suggestion['prompt'] ?? ''))">
                                        {{ $suggestion['label'] ?? '' }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @foreach($messages as $message)
                        {{-- One keyed box per row (display: contents, so the rows stay the list's flex items). Keys
                             on the rows themselves sat inside each @if's morph markers, which pair up by position:
                             once the 60-row window slid, every update rebuilt all of them. --}}
                        <div class="neo-item" wire:key="neo-{{ $message->id }}">
                            @if($message->role === 'user')
                                <div class="neo-msg-user">{{ $message->content }}</div>
                            @elseif($message->role === 'assistant')
                                @if(filled($message->content))
                                    <div class="neo-msg-ai">{!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'escape', 'allow_unsafe_links' => false], [new \PluggedHost\Ai\Support\Markdown\ImagesAsTextExtension()]) !!}</div>
                                @endif
                            @elseif($message->role === 'tool')
                                @php
                                    $status = (string) $message->status;
                                    $label = $message->meta['summary'] ?? $message->tool_name;
                                    $auto = ($message->meta['approval'] ?? null) === 'auto';
                                @endphp
                                @if($status === 'pending_approval')
                                    <div class="neo-card">
                                        <div class="neo-tile" title="{{ $s('chat.status.pending_approval') }}">
                                            {!! $icon('tabler-hand-stop', 'neo-i-sm neo-warn') !!}
                                            <span class="neo-tile-label">{{ $label }}</span>
                                        </div>
                                        @if(filled($message->meta['preview'] ?? null))
                                            <details class="neo-preview">
                                                <summary>{{ $s('chat.show_change') }}</summary>
                                                <pre>{{ $message->meta['preview'] }}</pre>
                                            </details>
                                        @endif
                                        <div class="neo-row">
                                            <button type="button" class="neo-btn neo-grow" x-bind:disabled="working" x-on:click="run(() => $wire.approve({{ $message->id }}))">{{ $s('chat.approve') }}</button>
                                            <button type="button" class="neo-btn neo-btn-ghost neo-grow" x-bind:disabled="working" x-on:click="run(() => $wire.deny({{ $message->id }}))">{{ $s('chat.deny') }}</button>
                                        </div>
                                    </div>
                                @else
                                    <div class="neo-tile" title="{{ $s('chat.status.' . $status) }}{{ $auto ? ' · ' . $s('chat.auto') : '' }}">
                                        {!! $stateIcon($status) !!}
                                        <span class="neo-tile-label">{{ $label }}</span>
                                        @if($auto)
                                            {!! $icon('tabler-bolt', 'neo-i-sm neo-dim') !!}
                                        @endif
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
                                <div class="neo-notice">
                                    {!! $icon($noticeIcon, 'neo-i-sm') !!}
                                    <div class="neo-notice-body">
                                        <span>{{ $message->content }}</span>
                                        @if($kind === 'unlinked')
                                            <a class="neo-btn" href="{{ route('plugged-host.sso.link') }}">{{ $s('chat.activate') }}</a>
                                        @elseif(in_array($kind, ['error', 'limited'], true) && $loop->last)
                                            <button type="button" class="neo-btn" x-on:click="run(() => $wire.retry())">{{ $s('chat.retry') }}</button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if($pending > 1)
                        <button type="button" class="neo-btn" x-bind:disabled="working" x-on:click="run(() => $wire.approveAll())">{{ $s('chat.approve_all', ['count' => $pending]) }}</button>
                    @endif

                    <div class="neo-typing" x-show="working" aria-label="{{ $s('chat.working') }}"><i></i><i></i><i></i></div>
                </div>

                <div class="neo-foot">
                    <div class="neo-flash" x-show="flash" x-cloak>{!! $icon('tabler-alert-circle', 'neo-i-sm') !!}<span x-text="flash"></span></div>

                    @if($usage)
                        <div class="neo-meter">
                            @foreach(['short' => [$s('chat.window_short'), 'HH:mm'], 'long' => [$s('chat.window_long'), 'ddd HH:mm']] as $window => [$windowLabel, $format])
                                @php $percent = max(0, min(100, (int) ($usage[$window]['percent'] ?? 0))); @endphp
                                <div class="neo-meter-item" title="{{ $s('chat.usage_title', ['window' => $windowLabel, 'percent' => $percent, 'time' => $resetsAt($usage[$window] ?? null, $format)]) }}">
                                    <span>{{ $windowLabel }}</span>
                                    <div class="neo-bar {{ $barClass($percent) }}"><i style="width: {{ $percent }}%"></i></div>
                                    <span>{{ $percent }}%</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <form class="neo-compose" x-on:submit.prevent="submit()">
                        <textarea class="neo-input" rows="1" x-ref="input" maxlength="{{ (int) config('plugged-host-ai.max_message_chars', 4000) }}"
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

    <button type="button" class="neo-launcher {{ $enabled ? '' : 'neo-launcher-off' }}" x-on:click="toggle()" x-show="!open"
            title="{{ $enabled ? $s('chat.open') : $s('chat.off_title') }}" aria-label="{{ $enabled ? $s('chat.open') : $s('chat.off_title') }}" x-cloak>
        <span x-show="!working">{!! $icon('tabler-sparkles') !!}</span>
        <span x-show="working" x-cloak>{!! $icon('tabler-loader-2', 'neo-i neo-spin') !!}</span>
        @if($enabled)
            <span class="neo-launcher-label"
                  x-text="working ? @js($s('chat.status_working')) : (attention === 'approve' ? @js($s('chat.status_approve')) : (attention === 'done' ? @js($s('chat.status_done')) : @js($s('chat.open'))))">{{ $s('chat.open') }}</span>
            <span class="neo-launcher-dot" x-show="attention && !working" x-bind:class="attention === 'approve' ? 'neo-dot-approve' : 'neo-dot-done'" x-cloak></span>
        @endif
    </button>
</div>
