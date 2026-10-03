<?php

namespace PluggedHost\Core\Services\Abuse;

use App\Models\Node;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Core\Filament\Admin\Resources\AbuseFindings\AbuseFindingResource;
use PluggedHost\Core\Models\AbuseFinding;
use Throwable;

/**
 * Staff alert for a suspension, a new critical finding or a deletion, posted to the Discord
 * webhook in ABUSE_ALERT_WEBHOOK (unset: no alerts). One finding alert per server or node per 30
 * minutes; suspensions and deletions always post.
 */
final class AbuseAlerts
{
    private const RED = 15548997;

    private const YELLOW = 16705372;

    /**
     * A server AbusePurge deleted.
     *
     * @param  array<int, string>  $reasons
     */
    public function deleted(Server $server, array $reasons): void
    {
        $this->post([
            'title' => mb_strimwidth("Deleted {$server->name} (#{$server->id})", 0, 250, '…'),
            'description' => mb_strimwidth(implode("\n", array_map(fn (string $reason) => '• ' . $reason, array_slice($reasons, 0, 10))) ?: 'Suspended by the abuse checks.', 0, 3900, '…'),
            'color' => self::RED,
            'fields' => [
                ['name' => 'Owner', 'value' => (string) ($server->user?->username ?? '?'), 'inline' => true],
                ['name' => 'Node', 'value' => (string) ($server->node?->name ?? '?'), 'inline' => true],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<int, AbuseFinding>  $rows
     */
    public function send(?Server $server, ?Node $node, array $rows, bool $suspended, string $mode): void
    {
        $url = trim((string) config('plugged-host-core.abuse.alert_webhook', ''));

        if ($url === '' || $rows === []) {
            return;
        }

        try {
            $key = 'ph-abuse:alert:' . ($server ? "server:{$server->id}" : 'node:' . ($node?->id ?? 0));

            if (!$suspended && !Cache::add($key, 1, 1800)) {
                return;
            }

            $title = match (true) {
                $suspended => "Suspended {$server?->name} (#{$server?->id})",
                $server !== null && $mode === 'report' => "Would suspend {$server->name} (#{$server->id}), report-only mode",
                $server !== null => "Flagged {$server->name} (#{$server->id})",
                default => 'Suspicious process on node ' . ($node?->name ?? '?'),
            };

            $lines = array_map(fn (AbuseFinding $row) => '• ' . $row->summary, array_slice($rows, 0, 10));

            $fields = array_values(array_filter([
                $server ? ['name' => 'Owner', 'value' => (string) ($server->user?->username ?? '?'), 'inline' => true] : null,
                ['name' => 'Node', 'value' => (string) ($server?->node?->name ?? $node?->name ?? '?'), 'inline' => true],
                $server ? ['name' => 'Egg', 'value' => (string) ($server->egg?->name ?? '?'), 'inline' => true] : null,
            ]));

            $this->post([
                'title' => mb_strimwidth($title, 0, 250, '…'),
                'url' => AbuseFindingResource::getUrl(panel: 'admin'),
                'description' => mb_strimwidth(implode("\n", $lines), 0, 3900, '…'),
                'color' => $suspended ? self::RED : self::YELLOW,
                'fields' => $fields,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            // An alert is never worth failing a scan over.
        }
    }

    /** @param  array<string, mixed>  $embed */
    private function post(array $embed): void
    {
        $url = trim((string) config('plugged-host-core.abuse.alert_webhook', ''));

        if ($url === '') {
            return;
        }

        try {
            Http::timeout(5)->post($url, [
                'username' => 'plugged.host abuse',
                'allowed_mentions' => ['parse' => []],
                'embeds' => [$embed],
            ]);
        } catch (Throwable) {
            // An alert is never worth failing a scan or a purge over.
        }
    }
}
