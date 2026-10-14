<?php

namespace PluggedHost\FiveM\Console\Commands;

use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use Illuminate\Console\Command;
use PluggedHost\FiveM\Support\FiveM;

/**
 * Put the cfx eggs on the plugged.host FiveM image, and optionally every FiveM server too.
 *
 * Per egg (any egg tagged `cfx`):
 *  - the image first in docker_images, so new servers get it (servers' provisioning takes the
 *    first one); the old images stay selectable;
 *  - the install script becomes egg/install.sh, run in the same image, so installs and starts
 *    lay the artifact down with the same code;
 *  - FIVEM_VERSION documents what it takes now (recommended, latest or a build number);
 *  - FIVEM_EDITION is added (legacy | enhanced), hidden from the Startup page because the
 *    Enhanced page sets it;
 *  - the stop command becomes SIGINT. The egg's `quit` goes to txAdmin's console, which ignores
 *    it while txAdmin has no server running (fresh servers, setup screens), so every stop and
 *    restart then sat out Wings' ten-minute timeout. SIGINT reaches txAdmin and FXServer alike
 *    (tini forwards it to the whole process group) and both shut down cleanly on it.
 *
 * With --servers, every FiveM server not on the image moves onto it: the change applies at the
 * server's next start, and the first start on the image installs the build its FIVEM_VERSION
 * asks for once (the old egg left no record of which build it laid down).
 *
 * Idempotent: run it again after an egg update (an egg import resets the install script).
 */
class FiveMSetupCommand extends Command
{
    protected $signature = 'plugged-host:fivem-setup
        {--servers : Also move every FiveM server onto the image}
        {--dry-run : Show what would change}';

    protected $description = 'Put the cfx eggs (and with --servers, every FiveM server) on the plugged.host FiveM image';

    public function handle(): int
    {
        $image = FiveM::image();
        $script = (string) file_get_contents(plugin_path('plugged-host-fivem', 'egg', 'install.sh'));
        $dry = (bool) $this->option('dry-run');

        $eggs = Egg::query()->get()->filter(fn (Egg $egg) => FiveM::isCfxEgg($egg));

        if ($eggs->isEmpty()) {
            $this->warn('No egg is tagged "cfx".');

            return self::FAILURE;
        }

        foreach ($eggs as $egg) {
            $images = (array) $egg->docker_images;
            $images = ['plugged.host FiveM' => $image] + array_filter($images, fn ($existing) => ltrim((string) $existing, '~') !== ltrim($image, '~'));

            $this->line("Egg #{$egg->id} {$egg->name}: image $image first, install script in $image, FIVEM_EDITION variable, stop by SIGINT");

            if ($dry) {
                continue;
            }

            $egg->forceFill([
                'docker_images' => $images,
                'script_container' => $image,
                'script_entry' => 'bash',
                'script_install' => str_replace("\r\n", "\n", $script),
                'copy_script_from' => null,
                // Pelican sends "^X" as signal X, and Wings knows SIGINT by that name.
                'config_stop' => '^SIGINT',
            ])->save();

            EggVariable::query()->updateOrCreate(
                ['egg_id' => $egg->id, 'env_variable' => FiveM::VERSION],
                [
                    'name' => 'FiveM version',
                    'description' => 'recommended or latest follows Cfx (every start installs that channel\'s current build), a build number stays on that build. Set from the Artifacts page.',
                    'default_value' => 'recommended',
                    'user_viewable' => true,
                    'user_editable' => true,
                    'rules' => ['required', 'string', 'regex:/^(recommended|latest|[0-9]{3,6})$/'],
                ],
            );

            EggVariable::query()->firstOrCreate(
                ['egg_id' => $egg->id, 'env_variable' => FiveM::EDITION],
                [
                    'name' => 'FiveM edition',
                    'description' => 'legacy runs FiveM, enhanced runs FiveM for GTAV Enhanced. Set from the Enhanced page.',
                    'default_value' => FiveM::LEGACY,
                    'user_viewable' => false,
                    'user_editable' => false,
                    'rules' => ['required', 'in:legacy,enhanced'],
                    'sort' => 99,
                ],
            );
        }

        if ($this->option('servers')) {
            $servers = Server::query()->whereIn('egg_id', $eggs->pluck('id'))->get()
                ->reject(fn (Server $server) => FiveM::onImage($server));

            $this->line(sprintf('%d FiveM server%s to move onto %s', $servers->count(), $servers->count() === 1 ? '' : 's', $image));

            if (!$dry) {
                foreach ($servers as $server) {
                    $server->forceFill(['image' => $image])->save();
                }
            }
        }

        $this->info($dry ? 'Dry run, nothing changed.' : 'Done. Servers pick the image up at their next start.');

        return self::SUCCESS;
    }
}
