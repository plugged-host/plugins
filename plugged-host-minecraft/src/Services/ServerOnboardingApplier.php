<?php

namespace PluggedHost\Minecraft\Services;

use App\Models\Server;
use App\Models\User;
use PluggedHost\Minecraft\Models\ServerOnboarding;

/**
 * Turn a set of onboarding answers into the files a Minecraft server actually reads.
 *
 * This exists because the answers get applied from two places and must mean the same thing in
 * both: straight away when the wizard is submitted and nothing is going to reinstall, or from
 * ApplyServerOnboardingJob after an install has finished wiping the disk. Putting the mapping
 * here is what stops "private" meaning white-list plus enforce-whitelist in one caller and only
 * white-list in the other.
 *
 * No decisions of its own: the order below is the decision, and it is deliberate.
 *
 *  1. server.properties first, because online-mode is what decides whether a name resolves to a
 *     Mojang uuid or to an offline one (MinecraftProfileResolver), and every whitelist entry
 *     written afterwards depends on that answer.
 *  2. whitelist.json, then ops.json. An operator who cannot get in is useless, so access comes
 *     before powers — and on a running server the two are separate console commands anyway.
 *
 * @see MinecraftPlayerRoster for the console-or-file strategy each write goes through
 * @see ServerOnboarding for where the answers are persisted between submit and job
 */
class ServerOnboardingApplier
{
    /**
     * Apply the answers and hand back the roster that did it, so the caller can report.
     *
     * `$system` is for the queued path: there is no authenticated user in a job, so the roster's
     * per-user permission checks would fail closed even though the wizard already checked them at
     * submit time. It also suppresses notifications, which have nowhere to render from a worker.
     *
     * @param  array<string, mixed>  $answers
     */
    public function apply(Server $server, array $answers, ?User $actor = null, bool $system = false): MinecraftPlayerRoster
    {
        $roster = MinecraftPlayerRoster::for($server);

        if ($system) {
            $roster->asSystem($actor)->silently();
        }

        $roster->load();

        $private = (bool) ($answers['private'] ?? false);

        $roster->writeProperties([
            'online-mode' => (bool) ($answers['online_mode'] ?? true),
            // Both keys, because either one alone is a half-open door: white-list off ignores the
            // file entirely, and enforce-whitelist off leaves whoever is already connected in
            // when the list changes.
            'white-list' => $private,
            'enforce-whitelist' => $private,
        ], create: true);

        foreach ($this->names($answers['players'] ?? []) as $name) {
            $roster->setWhitelisted($name, true);
        }

        foreach ($this->names($answers['operators'] ?? []) as $name) {
            $roster->setOperator($name, true);
        }

        // WP6: Bedrock support. Last, because Geyser installs jars and restarts the server, and
        // both want the properties/whitelist already written. It lands here rather than in the
        // page so the reinstall path (ApplyServerOnboardingJob) gets it too — a reinstall wipes
        // the disk, so installing Geyser before it would drop the jars. GeyserService is
        // idempotent and re-checks the loader has a build, so a stale "bedrock: true" for a
        // loader that changed to Forge mid-wizard is a no-op rather than an error.
        if (($answers['bedrock'] ?? false) === true) {
            try {
                app(GeyserService::class)->install($server, $actor?->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $roster;
    }

    /**
     * The names worth trying, de-duplicated case-insensitively.
     *
     * Re-validated here rather than trusted from the form: these answers are replayed from a JSON
     * column by a job that runs minutes later, and a name Minecraft would refuse costs a daemon
     * round trip and an error line for nothing.
     *
     * @return array<int, string>
     */
    protected function names(mixed $names): array
    {
        $resolver = app(MinecraftProfileResolver::class);
        $seen = [];

        foreach ((array) $names as $name) {
            if (!is_string($name)) {
                continue;
            }

            $name = trim($name);

            if ($name === '' || !$resolver->plausible($name)) {
                continue;
            }

            $seen[mb_strtolower($name)] ??= $name;
        }

        return array_values($seen);
    }
}
