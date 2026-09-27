{{--
    Topbar wallet + quota chips.

    Rendered inside `.fi-topbar-end`, which Filament wraps in x-persist. That is why this is a
    Livewire component and why the freshness strategy is wire:poll rather than "it re-renders on
    navigation" — it does not. See PluggedHost\Core\Livewire\AccountChips for the detail.

    A single root element is mandatory for Livewire, so the wrapper is unconditional and the
    chips inside it are what disappear. Utilities are compiled by
    plugins/plugged-host-core/resources/css/plugged-host.css; run `yarn build` after adding one.

    Breakpoints, and why they are where they are. The wallet is visible at *every* width, phones
    included: `.fi-topbar-start` is `hidden lg:flex` upstream, so below `lg` the topbar carries
    nothing but the sidebar toggle, the bell and the avatar, and hiding the chips there left a
    phone with no way to see a balance at all once the dashboard stats widget was removed. Two
    chips fit next to those three controls on a 360px screen with room to spare — measured, not
    assumed: 360 − 32 (px-4) − 36 (toggle) − 36 (bell) − 32 (avatar) − gaps.

    The Economy button appears from `sm`, and that budget is why: it is a word rather than a
    number, so it costs several times a chip's width and there is nothing left to spend at
    360px. Nothing is lost below `sm` — the `$` chip is the same link.

    The quota block appears from `md`. It carries three used/limit pairs and is roughly three
    times the width of a wallet chip, which is more than a phone has left. `md` rather than `lg`
    because the sidebar is still collapsed at that width, so the whole topbar is free.

    Everything is `shrink-0` inside a `min-w-0` wrapper: flex items shrink before their
    neighbours are pushed out, and an over-long balance must never be what moves the user menu
    off the edge of the screen.

    Look: `.ph-chip` in plugged-host.css — theme v2's badge shape on the one card fill, no ring.
    Icons stay only where they are the label (which currency, which quota); the Economy button
    is a word, so it carries no icon.
--}}
<div wire:poll.60s class="fi-ph-chips flex min-w-0 items-center gap-1.5 sm:gap-2">
    @if ($summary)
        @php
            $tokens = $summary['tokens'];
            $credits = $summary['credits'];

            // Split so the store button can set its own `display`. Everything using $chip
            // is inline-flex at all widths; the button is `hidden sm:inline-flex`, and
            // putting `hidden` and `inline-flex` in one class list leaves which one wins
            // to stylesheet order rather than to the code saying it.
            $chipBase = 'ph-chip shrink-0 items-center gap-1.5';
            $chip = 'inline-flex ' . $chipBase;

            $quotaTitle = trans('plugged-host-core::strings.chips.quota_title', [
                'cpu' => $summary['cpu_used'] . '% / ' . $this->limit($summary['cpu_limit'], $suffix, false) . ($summary['cpu_limit'] > 0 ? '%' : ''),
                'memory' => $this->size($summary['memory_used'], $suffix) . ' / ' . $this->limit($summary['memory_limit'], $suffix),
                'disk' => $this->size($summary['disk_used'], $suffix) . ' / ' . $this->limit($summary['disk_limit'], $suffix),
                'servers' => $summary['servers_used'] . ' / ' . $this->limit($summary['servers_limit'], $suffix, false),
            ]);
        @endphp

        {{--
            The way into the Economy page, in words.

            The `$` chip beside it links to the same place, and that is not redundancy: a chip
            whose subject is a balance cannot also announce a destination, and "too many
            buttons are just icons" is the complaint this whole pass answers. Labelled from
            the page itself — see AccountChips::storeLabel() — so it cannot end up calling
            that page something the sidebar does not.
        --}}
        @if ($storeUrl)
            <a
                href="{{ $storeUrl }}"
                wire:navigate
                @class(['hidden sm:inline-flex', $chipBase])
            >
                {{ $storeLabel }}
            </a>
        @endif

        @if ($tokens !== null)
            <span
                @class([$chip])
                title="{{ trans('plugged-host-core::strings.chips.tokens_title') }}"
            >
                <x-filament::icon icon="tabler-coin" class="size-4" />
                {{ number_format($tokens) }}
            </span>
        @endif

        {{--
            A coin for tokens, a `$` for credits. The two chips sit side by side with nothing
            but a number in each, so the icon is the only thing telling them apart and it has to
            say *which currency* rather than what the chip links to. Credits used to carry
            tabler-shopping-bag because the chip is also the way into the store; that made the
            store the subject of a chip whose subject is a balance, and put a bag next to a coin
            as if one were a place and the other a thing.
        --}}
        @if ($credits !== null)
            @if ($storeUrl)
                <a
                    href="{{ $storeUrl }}"
                    wire:navigate
                    @class([$chip])
                    title="{{ trans('plugged-host-core::strings.chips.credits_title') }}"
                >
                    <x-filament::icon icon="tabler-currency-dollar" class="size-4" />
                    {{ number_format($credits) }}
                </a>
            @else
                <span
                    @class([$chip])
                    title="{{ trans('plugged-host-core::strings.chips.credits_title') }}"
                >
                    <x-filament::icon icon="tabler-currency-dollar" class="size-4" />
                    {{ number_format($credits) }}
                </span>
            @endif
        @endif

        <span @class([$chip, 'hidden gap-2.5 md:inline-flex']) title="{{ $quotaTitle }}">
            <span class="inline-flex items-center gap-1.5">
                <x-filament::icon icon="tabler-cpu" class="size-4" />
                {{ $summary['cpu_used'] }}<span class="ph-chip-faint">/{{ $this->limit($summary['cpu_limit'], $suffix, false) }}{{ $summary['cpu_limit'] > 0 ? '%' : '' }}</span>
            </span>

            <span class="inline-flex items-center gap-1.5">
                <x-filament::icon icon="tabler-database" class="size-4" />
                {{ $this->size($summary['memory_used'], $suffix) }}<span class="ph-chip-faint">/{{ $this->limit($summary['memory_limit'], $suffix) }}</span>
            </span>

            <span class="inline-flex items-center gap-1.5">
                <x-filament::icon icon="tabler-device-sd-card" class="size-4" />
                {{ $this->size($summary['disk_used'], $suffix) }}<span class="ph-chip-faint">/{{ $this->limit($summary['disk_limit'], $suffix) }}</span>
            </span>
        </span>
    @endif
</div>
