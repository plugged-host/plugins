<?php

namespace PluggedHost\Core\Nodes;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Keeps the panel from crashing wings (v1.0.0-rc.1) when it deletes a server. A Guzzle
 * middleware on every outgoing HTTP call; it only acts on wings' DELETE /api/servers/{uuid} and
 * POST /api/servers/{uuid}/power.
 *
 * Wings' deleteServer closes the server's power lock (CleanupForDestroy) before it removes the
 * container, and it never forgets that lock:
 *  - Deleting a running server: removing the container is seen as a crash (state events are
 *    handled asynchronously, so "stopping" then "offline" reads as running → offline), the
 *    crash handler starts the server, Acquire() sends on the closed lock and the whole wings
 *    process panics. So a running server is killed first, and the delete waits until wings
 *    reports it offline.
 *  - A delete that fails after that point (Docker timing out) leaves the server loaded with a
 *    closed lock: the next power action panics wings, and so does a second delete (the lock is
 *    closed twice). So after a failed delete, both are refused for that server on that node
 *    until wings no longer holds the broken copy: it answers 404 for it, or reports it
 *    unsuspended (deleteServer suspends it first; a restarted wings loads it fresh).
 *
 * pl2 lost wings twice this way on 2026-10-21, each time for ~45 minutes while it booted again.
 */
final class WingsDeleteGuard
{
    private const KEY = 'plugged-host:wings-delete-failed:';

    private const PATH = '#^/api/servers/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(/power)?$#';

    /** How long a failed delete blocks the server, at most. */
    private const BLOCK_HOURS = 6;

    /** How long a delete waits for the killed server to go offline. */
    private const OFFLINE_WAIT_SECONDS = 20;

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            if (!$request->hasHeader('Authorization') || !preg_match(self::PATH, $request->getUri()->getPath(), $match)) {
                return $handler($request, $options);
            }

            $method = strtoupper($request->getMethod());
            $isPower = ($match[2] ?? '') !== '';

            if (!($method === 'DELETE' && !$isPower) && !($method === 'POST' && $isPower)) {
                return $handler($request, $options);
            }

            $uuid = $match[1];
            $key = self::KEY . sha1($request->getUri()->getHost() . ':' . $request->getUri()->getPort()) . ':' . $uuid;

            try {
                if (Cache::has($key)) {
                    if (!rescue(fn () => $this->stillBroken($handler, $request, $options, $uuid), true, false)) {
                        Cache::forget($key);
                    } else {
                        Log::warning("plugged-host-core: refused a wings {$method} for server {$uuid}: an earlier delete failed half-way and wings would crash on it");

                        return Create::rejectionFor(new ConnectException(
                            'This server is half-deleted on its node after a failed delete; the node\'s daemon must restart before it can be started, stopped or deleted.',
                            $request,
                        ));
                    }
                }

                if ($method === 'DELETE') {
                    $this->stopFirst($handler, $request, $options, $uuid);
                }
            } catch (Throwable $e) {
                // The guard never stands in the way of the request itself.
                Log::warning("plugged-host-core: wings delete guard for server {$uuid} skipped: " . $e->getMessage());
            }

            if ($method !== 'DELETE') {
                return $handler($request, $options);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($key, $uuid): ResponseInterface {
                    $status = $response->getStatusCode();

                    if ($status >= 400 && $status !== 404) {
                        $this->remember($key, $uuid, "HTTP {$status}");
                    } elseif ($status < 300) {
                        rescue(fn () => Cache::forget($key), null, false);
                    }

                    return $response;
                },
                function ($reason) use ($key, $uuid): PromiseInterface {
                    $this->remember($key, $uuid, $reason instanceof Throwable ? $reason->getMessage() : 'no response');

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /** Kill a server that is up and wait for wings to report it offline, so its removal is no crash. */
    private function stopFirst(callable $handler, RequestInterface $request, array $options, string $uuid): void
    {
        $state = $this->details($handler, $request, $options, $uuid)['state'] ?? null;

        if (!in_array($state, ['running', 'starting', 'stopping'], true)) {
            return;
        }

        $kill = $request->withMethod('POST')
            ->withUri($request->getUri()->withPath("/api/servers/{$uuid}/power"))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Utils::streamFor('{"action":"kill"}'));
        $handler($kill, $this->options($options))->wait();

        $deadline = microtime(true) + self::OFFLINE_WAIT_SECONDS;

        do {
            usleep(500_000);
            $state = $this->details($handler, $request, $options, $uuid)['state'] ?? null;
        } while ($state !== null && $state !== 'offline' && microtime(true) < $deadline);

        Log::info("plugged-host-core: killed server {$uuid} before deleting it on wings (state now " . ($state ?? 'unknown') . ')');
    }

    /** Whether wings still holds the copy a failed delete left behind. Unknown counts as yes. */
    private function stillBroken(callable $handler, RequestInterface $request, array $options, string $uuid): bool
    {
        $details = $this->details($handler, $request, $options, $uuid);

        if ($details === null) {
            return false; // 404: wings does not hold it any more
        }

        return ($details['is_suspended'] ?? true) !== false;
    }

    /**
     * Wings' GET /api/servers/{uuid}. Null when wings answers 404; throws when it does not answer.
     *
     * @return null|array<string, mixed>
     */
    private function details(callable $handler, RequestInterface $request, array $options, string $uuid): ?array
    {
        $get = $request->withMethod('GET')
            ->withUri($request->getUri()->withPath("/api/servers/{$uuid}"))
            ->withoutHeader('Content-Type')
            ->withBody(Utils::streamFor(''));

        /** @var ResponseInterface $response */
        $response = $handler($get, $this->options($options))->wait();
        $status = $response->getStatusCode();

        if ($status === 404) {
            return null;
        }

        if ($status >= 300) {
            throw new \RuntimeException("wings answered HTTP {$status}");
        }

        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /** @return array<string, mixed> */
    private function options(array $options): array
    {
        unset($options['on_stats']);

        return array_merge($options, ['http_errors' => false, 'timeout' => 5, 'connect_timeout' => 3]);
    }

    private function remember(string $key, string $uuid, string $why): void
    {
        Log::warning("plugged-host-core: wings failed to delete server {$uuid} ({$why}); power actions and deletes for it are held until wings drops it");
        rescue(fn () => Cache::put($key, true, now()->addHours(self::BLOCK_HOURS)), null, false);
    }
}
