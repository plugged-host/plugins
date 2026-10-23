<?php

namespace PluggedHost\Core\Providers;

use App\Services\Helpers\LanguageService;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use PluggedHost\Core\Services\LimitedLanguageService;
use PluggedHost\Core\Support\Languages;

/**
 * Only the allowed languages (ALLOWED_LANGUAGES, core settings › Languages): the pickers offer
 * just those, and a user whose saved language is another one sees the panel in the fallback
 * (Support\Languages::fallback()) instead. Their saved choice is left alone, so widening the list
 * again gives it back.
 */
class LanguagesPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LanguageService::class, LimitedLanguageService::class);
    }

    public function boot(): void
    {
        // LanguageMiddleware (and notifications) set the user's language through setLocale().
        Event::listen(LocaleUpdated::class, function (LocaleUpdated $event): void {
            if (!Languages::isAllowed($event->locale)) {
                app()->setLocale(Languages::fallback());
            }
        });
    }
}
