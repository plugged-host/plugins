<?php

namespace PluggedHost\ThemeV2\Support;

use App\Models\User;

/**
 * Theme v2 is now the only theme (WP2, v1.2.0). This class collapsed from a per-user
 * access/preference gate to an always-on stub, kept deliberately: five call sites across the
 * plugin (the plugin class's theme/topbar/navigation closures and the service provider's render
 * hooks) still ask it, and keeping the two methods they read avoids touching each one.
 *
 * The former nord fallback, the `admin_only` mode, the hardcoded identity allowlist, the
 * per-user `customization.theme` preference, the session toggle and the `/theme-v2/toggle`
 * route all went with nord-theme. Users whose stored `customization.theme` was `nord` are
 * simply ignored — shouldRenderTheme() no longer reads it — which is what avoids a data change.
 */
class ThemeV2Access
{
    /** Theme v2 is available to everyone. */
    public static function canAccess(?User $user = null): bool
    {
        return true;
    }

    /** Theme v2 is always the rendered theme. */
    public static function shouldRenderTheme(): bool
    {
        return true;
    }
}
