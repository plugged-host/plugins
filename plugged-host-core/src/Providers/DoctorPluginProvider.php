<?php

namespace PluggedHost\Core\Providers;

use App\Models\Mount;
use App\Models\Node;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Doctor\QueueHeartbeat;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Core\Support\SystemServers;

/**
 * `php artisan plugged-host:doctor`: the heartbeats it reads and core's own checks. Other
 * plugins add theirs with Doctor::check() from their providers.
 */
class DoctorPluginProvider extends ServiceProvider
{
    public const SCHEDULER_KEY = 'plugged-host:doctor:scheduler-tick';

    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(fn () => Cache::put(self::SCHEDULER_KEY, time(), now()->addDay()))
                ->everyMinute()
                ->name('plugged-host:doctor-scheduler-tick');

            $schedule->job(new QueueHeartbeat())
                ->everyFiveMinutes()
                ->name('plugged-host:doctor-queue-heartbeat');
        });
    }

    public function boot(): void
    {
        Doctor::check('plugged-host-core', 'Plugin requirements', function (): array {
            $unmet = Plugins::unmet();

            if ($unmet === []) {
                return Doctor::ok('every enabled plugin has what it requires');
            }

            return Doctor::fail(implode('; ', array_map(
                fn (string $id, array $missing): string => "{$id} needs " . implode(', ', $missing),
                array_keys($unmet),
                $unmet,
            )));
        });

        Doctor::check('plugged-host-core', 'Volume mounts', function (): array {
            $roots = Node::query()->distinct()->pluck('daemon_base')->filter()->all();
            $problems = [];
            $count = 0;

            foreach (Mount::query()->with('servers')->get() as $mount) {
                if (!SystemServers::isProtectedSource((string) $mount->source, $roots)) {
                    continue;
                }

                $count++;

                if ($mount->user_mountable) {
                    $problems[] = "{$mount->name} is user-mountable";
                }

                if ($mount->eggs()->exists() || $mount->nodes()->exists()) {
                    $problems[] = "{$mount->name} is attached to an egg or node";
                }

                foreach ($mount->servers as $server) {
                    if (SystemServers::allowedVolumeSource($server) !== SystemServers::clean((string) $mount->source)) {
                        $problems[] = "{$mount->name} on {$server->uuid_short} is not vouched for (Wings never gets it)";
                    }
                }
            }

            if ($problems !== []) {
                return Doctor::warn(implode('; ', $problems));
            }

            return Doctor::ok($count === 0 ? 'no mounts into server volumes' : "{$count} devbox volume mount(s), all vouched for");
        });

        Doctor::check('plugged-host-core', 'Scheduler (cron schedule:run)', function (): array {
            $tick = (int) Cache::get(self::SCHEDULER_KEY, 0);

            return $tick > time() - 180
                ? Doctor::ok('last tick ' . Doctor::ago($tick))
                : Doctor::fail('last tick ' . Doctor::ago($tick) . ': add `* * * * * php ' . base_path('artisan') . ' schedule:run` to the web user\'s crontab');
        });

        Doctor::check('plugged-host-core', 'Queue worker', function (): array {
            $beat = (int) Cache::get(QueueHeartbeat::CACHE_KEY, 0);

            return $beat > time() - 900
                ? Doctor::ok('took a job ' . Doctor::ago($beat))
                : Doctor::fail('no job taken since ' . Doctor::ago($beat) . ': is the queue worker service (php artisan queue:work) running?');
        });
    }
}
