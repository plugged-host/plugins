<?php

namespace PluggedHost\Neo\Providers;

use App\Enums\TabPosition;
use App\Filament\Pages\Auth\EditProfile;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Neo\Console\Commands\NeoPruneCommand;
use PluggedHost\Neo\Livewire\NeoChat;
use PluggedHost\Neo\Livewire\NeoProfileSettings;
use PluggedHost\Neo\Support\NeoAccess;
use Throwable;

/**
 * Wires neo into the panel: the profile tab, the floating chat on the app and server panels,
 * the activity-log sentences, and the daily retention prune. Everything is inert until
 * NEO_ENABLED is on and the migrations have run (NeoAccess::ready()).
 */
class NeoPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(NeoPruneCommand::class)
                ->dailyAt('03:40')
                ->withoutOverlapping(30)
                ->name('plugged-host-neo:prune');
        });

        // After core's danger zone: plugins load in load_order and neo is 7.
        EditProfile::registerCustomTabs(
            TabPosition::After,
            Tab::make('neo')
                ->label(fn () => trans('plugged-host-neo::strings.profile.tab'))
                ->icon('tabler-sparkles')
                ->visible(fn (): bool => NeoAccess::ready())
                ->schema([
                    LivewireComponent::make(NeoProfileSettings::class)->key('plugged-host-neo-profile'),
                ]),
        );
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-neo.chat', NeoChat::class);
        Livewire::component('plugged-host-neo.profile-settings', NeoProfileSettings::class);

        $this->injectActivityDescriptions();

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
