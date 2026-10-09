<?php

namespace PluggedHost\Core\Services;

use App\Extensions\OAuth\OAuthService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use PluggedHost\Core\Contracts\AccountPortal;
use Throwable;

/**
 * The panel as its own account portal: the profile page, and Discord linked through Pelican's
 * Discord OAuth (Admin › Settings › OAuth; the Discord application needs
 * {APP_URL}/auth/oauth/callback/discord as a redirect).
 */
final class PanelAccountPortal implements AccountPortal
{
    public function external(): bool
    {
        return false;
    }

    public function name(): string
    {
        return (string) config('app.name', 'the panel');
    }

    public function manageUrl(?string $page = null): ?string
    {
        try {
            return Filament::getPanel('app')->getProfileUrl();
        } catch (Throwable) {
            return null;
        }
    }

    public function discordLinkUrl(): ?string
    {
        try {
            if (!Route::has('auth.oauth.redirect') || !app(OAuthService::class)->get('discord')?->isEnabled()) {
                return null;
            }

            return route('auth.oauth.redirect', ['driver' => 'discord']);
        } catch (Throwable) {
            return null;
        }
    }
}
