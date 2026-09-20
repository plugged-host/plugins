<?php

namespace PluggedHost\Core\Providers;

use App\Filament\Server\Resources\Allocations\AllocationResource;
use App\Models\Allocation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;

/**
 * Shows the numeric IP beside the hostname in the server Network tab's Address column.
 *
 * Upstream renders that column as `$allocation->alias`, which is `ip_alias` whenever the node
 * sets one — currently true for all but two of our allocations — so the address a client
 * actually dials was nowhere in the client panel. Anyone who needed it (DNS not propagated yet,
 * a launcher that rejects hostnames, a firewall rule keyed on an address) had to ask support.
 * The admin panel never had this problem: its allocations table already lists `ip` and `alias`
 * as separate columns.
 *
 * Implementation notes, roughly in order of how easily each could be broken later:
 *
 *  - `AllocationResource` uses the `CanModifyTable` trait, so this is a sanctioned extension
 *    point and no upstream file is patched — unlike the OAuth registration guard, nothing here
 *    needs re-applying after a panel upgrade.
 *  - The column is mutated in place rather than by re-declaring `columns()`. Filament's
 *    `Resource::configureTable()` calls `static::table($table)` and discards the return value,
 *    so only mutations to the passed Table survive anyway; reaching for the one column by name
 *    also leaves upstream's other columns, record actions and toolbar action untouched, which is
 *    what keeps this working across an upgrade that reorders or extends them.
 *  - The instanceof check is the intended failure mode, not defensive noise: if an upgrade
 *    renames that column or swaps its type, the annotation quietly disappears instead of 500ing
 *    the Network tab for every user.
 *  - Returning an `Htmlable` is what lets the markup through — `TextColumn::formatState()` emits
 *    Htmlable state verbatim, skipping both escaping and the HtmlSanitizer — so both
 *    interpolated values are escaped here.
 *  - The utility classes are compiled by the plugin's own stylesheet
 *    (`resources/css/plugged-host.css`, which `@source`s `../../src`, so plugin PHP is scanned).
 *    All four already appear in plugin views, hence they are in the current `public/build` and
 *    this change needs no `yarn build`.
 */
class NetworkAddressPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        AllocationResource::modifyTable(function (Table $table): Table {
            $column = $table->getColumn('ip');

            if ($column instanceof TextColumn) {
                $column->formatStateUsing(fn (Allocation $allocation) => self::address($allocation));
            }

            return $table;
        });
    }

    /**
     * The hostname exactly as upstream showed it, followed by the numeric IP — unless the node
     * sets no alias, in which case the column already is the numeric IP and repeating it would
     * only be noise. Compared by value rather than through `has_alias` so an alias that merely
     * duplicates the IP is treated the same way.
     */
    private static function address(Allocation $allocation): HtmlString|string
    {
        if ($allocation->alias === $allocation->ip) {
            return $allocation->ip;
        }

        return new HtmlString(sprintf(
            '%s <span class="font-mono text-xs text-gray-500 dark:text-gray-400">(%s)</span>',
            e($allocation->alias),
            e($allocation->ip),
        ));
    }
}
