<?php

namespace PluggedHost\Neo\Listeners;

use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Str;
use PluggedHost\Neo\Agent\ServerGuard;
use PluggedHost\Neo\Services\Mclogs;
use PluggedHost\Neo\Support\MclogsHandoff;
use PluggedHost\Neo\Support\NeoAccess;
use Throwable;

/**
 * "Ask neo" for the mclogs-uploader plugin (Boy132, from the Pelican hub).
 *
 * That plugin is kept byte-for-byte upstream so hub updates keep working, and it has no hook of
 * its own, so neo watches for its upload instead: when the console is pasted to mclo.gs from a
 * server page and mclo.gs finds problems or errors in it, neo adds a notification listing them
 * with an "Ask neo" button. The upload itself is never affected; if anything here fails, the
 * user just gets the plugin's own notification.
 */
final class OfferNeoForMclogsUpload
{
    private const LISTED_PROBLEMS = 3;

    public function handle(ResponseReceived $event): void
    {
        $upload = Mclogs::uploaded($event->request, $event->response);

        if ($upload === null) {
            return;
        }

        try {
            $this->offer($upload);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array{id: string, url: string, errors: int}  $upload
     */
    private function offer(array $upload): void
    {
        $user = auth()->user();
        $server = Filament::getCurrentPanel()?->getId() === 'server' ? Filament::getTenant() : null;

        if (!$user instanceof User || !$server instanceof Server || !NeoAccess::enabledFor($user) || !ServerGuard::canUse($user, $server)) {
            return;
        }

        try {
            $problems = app(Mclogs::class)->insights($upload['id'])['problems'];
        } catch (Throwable) {
            // The analysis is a bonus: the error count from the upload is enough to offer help.
            $problems = [];
        }

        if ($problems === [] && $upload['errors'] === 0) {
            return;
        }

        MclogsHandoff::remember($user, $server, $upload, count($problems));

        Notification::make()
            ->title($problems === []
                ? trans_choice('plugged-host-neo::strings.mclogs.errors_title', $upload['errors'], ['count' => $upload['errors']])
                : trans_choice('plugged-host-neo::strings.mclogs.problems_title', count($problems), ['count' => count($problems)]))
            ->body($this->body($problems))
            ->icon('tabler-sparkles')
            ->warning()
            ->persistent()
            ->actions([
                Action::make('plugged-host-neo-ask')
                    ->label(trans('plugged-host-neo::strings.mclogs.ask'))
                    ->button()
                    ->dispatch('plugged-host-neo-ask', ['log' => $upload['id']])
                    ->close(),
                Action::make('plugged-host-neo-view-log')
                    ->label(trans('plugged-host-neo::strings.mclogs.view'))
                    ->link()
                    ->url($upload['url'], shouldOpenInNewTab: true),
            ])
            ->send();
    }

    /**
     * The first few problems mclo.gs named. They quote file and plugin names from the log, so
     * they are escaped (the notification body renders HTML).
     *
     * @param  list<array<string, mixed>>  $problems
     */
    private function body(array $problems): string
    {
        if ($problems === []) {
            return e(trans('plugged-host-neo::strings.mclogs.errors_body'));
        }

        $lines = array_map(
            static fn (array $problem): string => '• ' . e(Str::limit((string) ($problem['message'] ?? ''), 160)),
            array_slice($problems, 0, self::LISTED_PROBLEMS),
        );

        if (count($problems) > self::LISTED_PROBLEMS) {
            $lines[] = e(trans('plugged-host-neo::strings.mclogs.more', ['count' => count($problems) - self::LISTED_PROBLEMS]));
        }

        return implode('<br>', $lines);
    }
}
