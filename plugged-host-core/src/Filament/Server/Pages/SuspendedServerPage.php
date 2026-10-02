<?php

namespace PluggedHost\Core\Filament\Server\Pages;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use PluggedHost\Core\Support\SuspensionReason;
use Throwable;

/**
 * What the owner (and subusers) of a suspended server see instead of its pages: that it is
 * suspended, by what, why, and where to ask (RedirectToSuspendedPage sends them here). Admins
 * keep the normal pages. A server limbo parked has limbo's restore page instead.
 */
class SuspendedServerPage extends Page
{
    protected static ?string $slug = 'suspended';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'plugged-host-core::filament.pages.suspended';

    /** Suspended by us or an admin, not parked by limbo (hibernation has its own page). */
    public static function appliesTo(Server $server): bool
    {
        if (!$server->isSuspended()) {
            return false;
        }

        $limbo = 'PluggedHost\\Limbo\\Services\\LimboService';

        if (class_exists($limbo)) {
            try {
                if (in_array(app($limbo)->state($server)?->state, ['hibernating', 'limbo', 'restoring'], true)) {
                    return false;
                }
            } catch (Throwable) {
                //
            }
        }

        return true;
    }

    public function mount(): void
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server || !self::appliesTo($server)) {
            $this->redirect(Console::getUrl(panel: 'server', tenant: $server));
        }
    }

    public function getTitle(): string
    {
        return trans('plugged-host-core::strings.suspended.title');
    }

    /** @return array{source: string, reasons: array<int, string>, note: string|null, at: \Carbon\CarbonImmutable}|null */
    public function reason(): ?array
    {
        $server = Filament::getTenant();

        return $server instanceof Server ? SuspensionReason::latest($server) : null;
    }

    public function supportUrl(): string
    {
        return (string) config('plugged-host-ai.support_url', 'https://discord.gg/plugged-host-1502647500295635085')
            ?: 'https://discord.gg/plugged-host-1502647500295635085';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('help')
                ->label(trans('plugged-host-core::strings.suspended.help_action'))
                ->url(fn (): string => $this->supportUrl(), shouldOpenInNewTab: true),
        ];
    }
}
