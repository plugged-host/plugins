<?php

namespace PluggedHost\Core\Providers;

use App\Filament\Server\Resources\Allocations\AllocationResource;
use App\Models\Allocation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
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
     * The hostname exactly as upstream showed it, followed by the resolved IP (or numeric IP)
     * — unless the node sets no alias or resolution matches the alias, in which case repeating it
     * would only be noise.
     */
    private static function address(Allocation $allocation): HtmlString|string
    {
        $alias = (string) $allocation->alias;
        $ip = self::resolveIp($allocation);

        if ($ip === null || $alias === $ip) {
            return $alias;
        }

        return new HtmlString(sprintf(
            '%s <span class="font-mono text-xs text-gray-500 dark:text-gray-400">(%s)</span>',
            e($alias),
            e($ip),
        ));
    }

    private static function resolveIp(Allocation $allocation): ?string
    {
        $resolved = self::resolveDns((string) $allocation->alias);
        if ($resolved !== null) {
            return $resolved;
        }

        if (!empty($allocation->node?->fqdn)) {
            $resolved = self::resolveDns((string) $allocation->node->fqdn);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $rawIp = (string) $allocation->ip;
        if (is_ip($rawIp) && !in_array($rawIp, ['0.0.0.0', '::'], true)) {
            return $rawIp;
        }

        return null;
    }

    private static function resolveDns(string $hostname): ?string
    {
        $hostname = trim($hostname);

        if ($hostname === '' || in_array($hostname, ['0.0.0.0', '::'], true)) {
            return null;
        }

        if (is_ip($hostname)) {
            return $hostname;
        }

        try {
            $resolved = Cache::remember("dns_ip:{$hostname}", 300, function () use ($hostname): string {
                $ip = get_ip_from_hostname($hostname);

                if (!is_string($ip) || !is_ip($ip)) {
                    $ip = @gethostbyname($hostname);
                }

                return is_string($ip) && is_ip($ip) && !in_array($ip, ['0.0.0.0', '::'], true)
                    ? $ip
                    : '';
            });

            return $resolved !== '' ? $resolved : null;
        } catch (\Throwable) {
            $ip = get_ip_from_hostname($hostname);
            if (!is_string($ip) || !is_ip($ip)) {
                $ip = @gethostbyname($hostname);
            }

            return is_string($ip) && is_ip($ip) && !in_array($ip, ['0.0.0.0', '::'], true)
                ? $ip
                : null;
        }
    }
}
