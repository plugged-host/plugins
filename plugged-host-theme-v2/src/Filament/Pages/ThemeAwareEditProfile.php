<?php

namespace PluggedHost\ThemeV2\Filament\Pages;

use App\Filament\Pages\Auth\EditProfile;

/**
 * The panel profile page. It used to add a Theme switcher to the Customization tab; with
 * Theme v2 the only theme (WP2), that section is gone and this is now a plain EditProfile
 * subclass.
 *
 * The class is kept rather than deleted because it is registered as the panel's profile() page
 * and as two Livewire component aliases in PluggedHostThemeV2ServiceProvider — removing it would
 * change routing. Keeping it as an empty subclass keeps those registrations valid with no
 * behaviour of its own.
 */
class ThemeAwareEditProfile extends EditProfile
{
}
