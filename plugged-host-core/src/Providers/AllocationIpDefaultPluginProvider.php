<?php

namespace PluggedHost\Core\Providers;

use Filament\Forms\Components\Select;
use Illuminate\Support\ServiceProvider;

/**
 * Default the "IP address" picker on every allocation-creation form to 0.0.0.0.
 *
 * The admin allocation forms (Node ▸ Allocations, Server ▸ Allocations, and the Create Server
 * page) all render a `Select::make('allocation_ip')` with no default, so an operator adding a
 * port had to open the dropdown and choose the address by hand every single time. On our nodes
 * the wanted answer is always 0.0.0.0 — the daemon binds every interface and the reachable
 * address is set per-allocation via the alias — so the manual step was pure friction and easy to
 * forget, leaving an allocation bound to a specific IP that then breaks if the node's addressing
 * changes.
 *
 * Done with Filament's `configureUsing` rather than by editing the forms: those live in
 * upstream-owned `app/Filament/...` files (guarded by check-panel-integrity.sh), and this hook is
 * the sanctioned way to reach them. `configureUsing` runs at `Select::make()` time, before the
 * definition's own chained calls, so:
 *
 *  - it only touches selects literally named `allocation_ip`, nothing else in the panel;
 *  - `Node::ipAddresses()` always pushes '0.0.0.0' into the options, so the default is always a
 *    valid choice;
 *  - a form that sets its own default later still wins (its chained ->default() runs after ours),
 *    and on an *edit* form the record's existing value wins over a default, so this only fills the
 *    empty create case — exactly the manual step being removed.
 */
class AllocationIpDefaultPluginProvider extends ServiceProvider
{
    private const DEFAULT_IP = '0.0.0.0';

    public function register(): void
    {
        Select::configureUsing(function (Select $select): void {
            if ($select->getName() === 'allocation_ip') {
                $select->default(self::DEFAULT_IP);
            }
        });
    }
}
