<?php

namespace PluggedHost\Economy\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Services\MembershipService;
use Throwable;

/**
 * plugged.host Plus and PlusPlus (MembershipService): the scoped service, and "no ads anywhere"
 * for PlusPlus, registered with plugged-host-ads so its one exemption check decides it for
 * banners, the social bar, the popunder, the start gate and the ad-block detector at once.
 * The start queue asks for a priority level instead; an ad exemption never skips the queue.
 *
 * Also keeps the old Token Store address working: /token-store moved to /plus (PlusPage).
 */
class MembershipServiceProvider extends ServiceProvider
{
    private const ADS_DISPLAY = 'PluggedHost\\Ads\\Services\\AdDisplayService';

    public function register(): void
    {
        $this->app->scoped(MembershipService::class);
    }

    public function boot(): void
    {
        Route::redirect('/token-store', '/plus', 301)->name('plugged-host-economy.token-store');

        $display = self::ADS_DISPLAY;

        if (!Plugins::available($display) || !method_exists($display, 'registerExemptionCheck')) {
            return;
        }

        try {
            // Handed whoever is being rendered for, not always the signed-in user.
            $display::registerExemptionCheck(
                static fn (mixed $user): bool => $user instanceof User && app(MembershipService::class)->hasPlusPlus($user),
            );
        } catch (Throwable) {
            // A refused registration shows ads to someone who paid not to see them; a thrown
            // boot is an outage.
        }
    }
}
