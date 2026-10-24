<?php

namespace PluggedHost\Ai\Providers;

use App\Enums\TablerIcon;
use App\Filament\Server\Pages\Console;
use App\Models\Subuser;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use PluggedHost\Ai\Agent\ChatImages;
use PluggedHost\Ai\Console\Commands\NeoPruneCommand;
use PluggedHost\Ai\Livewire\LogCheckBanner;
use PluggedHost\Ai\Livewire\NeoChat;
use PluggedHost\Ai\Models\NeoMessage;
use PluggedHost\Core\Support\ActivityLines;
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
        Subuser::registerCustomPermissions('neo', ['access'], 'plugged-host-ai::strings.permissions', TablerIcon::Sparkles);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(NeoPruneCommand::class)
                ->dailyAt('03:40')
                ->withoutOverlapping(30)
                ->name('plugged-host-ai:neo-prune');
        });
    }

    public function boot(): void
    {
        Livewire::component('plugged-host-ai.chat', NeoChat::class);

        // Images attached in the chat or made for it, for its owner only (ChatImages).
        Route::middleware(['web'])->get('/neo/images/{message}/{index}', function (int $message, int $index) {
            $row = NeoMessage::query()->with('conversation')->find($message);
            $owner = $row?->conversation?->user_id;
            $path = ChatImages::existing(((array) $row?->meta)['images'][$index]['path'] ?? null);

            abort_unless(in_array($row?->role, [NeoMessage::USER, NeoMessage::ASSISTANT], true) && $owner !== null && (int) $owner === (int) auth()->id() && $path !== null, 404);

            return Storage::disk('local')->response($path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=604800, immutable']);
        })->whereNumber(['message', 'index'])->name('plugged-host-ai.image');

        $this->injectActivityDescriptions();

        Livewire::component('plugged-host-ai.log-check', LogCheckBanner::class);

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

                    return new HtmlString((string) view('plugged-host-ai::log-check-host', [
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

                    return new HtmlString((string) view('plugged-host-ai::chat-host')->render());
                } catch (Throwable $e) {
                    report($e);

                    return new HtmlString('');
                }
            },
        );
    }

    private function injectActivityDescriptions(): void
    {
        ActivityLines::inject('plugged-host-ai');
    }
}
