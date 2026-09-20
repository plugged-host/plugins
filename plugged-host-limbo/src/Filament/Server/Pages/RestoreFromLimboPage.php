<?php

namespace PluggedHost\Limbo\Filament\Server\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

        $state = app(LimboService::class)->state($server);

        return $state !== null && in_array($state->state, ['limbo', 'restoring'], true);
    }

    public function mount(): void
    {
        $server = Filament::getTenant();
        $state = $server instanceof Server ? app(LimboService::class)->state($server) : null;

        if ($state !== null) {
            $this->cost = app(LimboService::class)->cost($state);
            $this->enteredAt = $state->limbo_at?->diffForHumans();
        }
    }

    public function getTitle(): string
    {
        return trans('plugged-host-limbo::strings.title');
    }

    public function balance(): int
    {
        $service = 'PluggedHost\\Economy\\Services\\TokenService';
        $user = auth()->user();

        if (!class_exists($service) || $user === null) {
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
                ->icon('tabler-refresh')
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

        $result = app(LimboService::class)->restore($user, $server);

        if ($result['ok'] ?? false) {
            Notification::make()->title(trans('plugged-host-limbo::strings.restored'))->success()->send();
            $this->redirect(\App\Filament\Server\Pages\Console::getUrl(panel: 'server', tenant: $server));

            return;
        }

        $reason = $result['reason'] ?? 'error';
        $key = $reason === 'insufficient_tokens'
            ? 'plugged-host-limbo::strings.insufficient'
            : 'plugged-host-limbo::strings.failed';

        Notification::make()->title(trans($key, ['cost' => $result['cost'] ?? $this->cost]))->danger()->send();
    }
}
