<?php

namespace PluggedHost\Core\Console\Commands;

use App\Models\Plugin;
use Illuminate\Console\Command;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Support\Plugins;

/**
 * Is this install complete and healthy? Lists the plugged.host plugins with what each requires
 * and integrates with, then runs every enabled plugin's checks (see Doctor). Exits non-zero
 * when a check fails, so it can gate a deploy.
 */
class DoctorCommand extends Command
{
    protected $signature = 'plugged-host:doctor
        {plugin? : Only this plugin\'s checks, e.g. plugged-host-storage}';

    protected $description = 'Check that the plugged.host plugins have what they need and are healthy';

    public function handle(): int
    {
        $only = $this->argument('plugin');

        if ($only === null) {
            $this->renderPlugins();
        }

        $results = Doctor::run($only);
        $marks = [Doctor::OK => '<fg=green>ok</>', Doctor::WARN => '<fg=yellow>warn</>', Doctor::FAIL => '<fg=red>FAIL</>'];

        $this->table(
            ['Plugin', 'Check', '', 'Detail'],
            array_map(fn (array $r): array => [$r['plugin'], $r['label'], $marks[$r['status']] ?? $r['status'], $r['message']], $results),
        );

        $failed = count(array_filter($results, fn (array $r): bool => $r['status'] === Doctor::FAIL));
        $warned = count(array_filter($results, fn (array $r): bool => $r['status'] === Doctor::WARN));

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed, {$warned} warning(s).");

            return self::FAILURE;
        }

        $this->info($warned > 0 ? "No failures, {$warned} warning(s)." : 'All checks passed.');

        return self::SUCCESS;
    }

    private function renderPlugins(): void
    {
        $rows = [];

        foreach (Plugin::query()->orderBy('load_order')->get() as $plugin) {
            $deps = Plugins::dependencies($plugin->id);

            if (!str_starts_with($plugin->id, 'plugged-host-') && $deps['requires'] === [] && $deps['optional'] === []) {
                continue;
            }

            $enabled = Plugins::enabled($plugin->id);
            $requires = array_map(fn (string $id): string => Plugins::enabled($id) ? $id : "<fg=red>{$id} (off)</>", $deps['requires']);
            $optional = array_map(fn (string $id): string => Plugins::enabled($id) ? $id : "<fg=gray>{$id} (off)</>", $deps['optional']);

            $rows[] = [
                $plugin->id,
                $enabled ? '<fg=green>enabled</>' : '<fg=gray>' . $plugin->status->value . '</>',
                implode("\n", $requires) ?: '-',
                implode("\n", $optional) ?: '-',
            ];
        }

        $this->table(['Plugin', 'Status', 'Requires', 'Works with'], $rows);
    }
}
