<?php

namespace PluggedHost\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Keep the server console alive when a browser tab stops draining Livewire requests.
 *
 * The console's websocket delivers a `stats` frame once per second for as long as the
 * server runs, and `resources/views/filament/components/server-console.blade.php` turns
 * each one into `$wire.dispatchSelf('store-stats', …)` — a real round trip to
 * `App\Filament\Server\Widgets\ServerConsole::storeStats()`, which is what feeds the CPU,
 * memory and network charts underneath the terminal.
 *
 * A websocket is not throttled when its tab goes to the background; `fetch` is. So a
 * console left open in a background tab keeps minting `store-stats` dispatches while the
 * request that should carry them stalls, and Livewire pools everything that arrives in
 * the meantime into the next payload. When the tab is focused again that payload flushes
 * as one request carrying hundreds of calls, `HandleComponents::callMethods()` compares
 * the count against `livewire.payload.max_calls` — 50, from Livewire's own package
 * config — and throws `TooManyCallsException`.
 *
 * That is the "console is blank" report. On 2026-09-01 every 500 this panel served whose
 * referer was a console page was this one exception (~40 across the day, up to 593 calls
 * in a single payload), and a 500 on `/livewire/update` is not a page the user can read:
 * Livewire drops the response over the console, so the terminal disappears behind it.
 *
 * The pool is redundant, not merely large. `storeStats()` keys each sample by
 * `now()->getTimestamp()`, so every call in one request writes the same cache slot and
 * only the last one survives — several hundred calls do the work of one. This provider
 * therefore collapses each component's run of `store-stats` dispatches down to the most
 * recent before Livewire ever counts them, which is lossless by construction.
 *
 * `Livewire::listen('request', …)` is the supported seam:
 * `Mechanisms\HandleRequests\HandleRequests::handleUpdate()` triggers it and feeds the
 * payload back through whatever the listener returns, before any component is hydrated
 * and before the call count is checked. Only each component's `calls` array is touched —
 * the snapshot, and therefore its checksum, is passed through untouched.
 */
class ConsolePayloadPluginProvider extends ServiceProvider
{
    /**
     * Dispatches that are safe to collapse: ones whose handler is idempotent within a
     * single request, so the newest call carries everything the older ones did.
     *
     * Deliberately not `console-status`. It also arrives from the websocket, but each
     * state transition rebuilds the page's power buttons, and it is rare enough that it
     * never contributes to a flood.
     *
     * @var string[]
     */
    protected const COALESCE = ['store-stats'];

    /**
     * A backstop for whatever else a stalled tab manages to queue. Livewire's default of
     * 50 is an anti-abuse number rather than a statement about this panel: one console
     * legitimately batches stats, power-status, sidebar- and topbar-refresh dispatches
     * together. The coalescing below is what removes the unbounded source, so this only
     * has to absorb the bounded remainder — the route still requires auth and CSRF.
     */
    protected const MAX_CALLS = 200;

    public function register(): void
    {
        $configured = config('livewire.payload.max_calls');

        // Only ever raises, and leaves an explicit null (Livewire's "no limit") alone.
        if (is_numeric($configured) && (int) $configured < self::MAX_CALLS) {
            config(['livewire.payload.max_calls' => self::MAX_CALLS]);
        }

        Livewire::listen('request', fn () => fn ($payload) => is_array($payload)
            ? $this->coalesce($payload)
            : $payload);
    }

    /**
     * @param  array<int, mixed>  $payload
     * @return array<int, mixed>
     */
    protected function coalesce(array $payload): array
    {
        foreach ($payload as $index => $component) {
            $calls = is_array($component) ? ($component['calls'] ?? null) : null;

            if (!is_array($calls) || count($calls) < 2) {
                continue;
            }

            $seen = [];
            $kept = [];

            // Backwards, so the call that survives is the newest one.
            foreach (array_reverse($calls, true) as $key => $call) {
                $event = $this->coalescableEvent($call);

                if ($event !== null) {
                    if (isset($seen[$event])) {
                        continue;
                    }

                    $seen[$event] = true;
                }

                $kept[$key] = $call;
            }

            if (count($kept) === count($calls)) {
                continue;
            }

            ksort($kept);

            $payload[$index]['calls'] = array_values($kept);
        }

        return $payload;
    }

    /**
     * The event name behind a client-side dispatch, when that event is one this provider
     * is allowed to collapse. `$wire.dispatch()` travels as a `__dispatch` method call
     * whose first parameter is the event name — see `SupportEvents::call()`.
     */
    protected function coalescableEvent(mixed $call): ?string
    {
        if (!is_array($call) || ($call['method'] ?? null) !== '__dispatch') {
            return null;
        }

        $event = $call['params'][0] ?? null;

        return is_string($event) && in_array($event, self::COALESCE, true) ? $event : null;
    }
}
