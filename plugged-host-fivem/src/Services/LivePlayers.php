<?php

namespace PluggedHost\FiveM\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Who is on a FiveM server right now, from FXServer's own HTTP endpoints on the game port:
 * /dynamic.json (hostname, player count, slots) and /players.json (one entry per player).
 *
 * These are the endpoints the server browser reads, so they are public by design and need no
 * credentials. Since 2023 FXServer leaves the identifiers out of players.json unless the owner
 * sets sv_exposePlayerIdentifiersInHttpEndpoint, so they are shown when present and nothing is
 * promised otherwise.
 *
 * The game port is the server's primary allocation. Allocations bind 0.0.0.0 here, so the
 * address to call is the allocation's alias (the node's fqdn), falling back to the node.
 */
class LivePlayers
{
    /**
     * @return array{online: bool, hostname: ?string, clients: int, slots: ?int, players: array<int, array{id: int, name: string, ping: int, identifiers: array<int, string>}>}
     */
    public function fetch(Server $server): array
    {
        $offline = ['online' => false, 'hostname' => null, 'clients' => 0, 'slots' => null, 'players' => []];
        $base = $this->baseUrl($server);

        if ($base === null) {
            return $offline;
        }

        try {
            $responses = Http::pool(fn ($pool) => [
                $pool->as('dynamic')->timeout(4)->connectTimeout(3)->acceptJson()->get("$base/dynamic.json"),
                $pool->as('players')->timeout(4)->connectTimeout(3)->acceptJson()->get("$base/players.json"),
            ]);
        } catch (Throwable) {
            return $offline;
        }

        $dynamic = $responses['dynamic'] ?? null;
        $list = $responses['players'] ?? null;

        if (!$dynamic instanceof \Illuminate\Http\Client\Response || !$dynamic->successful()) {
            return $offline;
        }

        $players = [];

        foreach ($list instanceof \Illuminate\Http\Client\Response && $list->successful() ? (array) $list->json() : [] as $player) {
            if (!is_array($player)) {
                continue;
            }

            $players[] = [
                'id' => (int) ($player['id'] ?? 0),
                // Player names are chosen by players: plain text, rendered escaped, and short.
                'name' => mb_substr(self::stripColors((string) ($player['name'] ?? '')), 0, 64),
                'ping' => (int) ($player['ping'] ?? 0),
                'identifiers' => array_values(array_filter(
                    array_map('strval', (array) ($player['identifiers'] ?? [])),
                    fn (string $identifier) => preg_match('/^[a-z0-9]+:[\w.-]+$/i', $identifier) === 1,
                )),
            ];
        }

        usort($players, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        $slots = $dynamic->json('sv_maxclients');

        return [
            'online' => true,
            'hostname' => self::stripColors((string) $dynamic->json('hostname', '')) ?: null,
            'clients' => (int) $dynamic->json('clients', count($players)),
            'slots' => is_numeric($slots) ? (int) $slots : null,
            'players' => $players,
        ];
    }

    private function baseUrl(Server $server): ?string
    {
        $allocation = $server->allocation;

        if (!$allocation) {
            return null;
        }

        $host = $allocation->ip_alias ?: $allocation->ip;

        if (in_array($host, ['0.0.0.0', '::', '', null], true)) {
            $host = $server->node->fqdn;
        }

        $host = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$host]" : $host;

        return "http://$host:{$allocation->port}";
    }

    /**
     * FiveM's ^0-^9 colour codes, which names and hostnames are full of.
     */
    public static function stripColors(string $text): string
    {
        return trim((string) preg_replace('/\^[0-9]/', '', $text));
    }
}
