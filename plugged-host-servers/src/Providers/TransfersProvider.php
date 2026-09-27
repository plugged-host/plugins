<?php

namespace PluggedHost\Servers\Providers;

use App\Enums\TablerIcon;
use App\Models\Server;
use App\Models\Subuser;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Servers\Console\Commands\TransferWatchCommand;
use PluggedHost\Servers\Filament\Server\Pages\ServerTransferPage;
use PluggedHost\Servers\Livewire\TransferStrip;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferService;
use PluggedHost\Servers\Services\Transfers\TransferWatcher;
use Throwable;

/**
 * Wiring for self-service transfers: the subuser permission, the watcher's schedule, and a
 * progress strip on every server page while a move runs (the console cannot show one —
 * upstream refuses its websocket to non-admins during a transfer).
 */
class TransfersProvider extends ServiceProvider
{
    public function register(): void
    {
        // "Transfer" tab in the subuser editor. Registered unconditionally and never hidden:
        // a group with no form field is stripped from the subuser the next time it is saved.
        Subuser::registerCustomPermissions('transfer', ['move'], 'plugged-host-servers::strings.transfers.permissions', TablerIcon::TruckDelivery);

        $this->app->singleton(TransferService::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // The watcher starts itself on the first move; this only catches a crash or a
            // reboot. It holds its own flock, so a second copy exits at once.
            $schedule->command(TransferWatchCommand::class)
                ->everyMinute()
                ->runInBackground()
                ->when(fn (): bool => TransferWatcher::hasWork())
                ->name('plugged-host-servers:transfers');
        });
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-servers.transfer-strip', TransferStrip::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): Htmlable {
                try {
                    return $this->strip();
                } catch (Throwable) {
                    return new HtmlString('');
                }
            },
        );
    }

    private function strip(): Htmlable
    {
        if (Filament::getCurrentPanel()?->getId() !== 'server') {
            return new HtmlString('');
        }

        $server = Filament::getTenant();

        if (!$server instanceof Server || !ServerRelocation::ready() || !ServerTransferPage::canAccess()) {
            return new HtmlString('');
        }

        $livewire = app('livewire')->current();

        if ($livewire instanceof ServerTransferPage) {
            return new HtmlString('');
        }

        $recent = ServerRelocation::query()
            ->where('server_id', $server->getKey())
            ->where(fn ($query) => $query->whereIn('state', ServerRelocation::ACTIVE)->orWhere('finished_at', '>', now()->subMinutes(10)))
            ->exists();

        if (!$recent) {
            return new HtmlString('');
        }

        return new HtmlString(Blade::render(
            '@livewire("plugged-host-servers.transfer-strip", ["serverId" => $serverId, "url" => $url], key("ph-transfer-strip-" . $serverId))',
            ['serverId' => (int) $server->getKey(), 'url' => ServerTransferPage::getUrl(tenant: $server)],
        ));
    }
}
