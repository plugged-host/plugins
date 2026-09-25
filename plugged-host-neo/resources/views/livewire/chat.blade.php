@php
    $s = fn (string $key, array $replace = []) => trans('plugged-host-neo::strings.' . $key, $replace);
@endphp
<div class="neo-root"
     x-data="{
        open: false,
        working: false,
        init() {
            try { this.open = localStorage.getItem('plugged-host-neo.open') === '1'; } catch (e) {}
            if (this.open) { this.load(); }
        },
        toggle() {
            this.open = !this.open;
            try { localStorage.setItem('plugged-host-neo.open', this.open ? '1' : '0'); } catch (e) {}
            if (this.open) { this.load(); } else { $wire.close(); }
        },
        load() {
            $wire.open().then((r) => { if (r && r.resume) { this.run(() => $wire.step()); } });
        },
        async run(first) {
            if (this.working) { return; }
            this.working = true;
            try {
                let r = await first();
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
     x-cloak>
    <div class="neo-panel" x-show="open" x-transition.opacity>
        @if($conversation)
            <div class="neo-head">
                <div>
                    <div class="neo-title">neo</div>
                    <div class="neo-sub">{{ $serverName ? $s('chat.subtitle_server', ['server' => $serverName]) : $s('chat.subtitle_app') }}</div>
                </div>
                <div class="neo-head-actions">
                    <button type="button" class="neo-icon-btn" wire:click="newChat" x-bind:disabled="working">{{ $s('chat.new_chat') }}</button>
                    @if($profileUrl)
                        <a class="neo-icon-btn" href="{{ $profileUrl }}">{{ $s('chat.settings') }}</a>
                    @endif
                    <button type="button" class="neo-icon-btn" x-on:click="toggle()" aria-label="{{ $s('chat.close') }}">✕</button>
                </div>
            </div>

            @if(!$acknowledged)
                <div class="neo-consent">
                    <h3>{{ $s('consent.title') }}</h3>
                    <p>{{ $s('consent.body') }}</p>
                    <p>{{ $s('consent.approvals') }}</p>
                    <div><button type="button" class="neo-btn neo-btn-primary" wire:click="acknowledge">{{ $s('consent.accept') }}</button></div>
                </div>
            @else
                @if($usage)
                    <div class="neo-usage">
                        {{ $s('chat.usage', ['short' => $usage['short']['percent'] ?? 0, 'long' => $usage['long']['percent'] ?? 0]) }}
                        @if(!empty($usage['short']['resets_at']))
                            · {{ $s('chat.resets', ['time' => \Carbon\Carbon::parse($usage['short']['resets_at'])->setTimezone(auth()->user()->timezone ?: config('app.timezone'))->format('H:i')]) }}
                        @endif
                    </div>
                @endif

                <div class="neo-body" x-init="new MutationObserver(() => { $el.scrollTop = $el.scrollHeight; }).observe($el, { childList: true, subtree: true }); $el.scrollTop = $el.scrollHeight;">
                    @if($messages->isEmpty())
                        <div class="neo-empty">{{ $serverName ? $s('chat.empty_server') : $s('chat.empty_app') }}</div>
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
                                $badge = match ($status) {
                                    'pending_approval' => 'neo-badge-warn',
                                    'error', 'denied' => 'neo-badge-bad',
                                    'done' => 'neo-badge-ok',
                                    default => '',
                                };
                            @endphp
                            <div class="neo-tool" wire:key="neo-{{ $message->id }}">
                                <div class="neo-tool-row">
                                    <span class="neo-tool-name">{{ $message->meta['summary'] ?? $message->tool_name }}</span>
                                    <span class="neo-badge {{ $badge }}">
                                        {{ $s('chat.status.' . $status) }}@if(($message->meta['approval'] ?? null) === 'auto') · {{ $s('chat.auto') }}@endif
                                    </span>
                                </div>
                                @if(in_array($status, ['done', 'error'], true) && filled($message->meta['display'] ?? null) && ($message->meta['display'] ?? '') !== ($message->meta['summary'] ?? ''))
                                    <div class="neo-tool-detail">{{ \Illuminate\Support\Str::limit($message->meta['display'], 200) }}</div>
                                @endif
                                @if($status === 'pending_approval')
                                    @if(filled($message->meta['preview'] ?? null))
                                        <div class="neo-preview">{{ $message->meta['preview'] }}</div>
                                    @endif
                                    <div class="neo-approval">
                                        <button type="button" class="neo-btn neo-btn-primary" x-bind:disabled="working" x-on:click="run(() => $wire.approve({{ $message->id }}))">{{ $s('chat.approve') }}</button>
                                        <button type="button" class="neo-btn neo-btn-ghost" x-bind:disabled="working" x-on:click="run(() => $wire.deny({{ $message->id }}))">{{ $s('chat.deny') }}</button>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="neo-notice" wire:key="neo-{{ $message->id }}">
                                {{ $message->content }}
                                @if(($message->meta['kind'] ?? null) === 'unlinked')
                                    <div style="margin-top: .4rem;"><a class="neo-btn neo-btn-primary" href="{{ route('plugged-host.sso.link') }}">{{ $s('chat.activate') }}</a></div>
                                @elseif(in_array($message->meta['kind'] ?? null, ['error', 'limited'], true) && $loop->last)
                                    <div style="margin-top: .4rem;"><button type="button" class="neo-btn neo-btn-ghost" x-on:click="run(() => $wire.retry())">{{ $s('chat.retry') }}</button></div>
                                @endif
                            </div>
                        @endif
                    @endforeach

                    @if($pending > 1)
                        <div><button type="button" class="neo-btn neo-btn-primary" x-bind:disabled="working" x-on:click="run(() => $wire.approveAll())">{{ $s('chat.approve_all', ['count' => $pending]) }}</button></div>
                    @endif
                </div>

                <div class="neo-working" x-show="working">
                    {{ $s('chat.working') }}
                    <button type="button" class="neo-icon-btn" x-on:click="$wire.cancel()">{{ $s('chat.stop') }}</button>
                </div>

                <form class="neo-foot" x-on:submit.prevent="run(() => $wire.send())">
                    <textarea class="neo-input" rows="1" maxlength="{{ (int) config('plugged-host-neo.max_message_chars', 4000) }}"
                              wire:model="draft"
                              placeholder="{{ $s('chat.placeholder') }}"
                              x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); run(() => $wire.send()); }"></textarea>
                    <button type="submit" class="neo-btn neo-btn-primary" x-bind:disabled="working">{{ $s('chat.send') }}</button>
                </form>
            @endif
        @endif
    </div>

    <button type="button" class="neo-launcher" x-on:click="toggle()" x-show="!open">
        <span class="neo-dot"></span>
        <span>{{ $s('chat.open') }}</span>
    </button>
</div>
