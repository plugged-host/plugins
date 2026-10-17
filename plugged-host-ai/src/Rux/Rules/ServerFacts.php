<?php

namespace PluggedHost\Ai\Rux\Rules;

use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Core\Support\Countries;
use Throwable;

/**
 * What the AI has to know about plugged.host to judge members' messages fairly, given with every
 * check (watched channels and standing rules). Without it the checks warned "how to get the fr
 * node" and "PLS ENABLE FR or NL" as requests for new locations, and a link to noprofit.space as
 * a scam. The locations come from the nodes' tags (a country code, plus `plus` / `premium`),
 * read again every 10 minutes.
 */
final class ServerFacts
{
    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [
            'Facts about plugged.host:',
            '- Its own sites and links, never a scam or an advert: plugged.host and its subdomains (panel.plugged.host, status.plugged.host, …), noprofit.space and accounts.noprofit.space (plugged.host accounts, credits and sign-in), and its Discord invite discord.gg/plugged-host-1502647500295635085.',
        ];

        $locations = $this->locations();

        if ($locations !== []) {
            $lines[] = '- The server locations it already has: ' . implode('; ', $locations) . '. Asking about one of these, which to pick, how to get or move to one, or for one to be opened, enabled or brought back is NOT asking for a new location.';
        }

        return $lines;
    }

    /**
     * @return list<string> e.g. "France (nodes fr1, fr3)", "Germany (Premium; node de1)"
     */
    private function locations(): array
    {
        return Cache::remember('plugged-host-ai:rux:server-facts:locations', 600, function (): array {
            try {
                $nodes = Node::query()->orderBy('name')->get(['id', 'name', 'tags']);
            } catch (Throwable) {
                return [];
            }

            $plus = strtolower((string) config('plugged-host-servers.user-creatable-servers.plus.tag', 'plus'));
            $premium = strtolower((string) config('plugged-host-servers.user-creatable-servers.premium.tag', 'premium'));
            $countries = [];

            foreach ($nodes as $node) {
                $tags = array_map(static fn ($tag): string => strtolower(trim((string) $tag)), array_filter((array) ($node->tags ?? []), 'is_string'));
                $tier = in_array($premium, $tags, true) ? 'Premium' : (in_array($plus, $tags, true) ? 'Plus' : null);

                foreach ($tags as $tag) {
                    if (Countries::isCode($tag)) {
                        $countries[$tag]['nodes'][] = (string) $node->name;
                        $countries[$tag]['tiers'][$tier ?? 'free'] = true;
                    }
                }
            }

            $lines = [];
            foreach ($countries as $code => $country) {
                $paid = array_values(array_diff(array_keys($country['tiers']), ['free']));
                $lines[] = (Countries::name($code) ?? strtoupper($code)) . ' (' . strtoupper($code) . ($paid !== [] ? ', ' . implode('/', $paid) : '') . '; ' . (count($country['nodes']) > 1 ? 'nodes ' : 'node ') . implode(', ', $country['nodes']) . ')';
            }

            return $lines;
        });
    }
}
