<?php

namespace PluggedHost\Core\Services;

use App\Services\Helpers\LanguageService;
use PluggedHost\Core\Support\Languages;

/**
 * Pelican's language list, cut down to the allowed languages (Support\Languages). Bound in place
 * of LanguageService, so the profile page, Admin › Users and the installer all offer the same
 * list. With a list set, Pelican's "Update On Crowdin" hint under the picker is gone too: it shows
 * for every language that is not English, and the admin chose these on purpose.
 */
class LimitedLanguageService extends LanguageService
{
    public function isLanguageTranslated(string $countryCode = 'en'): bool
    {
        return Languages::allowed() !== [] || parent::isLanguageTranslated($countryCode);
    }

    /**
     * @return array<array-key, string>
     */
    public function getAvailableLanguages(string $path = 'lang'): array
    {
        $all = parent::getAvailableLanguages($path);
        $allowed = Languages::allowed();

        return $allowed === [] ? $all : array_intersect_key($all, array_flip($allowed));
    }

    /**
     * Every language installed, for the settings page's picker.
     *
     * @return array<array-key, string>
     */
    public function installedLanguages(): array
    {
        return parent::getAvailableLanguages();
    }
}
