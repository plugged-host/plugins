<?php

namespace PluggedHost\Devbox\Console\Commands;

use App\Enums\EggFormat;
use App\Models\User;
use App\Services\Eggs\Sharing\EggImporterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PluggedHost\Devbox\Services\DevboxProvisioner;
use Ramsey\Uuid\Uuid;

/**
 * Puts what devboxes need in place, and is safe to run again (after an update): the hidden
 * devbox egg shipped with the plugin, and the system account that owns every devbox server.
 */
class DevboxSetupCommand extends Command
{
    protected $signature = 'plugged-host:devbox-setup';

    protected $description = 'Install or update the devbox egg and the account that owns devbox servers.';

    public function handle(EggImporterService $importer): int
    {
        $json = (string) file_get_contents(plugin_path('plugged-host-devbox', 'resources/egg/devbox.json'));

        // The egg lists the image the settings pick (this release's tag, or DEVBOX_IMAGE), not
        // the one the file was written with. Each devbox server carries its own image anyway,
        // which DevboxPower keeps on the same setting at every start.
        $data = json_decode($json, true);
        if (is_array($data)) {
            $data['docker_images'] = ['Devbox' => DevboxProvisioner::image()];
            $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $egg = $importer->fromContent($json, EggFormat::JSON, DevboxProvisioner::egg());
        $this->info("Egg: {$egg->name} (#{$egg->id}, {$egg->uuid})");
        $this->info('Image: ' . DevboxProvisioner::image());

        $username = (string) config('plugged-host-devbox.system_user', 'ph-devbox');
        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $user = User::query()->forceCreate([
                'uuid' => Uuid::uuid4()->toString(),
                'username' => $username,
                'email' => $username . '@devbox.invalid',
                // Random and never shown: nobody signs in as this account.
                'password' => Hash::make(Str::random(64)),
                'language' => 'en',
            ]);
            $this->info("System user created: {$user->username} (#{$user->id})");
        } else {
            $this->info("System user: {$user->username} (#{$user->id})");
        }

        return self::SUCCESS;
    }
}
