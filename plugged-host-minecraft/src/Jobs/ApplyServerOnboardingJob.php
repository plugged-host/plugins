<?php

namespace PluggedHost\Minecraft\Jobs;

use App\Enums\ServerState;
use App\Models\Server;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PluggedHost\Minecraft\Models\ServerOnboarding;
use PluggedHost\Minecraft\Services\ServerOnboardingApplier;
use Throwable;

/**
 * Re-apply onboarding answers to a server that is reinstalling.
 *
 * The wizard's last step can trigger a reinstall — a modpack install, or a loader/version change —
 * and a reinstall replaces the whole disk. Anything the wizard wrote to server.properties,
 * whitelist.json or ops.json before that would be gone. So when the answers imply a reinstall, the
 * files are not written at submit time at all: they are written here, once the install has
 * finished, from the answers persisted on the server_onboarding row.
 *
 * Waiting is the entire trick, and it is the same one InstallModpackJob uses: while the server is
 * ServerState::Installing there is nothing to write to, so release back onto the queue and look
 * again. Backing off in 20s steps rather than blocking a worker for the length of a Forge install.
 *
 * Ordering against InstallModpackJob: that job also waits for the install to end and then places
 * the pack's files. Both jobs may therefore wake up in the same window. They do not collide —
 * InstallModpackJob writes mods/, config/ and the pack's own overrides, this one writes three
 * top-level files a modpack does not ship — but a pack that ships its own server.properties would
 * win or lose by timing, which is why this job is delayed at dispatch rather than queued
 * immediately, and why the answers are the record of intent either way.
 */
class ApplyServerOnboardingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Roughly 30 minutes of 20s waits, which is longer than any install this panel has seen and
     * still bounded — a server stuck in Installing must not keep a job alive forever.
     */
    public int $tries = 90;

    /**
     * @param  array<string, mixed>  $answers
     */
    public function __construct(
        public Server $server,
        public array $answers,
        public ?int $userId = null,
    ) {}

    public function handle(ServerOnboardingApplier $applier): void
    {
        // Deleted while the install ran, or transferred away: nothing to configure.
        if (!Server::query()->whereKey($this->server->id)->exists()) {
            return;
        }

        $this->server->refresh();

        if ($this->server->status === ServerState::Installing) {
            $this->release(20);

            return;
        }

        // A failed install leaves a server nobody can start; writing a whitelist onto it would
        // only add noise to a support ticket that is about the install.
        if ($this->server->status === ServerState::InstallFailed || $this->server->status === ServerState::ReinstallFailed) {
            return;
        }

        $actor = $this->userId !== null ? User::query()->find($this->userId) : null;

        $roster = $applier->apply($this->server, $this->answers, $actor, system: true);

        // The row already says completed_at; this records what actually landed, so a support
        // question about "I asked for a whitelist and there is none" has an answer on the row
        // rather than only in the daemon's files.
        $record = ServerOnboarding::query()->where('server_id', $this->server->id)->first();

        if ($record !== null) {
            $record->answers = array_merge($this->answers, [
                'applied_at' => now()->toIso8601String(),
                'applied_errors' => $roster->errors(),
            ]);
            $record->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
