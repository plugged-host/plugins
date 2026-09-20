<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Services\Servers\ReinstallServerService;
use Exception;
use PluggedHost\Servers\Services\GameCatalog;

/**
 * Point a server at a different mod loader and/or Minecraft version, then reinstall.
 *
 * Extracted because two callers need exactly this sequence and getting it subtly wrong is
 * expensive: the Version tab on the Settings page, and InstallModpackJob, which has to
 * switch the loader before it can place a modpack's files.
 *
 * The variables are written straight onto the server rather than through
 * StartupModificationService. MC_SOFTWARE is `user_editable` but not `user_viewable`, so
 * StartupModification rejects it as an untrusted edit — correct for a request coming from a
 * user's browser, wrong here, where the caller has already established authority (the
 * SettingsReinstall permission, or a job the user queued).
 */
class ServerSoftwareSwitcher
{
    public function __construct(
        private GameCatalog $catalog,
        private ReinstallServerService $reinstallService,
    ) {}

    /**
     * @throws Exception when the egg declares no loader variants, or the reinstall fails
     */
    public function apply(Server $server, ?string $loader, string $version, bool $reinstall = true): void
    {
        $types = $this->catalog->typesFor($server->egg);

        if ($types === null) {
            throw new Exception('This egg declares no software variants.');
        }

        $variables = [
            $types['variable'] => $loader,
            'MINECRAFT_VERSION' => $version,
        ];

        // A pinned Purpur build cannot survive a version or loader change.
        if ($server->variables->firstWhere('env_variable', 'BUILD_NUMBER')) {
            $variables['BUILD_NUMBER'] = 'latest';
        }

        $this->writeVariables($server, $variables);

        $runtime = $this->catalog->resolveRuntime($server->egg, $loader);

        $updates = array_filter([
            'startup' => $runtime['startup'],
            'image' => $runtime['image'],
        ], fn ($value) => $value !== null);

        if ($updates) {
            $server->forceFill($updates)->saveOrFail();
        }

        $server->refresh();

        if ($reinstall) {
            $this->reinstallService->handle($server);
        }
    }

    /**
     * The loader currently installed, read back from the egg's variant variable.
     *
     * Falls back to the first declared variant rather than null when the stored value is
     * missing or unrecognised: every server running this egg is running *something*, and
     * the first variant is the one the egg installs by default.
     */
    public function loader(Server $server): ?string
    {
        $catalog = $this->catalog->typesFor($server->egg);

        if (!$catalog || $catalog['variable'] === '') {
            return null;
        }

        $value = $server->variables
            ->firstWhere('env_variable', $catalog['variable'])
            ?->server_value;

        $values = array_column($catalog['types'], 'value');

        return in_array($value, $values, true) ? $value : ($values[0] ?? null);
    }

    /**
     * The Minecraft version currently installed, or "latest" when the server tracks it.
     */
    public function version(Server $server): string
    {
        $value = $server->variables
            ->firstWhere('env_variable', 'MINECRAFT_VERSION')
            ?->server_value;

        return is_string($value) && $value !== '' ? $value : MinecraftVersionCatalog::LATEST;
    }

    /**
     * Write env-variable values by name, skipping anything the egg does not declare.
     *
     * Silently skipping is deliberate: eggs differ in which variables they expose (only
     * Purpur has BUILD_NUMBER), and a missing one means "this egg has no such knob", not a
     * failure worth aborting a reinstall over.
     *
     * @param  array<string, string|null>  $variables
     */
    public function writeVariables(Server $server, array $variables): void
    {
        foreach ($variables as $env => $value) {
            $serverVariable = $server->variables->firstWhere('env_variable', $env);

            if (!$serverVariable) {
                continue;
            }

            $server->serverVariables()->updateOrCreate(
                ['variable_id' => $serverVariable->id],
                ['variable_value' => (string) $value],
            );
        }
    }
}
