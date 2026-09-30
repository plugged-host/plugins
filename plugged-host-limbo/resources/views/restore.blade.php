{{--
    Restore interstitial for a hibernated server. What happened, what it costs, what the owner
    has — and the one action, "Restore server", which is the page's header action. While the
    move back runs, the card says so instead and the page polls until it has settled.

    One card, the two figures side by side separated by whitespace (no inner box, no divider).
    Styles: `.ph-card`, `.ph-caption`, `.ph-figure` in plugged-host-core's plugged-host.css.
--}}
<x-filament-panels::page>
    @if ($restoring)
        <div class="ph-card flex flex-col gap-1" wire:poll.3s="checkRestore">
            <p class="ph-row-body">
                {{ trans('plugged-host-limbo::strings.restoring') }}
            </p>
        </div>
    @else
        <div class="ph-card flex flex-col gap-6">
            <div class="flex flex-col gap-1">
                <p class="ph-row-body">
                    {{ trans('plugged-host-limbo::strings.body') }}
                </p>
                @if ($enteredAt)
                    <p class="ph-row-body">
                        {{ trans('plugged-host-limbo::strings.entered', ['time' => $enteredAt]) }}
                    </p>
                @endif
            </div>

            <dl class="flex flex-wrap gap-8">
                <div class="flex flex-col gap-1">
                    <dt class="ph-caption">{{ trans('plugged-host-limbo::strings.cost_label') }}</dt>
                    <dd class="ph-figure font-mono">{{ trans('plugged-host-limbo::strings.tokens', ['count' => $cost]) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="ph-caption">{{ trans('plugged-host-limbo::strings.balance_label') }}</dt>
                    <dd class="ph-figure font-mono">{{ trans('plugged-host-limbo::strings.tokens', ['count' => $this->balance()]) }}</dd>
                </div>
            </dl>
        </div>
    @endif
</x-filament-panels::page>
