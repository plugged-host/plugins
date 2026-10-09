<?php

namespace PluggedHost\Core\Support;

use App\Filament\Server\Pages\Console;
use App\Models\Server;
use App\Models\User;
use Throwable;

/**
 * What the welcome tour points at, in order, for one person on one page.
 *
 * Each step names the element it spotlights as a list of CSS selectors; the overlay uses the
 * first one that is on screen, and a step whose element is missing (a plugin switched off,
 * a layout change) still shows, centred, instead of pointing at nothing. Steps that cannot
 * apply to this account are left out here rather than hidden in the browser, so "Step 3 of
 * 6" always counts what the person will actually see.
 *
 * Copy lives in lang/{en,de}/strings.php under tour.panel.* and tour.server.*. Every step
 * names the thing by the label it carries on screen -- the point of the tour is that "open
 * Settings" means something afterwards.
 *
 * `final` is set on the last step only and picks its primary button: `open` goes into the
 * person's server so the second part starts right away, `create` goes to Create server
 * (there is no server yet to show), `finish` just closes.
 *
 * @phpstan-type Step array{key: string, targets: array<int, string>, sidebar: bool, heading: string, body: string, bullets: array<int, string>, final: ?string}
 */
final class PanelTourSteps
{
    /**
     * The first part, on the server list.
     *
     * @return array{steps: array<int, Step>, server: ?array{name: string, url: string}, create_url: ?string}
     */
    public static function panel(User $user): array
    {
        $server = self::firstServer($user);
        $serverUrl = null;

        if ($server !== null) {
            try {
                $serverUrl = Console::getUrl(panel: 'server', tenant: $server);
            } catch (Throwable) {
                $server = null;
            }
        }

        $createUrl = self::createUrl($user);

        $steps = [
            self::step('panel', 'welcome', []),
            self::step('panel', 'navigation', ['.fi-sidebar-nav-groups'], sidebar: true),
        ];

        if (self::hasBalance()) {
            $steps[] = self::step('panel', 'balance', ['.phv2-balance'], sidebar: true);
        }

        $steps[] = self::step('panel', 'account', ['.fi-sidebar-footer .fi-user-menu', '.fi-sidebar-footer'], sidebar: true);

        $card = $server !== null
            ? ['.phv2-server[data-server="' . self::attr((string) $server->uuid_short) . '"]', '.phv2-server', '.fi-ta-content-ctn']
            : ['.fi-ta-empty-state', '.fi-ta-content-ctn'];

        $create = $createUrl !== null
            ? ['.fi-ta-header-toolbar a[href="' . self::attr($createUrl) . '"]', '.fi-ta-header-toolbar .fi-ta-actions']
            : null;

        if ($server !== null) {
            // Someone with a server: Create server first, then their server, and the last
            // button walks straight into it.
            if ($create !== null) {
                $steps[] = self::step('panel', 'create', $create);
            }

            $steps[] = self::step('panel', 'servers', $card, final: 'open', replace: ['name' => $server->name]);
        } else {
            // A new account: where servers will show up, then the button that makes one.
            if ($create !== null) {
                $steps[] = self::step('panel', 'empty', $card);
                $steps[] = self::step('panel', 'create_first', $create, final: 'create');
            } else {
                $steps[] = self::step('panel', 'empty', $card, final: 'finish');
            }
        }

        return [
            'steps' => $steps,
            'server' => $server !== null && $serverUrl !== null ? ['name' => (string) $server->name, 'url' => $serverUrl] : null,
            'create_url' => $createUrl,
        ];
    }

    /**
     * The second part, on a server's console.
     *
     * @return array<int, Step>
     */
    public static function server(Server $server): array
    {
        $steps = [
            self::step('server', 'console', ['.fi-console-page .fi-wi:has(#terminal)', '#terminal']),
            self::step('server', 'power', ['.fi-console-page .fi-header-actions-ctn']),
            self::step('server', 'address', ['.fi-console-page .fi-wi-stats-overview']),
        ];

        if (self::setupPending()) {
            $steps[] = self::step('server', 'setup', ['.fi-console-page .fi-callout']);
        }

        $steps[] = self::step(
            'server',
            self::isMinecraft($server) ? 'sidebar_minecraft' : 'sidebar',
            ['.fi-sidebar-nav-groups'],
            sidebar: true,
        );

        $settingsUrl = self::settingsUrl();

        if ($settingsUrl !== null) {
            $steps[] = self::step('server', 'settings', [
                '.fi-sidebar-nav a[href="' . self::attr($settingsUrl) . '"]',
            ], sidebar: true);
        }

        $steps[] = self::step('server', 'home', ['.fi-sidebar-header-logo-ctn', '.fi-sidebar-header'], sidebar: true, final: 'finish');

        return $steps;
    }

    /**
     * @param  array<int, string>  $targets
     * @param  array<string, string>  $replace
     * @return Step
     */
    private static function step(string $part, string $key, array $targets, bool $sidebar = false, ?string $final = null, array $replace = []): array
    {
        $prefix = "plugged-host-core::strings.tour.{$part}.{$key}";
        $bullets = trans("{$prefix}.bullets", $replace);

        return [
            'key' => $key,
            'targets' => $targets,
            'sidebar' => $sidebar,
            'heading' => trans("{$prefix}.heading", $replace),
            'body' => trans("{$prefix}.body", $replace),
            // Absent in lang => trans() hands back the key it was given, which is not an array.
            'bullets' => is_array($bullets) ? array_values($bullets) : [],
            'final' => $final,
        ];
    }

    /**
     * The server the last step points at and walks into: the person's own first, else one
     * they were added to.
     */
    private static function firstServer(User $user): ?Server
    {
        try {
            return $user->directAccessibleServers()->where('servers.owner_id', $user->id)->orderBy('servers.id')->first()
                ?? $user->directAccessibleServers()->orderBy('servers.id')->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Create server's URL when the toolbar actually shows the button: CreateServerAction is
     * visible to anyone the create page lets in, which is this same check.
     */
    private static function createUrl(User $user): ?string
    {
        $page = 'PluggedHost\\Servers\\Filament\\App\\Pages\\CreateServerPage';
        $allowance = 'PluggedHost\\Servers\\Support\\AccountAllowance';

        if (!Plugins::available($page) || !Plugins::available($allowance)) {
            return null;
        }

        try {
            return $allowance::forUser($user) !== null ? $page::getUrl(panel: 'app') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function hasBalance(): bool
    {
        $balance = 'PluggedHost\\ThemeV2\\Livewire\\ThemeV2Balance';

        try {
            return Plugins::available($balance) && $balance::shouldMount();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The Minecraft setup guide's callout is on this console (setup is an offer here, not a
     * requirement -- a required one never lets the console open at all).
     */
    private static function setupPending(): bool
    {
        $page = 'PluggedHost\\Minecraft\\Filament\\Server\\Pages\\ServerOnboardingPage';

        try {
            return Plugins::available($page) && $page::canAccess();
        } catch (Throwable) {
            return false;
        }
    }

    private static function settingsUrl(): ?string
    {
        $page = 'PluggedHost\\Servers\\Filament\\Server\\Pages\\ServerSettings';

        try {
            return Plugins::available($page) && $page::canAccess() ? $page::getUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isMinecraft(Server $server): bool
    {
        return in_array('minecraft', (array) ($server->egg?->tags ?? []), true);
    }

    /**
     * A value for a double-quoted CSS attribute selector.
     */
    private static function attr(string $value): string
    {
        return addcslashes($value, "\"\\\n");
    }
}
