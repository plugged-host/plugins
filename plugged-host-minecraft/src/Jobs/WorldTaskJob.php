<?php

namespace PluggedHost\Minecraft\Jobs;

use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\DownloadFiles;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Services\WorldService;
use RuntimeException;
use Throwable;

/**
 * The slow world operations from the Worlds page: zipping a world for download, starting a
 * new world (with new generation settings or just a new seed) and importing an uploaded one.
 * Each can take minutes on a big world, longer than a web request may run, so they are
 * queued and report back through the notification bell.
 *
 * Anything that deletes a world re-checks that the server is stopped right before it does:
 * the page checked when the job was queued, but the owner may have started it since.
 */
class WorldTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const DOWNLOAD = 'download';

    public const RECREATE = 'recreate';

    public const IMPORT = 'import';

    // Deleting or moving a world twice is not something to retry into.
    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $options  recreate: properties (server.properties values), keep_copy; import: archive, keep_copy
     */
    public function __construct(
        public Server $server,
        public int $userId,
        public string $task,
        public string $world,
        public array $options = [],
    ) {}

    public function handle(WorldService $worlds): void
    {
        try {
            match ($this->task) {
                self::DOWNLOAD => $this->download($worlds),
                self::RECREATE => $this->recreate($worlds),
                self::IMPORT => $this->import($worlds),
                default => null,
            };
        } catch (Throwable $e) {
            if (!$e instanceof RuntimeException && !$e instanceof \InvalidArgumentException) {
                report($e);
            }

            $this->tell(false, trans('plugged-host-minecraft::strings.worlds.failed_' . $this->task), $this->reason($e));
        }
    }

    protected function download(WorldService $worlds): void
    {
        $this->saveIfRunning();

        $archive = $worlds->archive($this->server, $this->world, 'download');

        // Not left lying around to fill the disk: gone in two hours.
        DeleteServerFileJob::dispatch($this->server, $archive)->delay(now()->addHours(2));

        Activity::event('server:file.compress')
            ->subject($this->server)
            ->actor(User::find($this->userId))
            ->property('files', $worlds->folders($this->server, $this->world))
            ->log();

        $this->tell(true, trans('plugged-host-minecraft::strings.worlds.download_ready', ['world' => $this->world]), trans('plugged-host-minecraft::strings.worlds.download_ready_body'), [
            Action::make('download')
                ->label(trans('plugged-host-minecraft::strings.worlds.download'))
                ->button()
                ->url(DownloadFiles::getUrl(['path' => $archive], panel: 'server', tenant: $this->server), shouldOpenInNewTab: true),
        ]);
    }

    /**
     * A new world in place of this one: optionally zip the old one first, delete it, then set
     * the generation settings the next start creates it with.
     */
    protected function recreate(WorldService $worlds): void
    {
        $this->assertStopped();
        $copy = $this->keepCopy($worlds);

        $worlds->remove($this->server, $this->world);
        $worlds->setProperties($this->server, (array) ($this->options['properties'] ?? []));

        $this->log('server:minecraft.world.recreate');
        $this->tell(true, trans('plugged-host-minecraft::strings.worlds.recreated', ['world' => $this->world]), $this->copyNote($copy));
    }

    protected function import(WorldService $worlds): void
    {
        $this->assertStopped();
        $copy = $this->keepCopy($worlds);

        $worlds->remove($this->server, $this->world);
        $worlds->import($this->server, (string) ($this->options['archive'] ?? ''), $this->world);

        $this->log('server:minecraft.world.import');
        $this->tell(true, trans('plugged-host-minecraft::strings.worlds.imported', ['world' => $this->world]), $this->copyNote($copy));
    }

    protected function keepCopy(WorldService $worlds): ?string
    {
        if (!($this->options['keep_copy'] ?? false) || $worlds->folders($this->server, $this->world) === []) {
            return null;
        }

        return $worlds->archive($this->server, $this->world, 'backup');
    }

    protected function copyNote(?string $copy): string
    {
        return $copy === null
            ? trans('plugged-host-minecraft::strings.worlds.start_to_generate')
            : trans('plugged-host-minecraft::strings.worlds.copy_kept', ['file' => $copy]);
    }

    protected function assertStopped(): void
    {
        if (!$this->server->fresh()?->retrieveStatus()->isOffline()) {
            throw new RuntimeException('server_running');
        }
    }

    /**
     * Flush the world to disk before zipping a running server, so the download is not missing
     * the last minutes of play.
     */
    protected function saveIfRunning(): void
    {
        try {
            if ($this->server->retrieveStatus()->isOffline()) {
                return;
            }

            Http::daemon($this->server->node)->post("/api/servers/{$this->server->uuid}/commands", ['commands' => ['save-all flush']]);
            sleep(3);
        } catch (Throwable) {
            //
        }
    }

    protected function log(string $event): void
    {
        Activity::event($event)
            ->subject($this->server)
            ->actor(User::find($this->userId))
            ->property('world', $this->world)
            ->log();
    }

    protected function reason(Throwable $e): string
    {
        $key = 'plugged-host-minecraft::strings.worlds.error_' . $e->getMessage();
        $text = trans($key);

        return $text === $key ? trans('plugged-host-minecraft::strings.worlds.error_generic') : $text;
    }

    /**
     * @param  list<Action>  $actions
     */
    protected function tell(bool $success, string $title, string $body, array $actions = []): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $notification = Notification::make()->title($title)->body($body)->actions($actions);

        // The bell polls, so no broadcasting driver is needed for this to arrive.
        ($success ? $notification->success() : $notification->danger())->sendToDatabase($user);
    }
}
