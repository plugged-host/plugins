<?php

namespace PluggedHost\Ai\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for mclo.gs, where the console's log is uploaded automatically (LogCheck).
 *
 * Uploads a log, and reads a log's info, mclo.gs's automatic analysis (known problems with
 * suggested fixes) and its error entries. The host is fixed and ids are strictly validated, so
 * an id taken from the model or a log can never point a request anywhere else.
 */
final class Mclogs
{
    public const API = 'https://api.mclo.gs/1';

    public const SITE = 'https://mclo.gs/';

    // Codex log levels: 0-3 are emergency..error, 4 is warning.
    public const LEVEL_ERROR = 3;

    public const LEVEL_WARNING = 4;

    // The parsed entries are only fetched for logs up to this size (the console's own upload
    // is ~100 lines; a shared link can be a 10 MB log).
    private const MAX_PARSED_BYTES = 1024 * 1024;

    /**
     * The log id in an id or a mclo.gs link ("https://mclo.gs/AbC12x", "mclo.gs/AbC12x",
     * "https://api.mclo.gs/1/raw/AbC12x"), or null.
     */
    public static function idFrom(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('~^(?:https?://)?(?:api\.)?mclo\.gs/(?:1/(?:raw|log|insights)/)?([A-Za-z0-9]{3,32})/?(?:[?#].*)?$~', $value, $match) === 1) {
            return $match[1];
        }

        return preg_match('/^[A-Za-z0-9]{3,32}$/', $value) === 1 ? $value : null;
    }

    /**
     * Paste a log to mclo.gs. mclo.gs strips IP addresses itself; callers redact secrets first.
     *
     * @return array{id: string, url: string, errors: int}
     *
     * @throws RuntimeException When mclo.gs cannot be reached or refuses the log.
     */
    public function upload(string $content): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->withUserAgent(config('app.name') . ' neo')
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(15)
            ->post(self::API . '/log', ['content' => $content]);

        $data = $response->json();
        $id = is_array($data) && ($data['success'] ?? false) === true && is_string($data['id'] ?? null) ? self::idFrom($data['id']) : null;

        if (!$response->successful() || $id === null) {
            throw new RuntimeException(is_array($data) && is_string($data['error'] ?? null)
                ? "mclo.gs refused the log: {$data['error']}"
                : "mclo.gs answered HTTP {$response->status()}.");
        }

        return ['id' => $id, 'url' => self::SITE . $id, 'errors' => max(0, (int) ($data['errors'] ?? 0))];
    }

    /**
     * A log's info (title, size, lines, error count) and its analysis.
     *
     * @return array{id: string, url: string, title: ?string, size: int, lines: int, errors: int, problems: list<array<string, mixed>>, information: list<array<string, mixed>>}
     *
     * @throws RuntimeException When the log does not exist or mclo.gs cannot be reached.
     */
    public function insights(string $id): array
    {
        $data = $this->get("/log/{$id}?insights=1");
        $insights = (array) ($data['content']['insights'] ?? []);

        return [
            'id' => $id,
            'url' => self::SITE . $id,
            'title' => is_string($insights['title'] ?? null) ? $insights['title'] : null,
            'size' => (int) ($data['size'] ?? 0),
            'lines' => (int) ($data['lines'] ?? 0),
            'errors' => (int) ($data['errors'] ?? 0),
            'problems' => array_values(array_filter((array) ($insights['analysis']['problems'] ?? []), 'is_array')),
            'information' => array_values(array_filter((array) ($insights['analysis']['information'] ?? []), 'is_array')),
        ];
    }

    /**
     * The log's entries at or above a severity (errors by default), oldest first. Empty for
     * logs too large to fetch whole.
     *
     * @return list<array{level: int, lines: list<array{number: int, content: string}>}>
     *
     * @throws RuntimeException
     */
    public function entries(string $id, int $size, int $level = self::LEVEL_ERROR): array
    {
        if ($size > self::MAX_PARSED_BYTES) {
            return [];
        }

        $entries = [];
        foreach ((array) ($this->get("/log/{$id}?parsed=1")['content']['parsed'] ?? []) as $entry) {
            if (!is_array($entry) || (int) ($entry['level'] ?? 99) > $level) {
                continue;
            }

            $lines = [];
            foreach ((array) ($entry['lines'] ?? []) as $line) {
                if (is_array($line) && is_string($line['content'] ?? null)) {
                    $lines[] = ['number' => (int) ($line['number'] ?? 0), 'content' => $line['content']];
                }
            }

            $entries[] = ['level' => (int) $entry['level'], 'lines' => $lines];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->http()->get(self::API . $path);

        if ($response->status() === 404) {
            throw new RuntimeException('That log does not exist on mclo.gs (or it has expired).');
        }

        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['success'] ?? false) !== true) {
            throw new RuntimeException("mclo.gs answered HTTP {$response->status()}.");
        }

        return $data;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent(config('app.name') . ' neo')
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(10);
    }
}
