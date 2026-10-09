<?php

namespace PluggedHost\Core\Services\Abuse;

/**
 * One thing the abuse checks found on a server (or, for the node agent's host scan, on a node).
 *
 * critical findings are what the Terms forbid outright and suspend the server when the abuse
 * mode is `suspend`; review findings only show up on the admin Abuse page.
 *
 * `rule` is the dedupe key: the same rule on the same server updates one row
 * (AbuseFinding::fingerprint) instead of adding a new one on every scan. The summary is what the
 * owner reads on the suspended page, so it says what was found and where, without jargon.
 */
final class Finding
{
    public const CRITICAL = 'critical';

    public const REVIEW = 'review';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $rule,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $source,
        public readonly string $summary,
        public readonly array $evidence = [],
    ) {}

    public function isCritical(): bool
    {
        return $this->severity === self::CRITICAL;
    }

    /**
     * Collapse hits into one finding per source + category: the strongest severity wins, and its
     * matches lead the summary.
     *
     * @param  array<int, array{category: string, severity: string, label: string, where: string, match?: string}>  $hits
     * @return array<int, self>
     */
    public static function fromHits(string $source, array $hits): array
    {
        $byCategory = [];

        foreach ($hits as $hit) {
            $byCategory[$hit['category']][] = $hit;
        }

        $findings = [];

        foreach ($byCategory as $category => $group) {
            usort($group, fn (array $a, array $b) => ($b['severity'] === self::CRITICAL) <=> ($a['severity'] === self::CRITICAL));

            $severity = $group[0]['severity'];
            $strongest = array_values(array_filter($group, fn (array $hit) => $hit['severity'] === $severity));

            $seen = [];
            $parts = [];

            foreach ($strongest as $hit) {
                $part = "{$hit['label']}: {$hit['where']}";

                if (!isset($seen[$part])) {
                    $seen[$part] = true;
                    $parts[] = $part;
                }
            }

            $summary = Signatures::categoryLabel($category) . ' (' . $parts[0] . ')';

            if (count($parts) > 1) {
                $summary .= ' and ' . (count($parts) - 1) . ' more';
            }

            $findings[] = new self(
                rule: "{$source}.{$category}",
                category: $category,
                severity: $severity,
                source: $source,
                summary: mb_strimwidth($summary, 0, 480, '…'),
                evidence: ['matches' => array_slice(array_map(fn (array $hit) => array_filter([
                    'severity' => $hit['severity'],
                    'label' => $hit['label'],
                    'where' => mb_strimwidth($hit['where'], 0, 300, '…'),
                    'match' => isset($hit['match']) ? mb_strimwidth($hit['match'], 0, 200, '…') : null,
                ]), $group), 0, 15)],
            );
        }

        return $findings;
    }
}
