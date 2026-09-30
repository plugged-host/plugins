<?php

namespace PluggedHost\Neo\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;
use PluggedHost\Neo\Support\SecretRedactor;
use Throwable;

/**
 * Recent console output, straight from Wings' GET /api/servers/{uuid}/logs (nothing upstream
 * wraps it). Wings keeps a bounded buffer, so asking for more than ~100 lines is pointless.
 */
final class ConsoleHistory
{
    public const MAX_LINES = 100;

    /**
     * @return array{ok: bool, lines: list<string>, error: ?string}
     */
    public function tail(Server $server, int $lines): array
    {
        $lines = max(1, min(self::MAX_LINES, $lines));

        try {
            $response = Http::daemon($server->node)
                ->timeout(5)
                ->get("/api/servers/{$server->uuid}/logs", ['size' => $lines]);
        } catch (Throwable) {
            return ['ok' => false, 'lines' => [], 'error' => 'The node could not be reached.'];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'lines' => [], 'error' => "The node answered HTTP {$response->status()}."];
        }

        $data = $response->json('data');
        if (!is_array($data)) {
            return ['ok' => false, 'lines' => [], 'error' => 'The node returned no console data.'];
        }

        $clean = [];
        foreach ($data as $line) {
            if (!is_string($line)) {
                continue;
            }

            $line = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a]*\a|\r/', '', $line);
            $clean[] = SecretRedactor::redact(rtrim($line));
        }

        return ['ok' => true, 'lines' => array_slice($clean, -$lines), 'error' => null];
    }
}
