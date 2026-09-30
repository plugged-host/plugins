{{--
    A currency's recent history as full-width rows: what happened and when on the left, the
    amount on the right. One list for every width, so there is no table to scroll sideways on
    a phone. Expects $heading, $transactions (credit or token ledger rows) and $empty.
--}}
<section class="flex flex-col gap-3">
    <h3 class="ph-eco-heading">{{ $heading }}</h3>

    <div class="ph-eco-list">
        @forelse ($transactions as $tx)
            <div class="ph-eco-tx">
                <div class="flex min-w-0 flex-col">
                    <span class="ph-eco-text">{{ $tx->description }}</span>
                    <span class="ph-eco-small tabular-nums" title="{{ $tx->created_at?->format('Y-m-d H:i') }}">
                        {{ $tx->created_at?->diffForHumans() }}
                        · {{ trans('plugged-host-economy::strings.store.balance_after', ['balance' => number_format((int) $tx->balance_after)]) }}
                    </span>
                </div>

                <span @class(['ph-eco-amount', 'ph-eco-amount-in' => $tx->amount > 0, 'ph-eco-amount-out' => $tx->amount <= 0])>
                    {{ $tx->amount > 0 ? '+' : ($tx->amount < 0 ? '−' : '') }}{{ number_format(abs((int) $tx->amount)) }}
                </span>
            </div>
        @empty
            <p class="ph-eco-muted ph-eco-empty">{{ $empty }}</p>
        @endforelse
    </div>
</section>
