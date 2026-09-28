<?php

namespace PluggedHost\Neo\Providers;

use App\Enums\TablerIcon;
use App\Filament\Server\Pages\Console;
use App\Models\Subuser;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Neo\Console\Commands\NeoPruneCommand;
use PluggedHost\Neo\Livewire\LogCheckBanner;
use PluggedHost\Neo\Livewire\NeoChat;
use Throwable;

/**
 * Wires neo into the panel: the floating chat on the app and server panels (its settings live
 * inside it), the automatic log check above the console with "Ask neo to fix it", the
 * activity-log sentences, and the daily retention prune.
 * Everything is inert until NEO_ENABLED is on and the migrations have run (NeoAccess::ready()).
 */
class NeoPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // "neo" tab in the subuser editor: owners decide which subusers may use neo on their
        // server. Registered unconditionally and never hidden -- a group with no form field is
        // silently stripped from the subuser the next time the owner saves them.
        Subuser::registerCustomPermissions('neo', ['access'], 'plugged-host-neo::strings.permissions', TablerIcon::Sparkles);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(NeoPruneCommand::class)
                ->dailyAt('03:40')
                ->withoutOverlapping(30)
                ->name('plugged-host-neo:prune');
        });
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-neo.chat', NeoChat::class);

        $this->injectActivityDescriptions();

        Livewire::component('plugged-host-neo.log-check', LogCheckBanner::class);

        // The automatic log check, at the top of the console page (LogCheckBanner). Rendered
        // inside a try so a failure costs the banner, never the console.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): Htmlable {
                try {
                    if (!LogCheckBanner::shouldMount()) {
                        return new HtmlString('');
                    }

                    $server = Filament::getTenant();

                    return new HtmlString((string) view('plugged-host-neo::log-check-host', [
                        'serverId' => (int) $server?->getKey(),
                    ])->render());
                } catch (Throwable $e) {
                    report($e);

                    return new HtmlString('');
                }
            },
            Console::class,
        );

        // Unscoped and filtered inside, like every plugged.host body hook; rendered inside the
        // try so a failure costs the launcher, never the page.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): Htmlable {
                try {
                    if (!NeoChat::shouldMount()) {
                        return new HtmlString('');
                    }

                    return new HtmlString((string) view('plugged-host-neo::chat-host')->render());
                } catch (Throwable $e) {
                    report($e);

                    return new HtmlString('');
                }
            },
        );
    }

    private function injectActivityDescriptions(): void
    {
        foreach (['en', 'de'] as $locale) {
            try {
                $lines = trans('plugged-host-neo::activity', [], $locale);

                if (is_array($lines)) {
                    Lang::addLines($lines, $locale);
                }
            } catch (Throwable) {
                //
            }
        }
    }
}
