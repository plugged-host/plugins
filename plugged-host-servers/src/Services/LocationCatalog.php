<?php

namespace PluggedHost\Servers\Services;

use App\Models\Node;
use App\Models\User;
use App\Services\Deployment\FindViableNodesService;
use Illuminate\Support\Collection;
use PluggedHost\Core\Support\Countries;
use PluggedHost\Servers\Support\PremiumAccess;

/**
 * Resolves the deployable "locations" offered during server creation.
 *
 * A location is a node tag that is a valid ISO 3166-1 alpha-2 country code (fr, nl, pl, ...).
 *
 * Paid tiers (WP13): a node also tagged `premium` (UCS_PREMIUM_TAG) is a Premium node, one tagged
 * `plus` (UCS_PLUS_TAG) a Plus node; a node carrying both counts as Premium. Because a country can
 * be offered in several tiers at once, paid locations take a namespaced code `<tier>:<cc>`
 * (`plus:pl`, `premium:de` — a distinct card from the free `<cc>`), while their flag and name still
 * come from the `<cc>` half. Each tier derives ONLY from its own nodes — the tiers never bleed into
 * each other.
 *
 * Plus and Premium are two labels over one entitlement: a single token purchase
 * (UCS_PREMIUM_COST, PremiumAccess) unlocks every paid location in both tiers.
 */
class LocationCatalog
{
    public const FREE = 'free';

    public const PLUS = 'plus';

    public const PREMIUM = 'premium';

    /** Paid tiers, lowest first. */
    public const PAID_TIERS = [self::PLUS, self::PREMIUM];

    private static array $flagCache = [];

    /** @return string The node tag that marks a paid tier's hardware. */
    public function tierTag(string $tier): string
    {
        return strtolower(trim((string) config("plugged-host-servers.user-creatable-servers.{$tier}.tag", $tier)));
    }

    /**
     * The tier a node's hardware belongs to, from its tags. The highest paid tier tagged wins.
     *
     * @param  iterable<mixed>  $tags
     */
    public function nodeTier(iterable $tags): string
    {
        $tags = collect($tags)
            ->filter(fn ($t) => is_string($t) || is_numeric($t))
            ->map(fn ($t) => strtolower(trim((string) $t)));

        foreach (array_reverse(self::PAID_TIERS) as $tier) {
            if ($tags->contains($this->tierTag($tier))) {
                return $tier;
            }
        }

        return self::FREE;
    }

    /**
     * Every location, every tier: free first, then Plus, then Premium. Each entry carries its
     * tier, whether this user may use it (Plus locations need plugged.host Plus, Premium ones
     * PlusPlus, PremiumAccess) and what it requires. Without plugged-host-economy's memberships
     * paid locations are omitted entirely.
     *
     * @return Collection<int, array{code: string, name: string, svg: ?string, node_count: int, latency: ?int, available: bool, tier: string, unlocked: bool, requires: ?string}>
     */
    public function available(int $cpu = 0, int $memory = 0, int $disk = 0, ?User $user = null): Collection
    {
        $deploymentTags = $this->deploymentTags();

        $allNodes = app(FindViableNodesService::class)->handle(0, 0, 0, $deploymentTags);
        $viableNodes = $memory || $disk || $cpu
            ? app(FindViableNodesService::class)->handle($memory, $disk, $cpu, $deploymentTags)
            : $allNodes;

        $latencyByNode = $this->latencyByNode(
            $allNodes->map(fn (Node $n) => (int) $n->getKey())->all(),
        );

        $locations = $this->tier($allNodes, $viableNodes, $latencyByNode, self::FREE);

        if (!PremiumAccess::supported()) {
            return $locations;
        }

        foreach (self::PAID_TIERS as $tier) {
            $unlocked = PremiumAccess::isUnlocked($user, $tier);
            $requires = PremiumAccess::requires($tier);

            $locations = $locations->concat(
                $this->tier($allNodes, $viableNodes, $latencyByNode, $tier)
                    ->map(function (array $loc) use ($tier, $unlocked, $requires) {
                        $loc['code'] = $tier . ':' . $loc['code'];
                        $loc['unlocked'] = $unlocked;
                        $loc['requires'] = $requires;

                        return $loc;
                    }),
            );
        }

        return $locations->values();
    }

