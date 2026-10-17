<?php

namespace PluggedHost\Servers\Support;

/**
 * Taking an amount off several servers equally: two servers 25% each, five 10% each. A server
 * that would drop below the floor gives what it can and the others share the rest; what no
 * server can give stays untaken.
 */
final class EvenCuts
{
    /**
     * @param  array<int, int>  $values  server id => current value
     * @return array<int, int> server id => amount to take off (servers giving nothing left out)
     */
    public static function split(array $values, int $over, int $floor): array
    {
        $cuts = array_fill_keys(array_keys($values), 0);

        while ($over > 0) {
            $room = [];
            foreach ($values as $id => $value) {
                if (($left = $value - $cuts[$id] - $floor) > 0) {
                    $room[$id] = $left;
                }
            }

            if ($room === []) {
                break;
            }

            $share = (int) ceil($over / count($room));

            foreach ($room as $id => $left) {
                $take = min($share, $left, $over);
                $cuts[$id] += $take;
                $over -= $take;
            }
        }

        return array_filter($cuts);
    }
}
