<?php

namespace PluggedHost\Abuse\Services;

use App\Models\Server;

/**
 * The full check of one server, through the panel and Wings alone: startup values, every file
 * name and the likely file contents, and the console. Used by the scheduled scan, by the CPU watch
 * when a server pins its CPU, and by the owner sweep after a catch.
 */
final class AbuseScanner
{
    public function __construct(
        private readonly FileInspector $files,
        private readonly StartupInspector $startup,
        private readonly ConsoleInspector $console,
        private readonly AbuseEnforcer $enforcer,
    ) {}

    /**
     * @param  bool  $record  false for a dry run: nothing is written, nothing is suspended
     * @return array{reachable: bool, findings: array<int, Finding>, result: array<string, mixed>|null}
     */
    public function scan(Server $server, bool $enforce, bool $record = true, bool $sweep = true): array
    {
        $server->loadMissing(['egg', 'node', 'user', 'variables']);

        $findings = $this->startup->inspect($server);
        $files = $this->files->inspect($server);

        if ($files !== null) {
            $findings = [...$findings, ...$files, ...($this->console->inspect($server) ?? [])];
        }

        return [
            'reachable' => $files !== null,
            'findings' => $findings,
            'result' => $record ? $this->enforcer->handle($server, $findings, $enforce, sweep: $sweep) : null,
        ];
    }
}
