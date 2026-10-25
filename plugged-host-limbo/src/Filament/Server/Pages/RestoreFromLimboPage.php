<?php

namespace PluggedHost\Limbo\Filament\Server\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Limbo\Services\LimboPurge;
use PluggedHost\Limbo\Services\LimboService;
use Throwable;

/**
 * The restore interstitial for a hibernated server (WP5). Shows what happened, the token cost,
 * the balance, and one button. Owner-only — a subuser with start permission has no business
 * spending the owner's tokens. Registered as an ad interstitial route by LimboPluginProvider.
 *
 * It must work on a SUSPENDED server, so it does NOT go through AuthenticateServerAccess's
 * normal gate; it lives on the server panel and does its own owner check (fact 15).
 */
class RestoreFromLimboPage extends Page
{
    protected static ?string $slug = 'limbo';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'plugged-host-limbo::restore';

    public ?int $cost = null;

    public ?string $enteredAt = null;

    /** When the server is deleted unless restored first (LimboPurge), for humans. */
    public ?string $deletesAt = null;

    /** The move back is in flight: the page shows progress instead of the button. */
    public bool $restoring = false;

    /** Its own location has no room: the owner picks one of theirs instead. */
    public bool $needsLocation = false;

    /** The picked location code, when $needsLocation. */
    public ?string $location = null;

    /** Whether the current request is this page — for the ads interstitial registry (WP4/WP5). */
    public static function isCurrentRoute(): bool
    {
        try {
            if (Filament::getCurrentPanel()?->getId() !== 'server') {
                return false;
            }

            return request()->routeIs(static::getRouteName());
        } catch (Throwable) {
            return false;
        }
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();
        $user = auth()->user();

        if (!$server instanceof Server || $user === null) {
            return false;
        }

        // Owner-only.
        if ((int) $server->owner_id !== (int) $user->id) {
            return false;
        }

        $limbo = app(LimboService::class);
        $state = $limbo->state($server);

        return $state !== null && ($state->state === 'restoring' || $limbo->isParked($server, $state));
    }

    public function mount(): void
    {
        $server = Filament::getTenant();
        $state = $server instanceof Server ? app(LimboService::class)->state($server) : null;

        if ($state !== null) {
            $this->cost = app(LimboService::class)->cost($state);
            $this->enteredAt = $state->limbo_at?->diffForHumans();
            $this->deletesAt = LimboPurge::deletesAt($state)?->diffForHumans();
            $this->restoring = $state->state === 'restoring';
            $this->needsLocation = !$this->restoring && !app(LimboService::class)->canRestoreHome($server);
        }
    }

    /** @return array<int, array<string, mixed>> the owner's unlocked locations with room */
    public function locations(): array
    {
        $server = Filament::getTenant();

        return $server instanceof Server && $this->needsLocation ? app(LimboService::class)->restoreLocations($server) : [];
    }

    public function pick(string $code): void
    {
        if (in_array($code, array_column($this->locations(), 'code'), true)) {
            $this->location = $code;
        }
    }

    /**
     * Polled while the move back runs. Once it has settled, open the Console: it is the server
     * again, or (a failed move, refunded) the Console sends the owner straight back here.
     */
    public function checkRestore(): void
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server || app(LimboService::class)->state($server)?->state === 'restoring') {
            return;
        }

        $this->redirect(\App\Filament\Server\Pages\Console::getUrl(panel: 'server', tenant: $server));
    }

    public function getTitle(): string
    {
        return trans('plugged-host-limbo::strings.title');
    }

    public function balance(): int
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';
        $user = auth()->user();

        if (!Plugins::available($service) || $user === null) {
            return 0;
        }

        try {
            return (int) app($service)->balance($user);
        } catch (Throwable) {
            return 0;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restore')
                ->label(trans('plugged-host-limbo::strings.restore'))
                ->visible(fn () => !$this->restoring)
                ->disabled(fn () => $this->needsLocation && $this->location === null)
                ->requiresConfirmation()
                ->modalDescription(fn () => trans('plugged-host-limbo::strings.confirm', ['cost' => $this->cost]))
                ->action('restore'),
        ];
    }

    public function restore(): void
    {
        $server = Filament::getTenant();
        $user = auth()->user();

        if (!$server instanceof Server || $user === null) {
            return;
        }

        $result = app(LimboService::class)->restore($user, $server, $this->needsLocation ? $this->location : null);

        if ($result['ok'] ?? false) {
            Notification::make()->title(trans('plugged-host-limbo::strings.restored'))->success()->send();
            $this->restoring = true;

            return;
        }

        $reason = $result['reason'] ?? 'error';
        Log::warning(sprintf('plugged-host-limbo: restore of server %d refused: %s', $server->id, $reason));

        // Its own location filled up since the page opened, or the picked one just did.
        if ($reason === 'no_destination' || $reason === 'location_full') {
            $this->needsLocation = true;
            $this->location = null;
        }

        $key = match ($reason) {
            'insufficient_tokens' => 'plugged-host-limbo::strings.insufficient',
            'no_destination', 'location_full' => 'plugged-host-limbo::strings.location_full',
            default => 'plugged-host-limbo::strings.failed',
        };

        Notification::make()->title(trans($key, ['cost' => $result['cost'] ?? $this->cost]))->danger()->send();
    }
}
