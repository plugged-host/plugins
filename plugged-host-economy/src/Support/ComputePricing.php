<?php

namespace PluggedHost\Economy\Support;

use PluggedHost\Economy\Contracts\ComputePlans;


/**
 * What CPU, memory and disk cost in the Resource Store. A copy of noprofit.space's
 * App\Services\ComputePricing: noprofit.space sets the numbers (Admin > Settings) and sends them
 * with the credit sync (NoprofitCreditSync::pricing), both stores price with this curve, and
 * noprofit.space refuses a purchase from here that paid less than its own price (the panel then
 * undoes it). Change the two together.
 *
 * There is no ceiling. The price of a step depends on how much the account already has,
 * measured against its limit (the plan's max_*):
 *
 *  - up to the limit, a step costs its share of the way there: half the step price at half the
 *    limit (never less than curve_floor_percent of it), the full step price at the limit;
 *  - from the limit to the soft limit (soft_limit_percent of the limit) the price grows smoothly
 *    to soft_limit_multiplier times the step price;
 *  - past the soft limit it doubles every doubling_percent of the limit.
 *
 * A price is the area under that curve, so four steps bought at once cost what four single
 * steps would, and part of a step (resources taken back) has a price too.
 */
final class ComputePricing
{
    /**
     * resource => [step key, step price key] in the pricing settings.
     */
    private const KEYS = [
        'cpu' => ['cpu_unit_percent', 'cpu_price_credits'],
        'memory' => ['memory_unit_mb', 'memory_price_credits'],
        'disk' => ['storage_unit_mb', 'storage_price_credits'],
    ];

    public const CURVE_DEFAULTS = [
        'curve_floor_percent' => 50,
        'soft_limit_percent' => 125,
        'soft_limit_multiplier' => 4,
        'doubling_percent' => 5,
    ];

    /**
     * What a price this high or higher stands for: more than anyone can pay. Keeps the curve's
     * far end finite.
     */
    public const UNAFFORDABLE = 1_000_000_000;

    /**
     * @param  array<string, int|float|string>  $pricing
     */
    public function __construct(private readonly array $pricing) {}

    /**
     * The current prices (ComputePlans: this panel's settings, or what noprofit.space last sent,
     * fetched again when older than $maxAge seconds), or null while none are known: then
     * nothing is for sale.
     */
    public static function current(int $maxAge = ComputePlans::PRICING_MAX_AGE): ?self
    {
        $pricing = app(ComputePlans::class)->pricing($maxAge);

        return $pricing === null ? null : new self($pricing);
    }

    /**
     * @return list<string>
     */
    public static function resources(): array
    {
        return array_keys(self::KEYS);
    }

    /**
     * The amount sold at once (CPU %, memory and disk MB), 0 when the resource is not for sale.
     */
    public function step(string $resource): int
    {
        return max(0, (int) ($this->pricing[self::KEYS[$resource][0] ?? ''] ?? 0));
    }

    /**
     * Credits for one step at the limit.
     */
    public function stepPrice(string $resource): int
    {
        return max(0, (int) ($this->pricing[self::KEYS[$resource][1] ?? ''] ?? 0));
    }

    public function softLimit(int $limit): int
    {
        return (int) round(max(1, $limit) * $this->softFactor());
    }

    /**
     * Credits for growing a resource from $from by $amount on an account whose limit is $limit.
     */
    public function cost(string $resource, int $from, int $amount, int $limit): int
    {
        $step = $this->step($resource);

        if ($amount <= 0 || $step <= 0) {
            return 0;
        }

        $limit = max(1, $limit);
        $from = max(0, $from);
        $credits = $this->stepPrice($resource) / $step * ($this->area($from + $amount, $limit) - $this->area($from, $limit));

        if (!is_finite($credits) || $credits >= self::UNAFFORDABLE) {
            return self::UNAFFORDABLE;
        }

        // Rounded first so float noise (100.0000000001) does not cost a whole extra credit.
        return (int) ceil(round($credits, 6));
    }

    /**
     * The price of a step at $at, as a multiple of the step price.
     */
    public function multiplier(int $at, int $limit): float
    {
        $limit = max(1, $limit);
        $soft = $limit * $this->softFactor();

        if ($at <= $limit) {
            return max($this->floor(), $at / $limit);
        }

        if ($at <= $soft) {
            return $this->growth() ** (($at - $limit) / ($soft - $limit));
        }

        return min(self::UNAFFORDABLE, $this->peak() * 2 ** (($at - $soft) / ($limit * $this->doubling())));
    }

    /**
     * The curve's area from 0 to $x, in step prices per resource unit.
     */
    private function area(int $x, int $limit): float
    {
        $floor = $this->floor();
        $knee = $floor * $limit;

        // Up to the limit: max(floor, x / limit).
        if ($x <= $knee) {
            return $floor * $x;
        }

        $toLimit = static fn (float $y): float => ($y ** 2 - $knee ** 2) / (2 * $limit) + $floor * $knee;

        if ($x <= $limit) {
            return $toLimit($x);
        }

        // Limit to soft limit: growth ** ((x - limit) / (soft - limit)).
        $soft = $limit * $this->softFactor();
        $band = $soft - $limit;
        $growth = $this->growth();
        $rise = static fn (float $y): float => match (true) {
            $band <= 0 => 0.0,
            $growth <= 1.0 => $y - $limit,
            default => $band / log($growth) * ($growth ** (($y - $limit) / $band) - 1),
        };

        if ($x <= $soft) {
            return $toLimit($limit) + $rise($x);
        }

        // Past the soft limit: doubling every `doubling` of the limit.
        $doubling = $limit * $this->doubling();

        return $toLimit($limit) + $rise($soft) + $this->peak() * $doubling / M_LN2 * (2 ** (($x - $soft) / $doubling) - 1);
    }

    private function floor(): float
    {
        return min(100, max(0, $this->curve('curve_floor_percent'))) / 100;
    }

    private function softFactor(): float
    {
        return max(100, $this->curve('soft_limit_percent')) / 100;
    }

    private function growth(): float
    {
        return max(1.0, $this->curve('soft_limit_multiplier'));
    }

    /**
     * The multiple a step costs at the soft limit, where the doubling starts.
     */
    private function peak(): float
    {
        return $this->softFactor() > 1 ? $this->growth() : 1.0;
    }

    private function doubling(): float
    {
        return max(1, $this->curve('doubling_percent')) / 100;
    }

    private function curve(string $key): float
    {
        return (float) ($this->pricing[$key] ?? self::CURVE_DEFAULTS[$key]);
    }
}
