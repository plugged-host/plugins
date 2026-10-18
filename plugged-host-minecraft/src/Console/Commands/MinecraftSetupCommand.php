<?php

namespace PluggedHost\Minecraft\Console\Commands;

use App\Enums\EggFormat;
use App\Services\Eggs\Sharing\EggImporterService;
use Illuminate\Console\Command;
use PluggedHost\Minecraft\Support\MinecraftEgg;
use PluggedHost\Minecraft\Support\UserAgent;

/**
 * Puts the Minecraft: Java Edition egg this plugin ships (egg/egg-minecraft-java.json) on the
 * panel, under its own UUID, which servers' catalog and so the Minecraft pages know it by. Only
 * when no egg has that UUID yet: an existing one is left alone.
 *
 * --update imports the file over the existing egg instead. Its install script, variables (one
 * the file does not have is deleted, and every server's value for it with it), startup
 * commands, images and config become the file's. Servers are not changed: each keeps its own
 * startup command, image and variable values, and the install script only runs at a reinstall.
 *
 * Imported on another panel, what players and APIs see names that panel (brand()).
 */
class MinecraftSetupCommand extends Command
{
    /** What the exported file says where it names plugged.host. */
    private const BRAND = 'plugged.host';

    /** The User-Agent the file's installer sends PaperMC. */
    private const EGG_USER_AGENT = 'plugged.host-egg/1.0 (support@plugged.host)';

    protected $signature = 'plugged-host:minecraft-setup
        {--update : Re-import the egg over the existing one (replaces its install script, variables and startup commands)}
        {--dry-run : Show what would change}';

    protected $description = 'Import the Minecraft: Java Edition egg this plugin ships (with --update, over the existing one)';

    public function handle(EggImporterService $importer): int
    {
        $path = MinecraftEgg::path();
        $data = json_decode((string) @file_get_contents($path), true);

        if (!is_array($data) || ($data['uuid'] ?? null) !== MinecraftEgg::UUID) {
            $this->error("Could not read the egg from $path.");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $egg = MinecraftEgg::find();

        if ($egg === null) {
            $this->line("No egg with uuid {$data['uuid']}: importing {$data['name']} from $path");
        } elseif (!$this->option('update')) {
            $this->info("Egg #{$egg->id} {$egg->name} ({$egg->uuid}) is already there, left as it is.");
            $this->line('--update imports the file over it: that replaces its install script, variables and startup commands.');

            return self::SUCCESS;
        } else {
            $variables = array_map(fn (array $variable) => strtoupper((string) ($variable['env_variable'] ?? '')), (array) ($data['variables'] ?? []));
            $dropped = $egg->variables()->pluck('env_variable')->reject(fn ($env) => in_array(strtoupper((string) $env), $variables, true))->values();
            $servers = $egg->servers()->count();

            $this->warn("Replacing egg #{$egg->id} {$egg->name} with the file: its install script, variables, startup commands, images and config become the file's.");
            if ($dropped->isNotEmpty()) {
                $this->warn('Deleted with it, and every server\'s value for them: the variables ' . $dropped->implode(', ') . ', which the file does not have.');
            }
            $this->line("Its $servers server" . ($servers === 1 ? '' : 's') . ' are not changed: they keep their startup command, image and variable values, and the new install script runs at their next reinstall.');
        }

        if ($dry) {
            $this->info('Dry run, nothing changed.');

            return self::SUCCESS;
        }

        $json = json_encode($this->brand($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $egg = $importer->fromContent($json, EggFormat::JSON, $egg);

        $this->info("Egg: {$egg->name} (#{$egg->id}, {$egg->uuid})");

        return self::SUCCESS;
    }

    /**
     * The file is plugged.host's egg as exported from its panel. Imported on another panel, what
     * players and APIs see names that panel: the description (shown when creating a server), the
     * installer's banner, and the User-Agent the installer sends PaperMC (this plugin's, with
     * -egg/ for -panel/). On plugged.host everything comes out as the file has it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function brand(array $data): array
    {
        $app = trim((string) config('app.name', ''));

        if (is_string($data['description'] ?? null)) {
            $data['description'] = trim(str_replace(self::BRAND, $app, $data['description']));
        }

        $script = $data['scripts']['installation']['script'] ?? null;
        if (is_string($script)) {
            // Both land between double quotes in bash: nothing that ends or expands them.
            $clean = fn (string $value): string => trim((string) preg_replace('/["$`\\\\\r\n]+/', '', $value));

            // strtr: the User-Agent first (it contains the brand), and never over what it put in.
            $data['scripts']['installation']['script'] = strtr($script, [
                self::EGG_USER_AGENT => $clean(str_replace('-panel/', '-egg/', UserAgent::get())),
                self::BRAND => $clean($app),
            ]);
        }

        return $data;
    }
}
