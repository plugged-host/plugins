@php
    use Illuminate\Support\Number;

    $locale = app()->getLocale();
@endphp

{{--
    The sidebar's usage card and balances (ThemeV2Balance). Each part links to where it is
    topped up — usage and credits to the Resource Store, tokens to the Tokens page — with
    wire:navigate so the click does not throw away the current page's state. A part whose store
    is unavailable renders as a plain element: it must not look clickable if nothing happens.

    Every colour is a --phv2-* property (theme.css), never a Tailwind `dark:` variant.
--}}
<div class="phv2-balance" wire:poll.60s>
    @if ($usage !== [])
        <{{ $resourceStoreUrl ? 'a' : 'div' }}
            @if ($resourceStoreUrl)
                href="{{ $resourceStoreUrl }}"
                wire:navigate
                title="{{ trans('plugged-host-theme-v2::strings.usage.open_store') }}"
            @endif
            @class(['phv2-resources-card', 'phv2-linked' => $resourceStoreUrl])
        >
            @foreach ($usage as $row)
                <div class="phv2-resource-row">
                    <div class="phv2-resource-info">
                        <span class="phv2-resource-name">{{ $row['label'] }}</span>
                        <span class="phv2-resource-metrics">
                            <span @class(['phv2-resource-value', 'phv2-resource-full' => $row['full']])>{{ $row['used'] }}</span>
                            <span class="phv2-resource-limit">/ {{ $row['limit'] }}</span>
                        </span>
                    </div>
                    <div class="phv2-resource-track">
                        <div @class(['phv2-resource-bar', 'phv2-resource-bar-full' => $row['full']]) style="width: {{ $row['percent'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </{{ $resourceStoreUrl ? 'a' : 'div' }}>
    @endif

    @if ($tokens !== null || $credits !== null)
        <div class="phv2-balance-pills">
            @foreach ([
                ['value' => $tokens, 'label' => 'tokens', 'url' => $tokenStoreUrl],
                ['value' => $credits, 'label' => 'credits', 'url' => $resourceStoreUrl],
            ] as $balance)
                @continue($balance['value'] === null)

                <{{ $balance['url'] ? 'a' : 'span' }}
                    @if ($balance['url'])
                        href="{{ $balance['url'] }}"
                        wire:navigate
                        title="{{ trans('plugged-host-theme-v2::strings.balance.open_' . $balance['label']) }}"
                    @endif
                    @class(['phv2-balance-item', 'phv2-linked' => $balance['url']])
                >
                    <span class="phv2-balance-value">{{ Number::format($balance['value'], locale: $locale) }}</span>
                    <span class="phv2-balance-label">{{ trans('plugged-host-theme-v2::strings.balance.' . $balance['label']) }}</span>
                </{{ $balance['url'] ? 'a' : 'span' }}>
            @endforeach
        </div>
    @endif
</div>
