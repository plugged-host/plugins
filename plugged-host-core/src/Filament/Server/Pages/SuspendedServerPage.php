<?php

namespace PluggedHost\Core\Filament\Server\Pages;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Closure;
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

    /** @var list<Closure(Server): bool> */
    private static array $claims = [];

    /** @var null|Closure(Server): ?CarbonImmutable */
    private static ?Closure $deletionTime = null;

    /**
     * Another plugin shows this kind of suspension on a page of its own: plugged-host-limbo
     * claims a hibernated server. Registered from that plugin's provider.
     *
     * @param  Closure(Server): bool  $claims
     */
    public static function claimedWhen(Closure $claims): void
    {
        self::$claims[] = $claims;
    }

    /**
     * When a suspended server will be deleted, for the notice on this page:
     * plugged-host-abuse's purge registers it.
     *
     * @param  Closure(Server): ?CarbonImmutable  $resolver
     */
    public static function deletionTimeUsing(Closure $resolver): void
    {
        self::$deletionTime = $resolver;
    }

    /** Suspended by us or an admin, and no other plugin shows it on its own page. */
    public static function appliesTo(Server $server): bool
    {
        if (!$server->isSuspended()) {
            return false;
        }

        foreach (self::$claims as $claims) {
            try {
                if ($claims($server)) {
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

    /** When this server will be deleted (see deletionTimeUsing()), or null when it will not. */
    public function deletesAt(): ?CarbonImmutable
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server || self::$deletionTime === null) {
            return null;
        }

        try {
            return (self::$deletionTime)($server);
        } catch (Throwable) {
            return null;
        }
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
