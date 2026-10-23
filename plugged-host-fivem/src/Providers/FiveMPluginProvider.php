<?php

namespace PluggedHost\FiveM\Providers;

use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Doctor\Doctor;
use PluggedHost\Core\Support\ActivityLines;
use PluggedHost\FiveM\Support\FiveM;

/**
 * The FiveM plugin's activity sentences and its doctor check. Globbed from src/Providers, so it
 * registers with no manifest edit.
 */
class FiveMPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        ActivityLines::inject('plugged-host-fivem');

        // Core is a hard requirement (plugin.json), so its Doctor is always there.
        $this->registerDoctorChecks();
    }

    private function registerDoctorChecks(): void
    {
        Doctor::check('plugged-host-fivem', 'FiveM eggs on the image', function (): array {
            $eggs = Egg::query()->get()->filter(fn (Egg $egg) => FiveM::isCfxEgg($egg));

            if ($eggs->isEmpty()) {
                return Doctor::ok('no cfx egg');
            }

            $image = ltrim(FiveM::image(), '~');
            $behind = $eggs->filter(fn (Egg $egg) => ltrim((string) $egg->script_container, '~') !== $image
                || !EggVariable::query()->where('egg_id', $egg->id)->where('env_variable', FiveM::EDITION)->exists());

            return $behind->isEmpty()
                ? Doctor::ok($eggs->count() . ' egg(s) install with ' . $image)
                : Doctor::fail('egg ' . $behind->pluck('id')->implode(', ') . ' not set up: run php artisan plugged-host:fivem-setup');
        });

        Doctor::check('plugged-host-fivem', 'FiveM servers on the image', function (): array {
            $eggs = Egg::query()->get()->filter(fn (Egg $egg) => FiveM::isCfxEgg($egg))->pluck('id');
            $servers = Server::query()->whereIn('egg_id', $eggs)->get(['id', 'image']);
            $off = $servers->reject(fn (Server $server) => FiveM::onImage($server))->count();

            return $off === 0
                ? Doctor::ok($servers->count() . ' server(s)')
                : Doctor::warn("$off of {$servers->count()} still on the old image (no artifact updates): php artisan plugged-host:fivem-setup --servers");
        });
    }
}