    /**
     * @param  Collection<int, Node>  $allNodes
     * @param  Collection<int, Node>  $viableNodes
     * @param  array<int, ?int>  $latencyByNode
     * @return Collection<int, array<string, mixed>>
     */
    private function tier(Collection $allNodes, Collection $viableNodes, array $latencyByNode, string $tier): Collection
    {
        $nodeIds = $this->nodeIdsByCode($allNodes, $tier);
        $viableCodes = $this->nodeIdsByCode($viableNodes, $tier);

        return $nodeIds->map(fn (Collection $ids, string $code) => [
            'code' => $code,
            'name' => Countries::name($code) ?? strtoupper($code),
            'svg' => self::flagSvg($code),
            'node_count' => $ids->count(),
            'latency' => $this->reduceLatency($ids, $latencyByNode),
            'available' => $viableCodes->has($code),
            'tier' => $tier,
            'unlocked' => true,
            'cost' => 0,
        ])->sortBy('name')->values();
    }

    public static function flagSvg(string $code): ?string
    {
        $code = strtolower($code);

        if (array_key_exists($code, self::$flagCache)) {
            return self::$flagCache[$code];
        }

        $path = plugin_path('plugged-host-servers', "resources/flags/4x3/$code.svg");
        $svg = is_file($path) ? (file_get_contents($path) ?: null) : null;

        return self::$flagCache[$code] = $svg;
    }

    /**
     * True when the code is offered to this user. A paid `<tier>:*` code is valid only when the
     * user has made the paid unlock — this is the real gate (a card's disabled flag is
     * presentation), the same reason save() re-resolves location server-side.
     */
    public function isValid(?string $code, ?User $user = null): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        $code = strtolower($code);

        if (!PremiumAccess::isUnlocked($user, $this->resolve($code)[1])) {
            return false;
        }

        return $this->available(user: $user)->contains('code', $code);
    }

    /**
     * The bare country code a (possibly tier-namespaced) location code deploys to, and the tier
     * whose hardware the deployment must target. Used by the deployment gate.
     *
     * @return array{0: string, 1: string}  [country code, tier]
     */
    public function resolve(string $code): array
    {
        $code = strtolower($code);

        foreach (self::PAID_TIERS as $tier) {
            if (str_starts_with($code, $tier . ':')) {
                return [substr($code, strlen($tier) + 1), $tier];
            }
        }

        return [$code, self::FREE];
    }

    /**
     * Node IDs per location code, filtered to one tier: only the nodes whose tags place them in
     * exactly that tier (see nodeTier()).
     *
     * @param  Collection<int, Node>  $nodes
     * @return Collection<string, Collection<int, int>>
     */
    private function nodeIdsByCode(Collection $nodes, string $tier): Collection
    {
        $map = [];

        foreach ($nodes as $node) {
            $tags = array_values(array_filter(array_map(
                fn ($t) => (is_string($t) || is_numeric($t)) ? strtolower(trim((string) $t)) : null,
                (array) ($node->tags ?? []),
            )));

            if ($this->nodeTier($tags) !== $tier) {
                continue; // wrong tier
            }

            foreach ($tags as $tag) {
                if (Countries::isCode($tag)) {
                    $map[$tag][$node->getKey()] = (int) $node->getKey();
                }
            }
        }

        return collect($map)->map(fn (array $ids) => collect(array_values($ids)));
    }

    /**
     * @param  array<int, int>  $nodeIds
     * @return array<int, ?int>
     */
    private function latencyByNode(array $nodeIds): array
    {
        $class = 'PluggedHost\\Status\\Services\\NodeLatency';

        if ($nodeIds === [] || !class_exists($class)) {
            return [];
        }

        try {
            return app($class)->medianForNodes($nodeIds);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  Collection<int, int>  $nodeIds
     * @param  array<int, ?int>  $latencyByNode
     */
    private function reduceLatency(Collection $nodeIds, array $latencyByNode): ?int
    {
        $values = $nodeIds
            ->map(fn (int $id) => $latencyByNode[$id] ?? null)
            ->filter(fn ($v) => is_int($v))
            ->values();

        return $values->isEmpty() ? null : (int) $values->min();
    }

    /** @return string[] */
    private function deploymentTags(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('plugged-host-servers.user-creatable-servers.deployment_tags'))
        )));
    }
}
