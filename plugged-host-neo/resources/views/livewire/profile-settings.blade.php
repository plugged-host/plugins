@php
    $s = fn (string $key, array $replace = []) => trans('plugged-host-neo::strings.profile.' . $key, $replace);
    $window = function (?array $w) use ($s, $timezone) {
        if (!is_array($w)) {
            return null;
        }

        $reset = !empty($w['resets_at'])
            ? $s('resets', ['time' => \Carbon\Carbon::parse($w['resets_at'])->setTimezone($timezone)->isoFormat('ddd HH:mm')])
            : $s('not_started');

        return ['percent' => (int) ($w['percent'] ?? 0), 'label' => $reset];
    };
    $limits = $usage['limits'] ?? null;
@endphp
<div style="display: flex; flex-direction: column; gap: 1.5rem;">
    <x-filament::section :heading="$s('section')" :description="$s('section_hint')">
        {{ $this->form }}
        <p style="margin-top: 1rem; font-size: .8125rem; opacity: .7;">{{ $s('privacy') }}</p>
    </x-filament::section>

    <x-filament::section :heading="$s('usage')" :description="$s('usage_hint')">
        @if(!$linked || ($usage['linked'] ?? true) === false)
            <p style="font-size: .875rem;">{{ $s('unlinked') }}</p>
            <div style="margin-top: .75rem;">
                <x-filament::button tag="a" :href="route('plugged-host.sso.link')" size="sm">{{ $s('link') }}</x-filament::button>
            </div>
        @elseif(!is_array($limits))
            <p style="font-size: .875rem; opacity: .7;">{{ $s('unavailable') }}</p>
        @else
            @foreach(['short' => $s('short_window'), 'long' => $s('long_window')] as $kind => $label)
                @php($w = $window($limits[$kind] ?? null))
                @if($w)
                    <div style="margin-bottom: .9rem;">
                        <div style="display: flex; justify-content: space-between; font-size: .8125rem; margin-bottom: .3rem;">
                            <span>{{ $label }}</span>
                            <span style="opacity: .7;">{{ $w['percent'] }}% · {{ $w['label'] }}</span>
                        </div>
                        <div style="height: .45rem; border-radius: 9999px; background: var(--phv2-raise-2, rgba(255,255,255,.08)); overflow: hidden;">
                            <div style="height: 100%; width: {{ $w['percent'] }}%; background: {{ $w['percent'] >= 90 ? '#f43f5e' : 'var(--phv2-accent, #2563eb)' }};"></div>
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </x-filament::section>

    <x-filament::section :heading="$s('history')" :description="$s('history_hint', ['days' => $retentionDays])">
        {{ $this->deleteHistoryAction }}
    </x-filament::section>

    <x-filament-actions::modals />
</div>
