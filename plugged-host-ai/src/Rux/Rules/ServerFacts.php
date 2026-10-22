<?php

namespace PluggedHost\Ai\Rux\Rules;

use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxGuild;
use PluggedHost\Core\Support\Countries;
use PluggedHost\Core\Support\Plugins;
use Throwable;

/**
 * What the AI has to know about this host to judge members' messages fairly, given with every
 * check (watched channels and standing rules). Without it the checks warned "how to get the fr
 * node" and "PLS ENABLE FR or NL" as requests for new locations, and a link to noprofit.space as
 * a scam. The locations come from the nodes' tags (a country code, plus `plus` / `premium`),
 * read again every 10 minutes.
 *
 * The facts are the server's (RuxGuild): its host's name, its own sites (the server's trusted
 * domains, each with its subdomains: RUX_TRUSTED_DOMAINS everywhere plus a server's own), the
 * panel's and the status page's host names, the Discord invite, and noprofit.space with
 * plugged-host-noprofit; the locations only where the server is this panel's own community.
 */
final class ServerFacts
{
    /**
     * @param  RuxGuild|null  $guild  null: the primary server
     * @return list<string>
     */
    public function lines(?RuxGuild $guild = null): array
    {
        $guild ??= RuxConfig::primary();
        $app = $guild?->hostName() ?? (string) config('app.name');
        $lines = ['Facts about ' . $app . ':'];
        $sites = $this->ownSites($app, $guild);

        if ($sites !== []) {
            $lines[] = '- Its own sites and links, never a scam or an advert: ' . self::listing($sites) . '.';
        }

        $locations = ($guild->locations ?? true) ? $this->locations() : [];

        if ($locations !== []) {
            $lines[] = '- The server locations it already has: ' . implode('; ', $locations) . '. Asking about one of these, which to pick, how to get or move to one, or for one to be opened, enabled or brought back is NOT asking for a new location.';
        }

        return $lines;
    }

    /**
     * "example.com and its subdomains (panel.example.com, status.example.com, …)", other host names
     * as they are, noprofit.space with plugged-host-noprofit, and the Discord invite.
     *
     * @return list<string>
     */
    private function ownSites(string $app, ?RuxGuild $guild): array
    {
        $domains = [];
        foreach ($guild->trustedDomains ?? (array) RuxConfig::get('trusted_domains', []) as $domain) {
            $domain = self::host((string) $domain);
            if ($domain !== '' && !in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        $hosts = [];
        foreach ([(string) config('app.url', ''), (string) config('plugged-host-core.status_url', '')] as $url) {
            $host = self::host($url);
            if ($host !== '' && !in_array($host, $hosts, true) && !in_array($host, $domains, true)) {
                $hosts[] = $host;
            }
        }

        $sites = [];
        foreach ($domains as $domain) {
            $under = array_values(array_filter($hosts, static fn (string $host): bool => str_ends_with($host, '.' . $domain)));
            $hosts = array_values(array_diff($hosts, $under));
            $sites[] = $domain . ' and its subdomains' . ($under !== [] ? ' (' . implode(', ', $under) . ', …)' : '');
        }
        array_push($sites, ...$hosts);

        if (Plugins::enabled('plugged-host-noprofit') && !in_array('noprofit.space', $domains, true)) {
            $sites[] = 'noprofit.space and accounts.noprofit.space (' . $app . ' accounts, credits and sign-in)';
        }

        $invite = (string) preg_replace('#^https?://#i', '', rtrim(trim((string) config('plugged-host-discord.invite_url', '')), '/'));
        if ($invite !== '') {
            $sites[] = 'its Discord invite ' . $invite;
        }

        return $sites;
    }

    /** "a", "a and b", "a, b, and c". */
    private static function listing(array $items): string
    {
        return count($items) > 2
            ? implode(', ', array_slice($items, 0, -1)) . ', and ' . end($items)
            : implode(' and ', $items);
    }

    /** The host name of a URL or bare domain, lowercase: "https://Panel.example.com/x" → "panel.example.com". */
    private static function host(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^\*\.#', '', (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value));

        return rtrim((string) strtok($value, '/:?#'), '.');
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
