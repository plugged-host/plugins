<?php

namespace PluggedHost\ThemeV2\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\TokenService;
use PluggedHost\ThemeV2\Support\ThemeV2Access;
use Throwable;

/**
 * The sidebar's token / credit balance — UIExample's wallet pill, which sits in its header
 * immediately left of the avatar dropdown ("py-2 px-4 rounded-xl hover:bg-white/5", a wallet
 * Heroicon and `coins.toLocaleString() XPL`, the whole thing clickable through to /wallet).
 *
 * Theme v2 has no header to put that in, so it goes at the bottom of the sidebar directly
 * above the avatar row, which is the same relationship to the avatar that the reference has.
 * It is injected at SIDEBAR_FOOTER, i.e. *after* `.fi-sidebar-footer` in the DOM, and appears
 * above it because that element carries `order: 1` in theme.css.
 *
 * Why this exists at all: before it there was nowhere in Theme v2 to see a balance. The
 * numbers used to arrive with plugged-host-core's `AccountChips`, which hangs off
 * GLOBAL_SEARCH_BEFORE — a hook that only exists inside `topbar.blade.php`, and Theme v2 has
 * no topbar on any panel. An earlier pass re-injected that component here verbatim and it was
 * removed again because it rendered wrong, not because the numbers were unwanted: core's chips
 * take *all* of their dark styling from Tailwind `dark:` variants, and Filament only puts
 * `.dark` on `<html>` client-side when the stored preference resolves to dark — so on a
 * light-preferring browser they painted as pale `bg-gray-50 / text-gray-700` pills on Theme
 * v2's unconditionally dark sidebar. Everything here is styled from `--phv2-*` custom
 * properties instead, so it cannot depend on that class being present.
 *
 * A Livewire component rather than a static fragment, for one reason: `wire:navigate` swaps
 * the body, so a fragment would be re-rendered on every page change and would look correct —
 * but buying something is a Livewire action on the *same* page, and a fragment would then sit
 * there showing the pre-purchase balance until the user navigated. `wire:poll.60s` in the view
 * closes that window. (There is no `x-persist` anywhere on a Theme v2 page — Filament puts it
 * on `.fi-topbar-end`, which this theme removes — so unlike core's chips this component is not
 * *forced* to be Livewire to avoid freezing. It is Livewire because of the purchase case.)
 *
 * Deliberately not reusing core's `AccountSummary`: it returns null outright for a user with
 * no `RoleResourceLimits` profile, which would blank the balance for exactly the users most
 * likely to be topping up, and it runs a four-column aggregate over `servers` to build the
 * cpu/memory/disk figures this row never shows. Tokens and credits are two cheap primary-key
 * reads, so they are read here with the same class_exists()/try guards core uses.
 */
class ThemeV2Balance extends Component
{
    /**
     * Both panels that have a sidebar footer worth hanging this off. The admin panel is
     * excluded on purpose: it is a staff tool, and a personal wallet is not part of it.
     *
     * @var array<string>
     */
    private const PANELS = ['app', 'server'];

    /**
     * Memoised per request and keyed by user, because the same two numbers are needed twice:
     * once by shouldMount() before the component exists, and again by the mounted instance's
     * render(). Static rather than per-instance so those two share one pair of reads.
     *
     * PHP-FPM tears the process down between requests, so this never survives one — which is
     * what makes `wire:poll.60s` work: a poll is its own request and reads fresh.
     *
     * A sentinel-free `array_key_exists` check, because null is a real answer here.
     *
     * @var array<string, ?int>
     */
    private static array $memo = [];

    public function render(): View
    {
        return view('plugged-host-theme-v2::sidebar.balance', [
            'tokens' => static::tokens(),
            'credits' => static::credits(),
            'resources' => static::resources(),
            'storeUrl' => static::storeUrl(),
        ]);
    }

    /**
     * Whether the row should be mounted at all — asked by the render hook that injects it,
     * *not* by Livewire.
     *
     * This used to be `shouldRender()`, on the assumption that Livewire consults such a method
     * the way Filament consults `canView()` on a widget. It does not: there is no `shouldRender`
     * anywhere in livewire/livewire or filament/*, so every guard in it was dead code and the
     * balance mounted on the admin panel as well. A Livewire component also cannot decline its
     * *initial* render — `skipRender()` only keeps a previous DOM — so the gate genuinely has to
     * live outside the component, and the hook is the only place that qualifies.
     *
     * Null and 0 are different answers and the view renders them differently: null means "this
     * currency does not exist in this install" and drops the pill, 0 means "you have none" and
     * shows it. Nothing here may turn one into the other — hence `!== null` rather than a
     * truthiness test, which would hide a legitimately empty wallet.
     */
    public static function shouldMount(): bool
    {
        if (!ThemeV2Access::shouldRenderTheme()) {
            return false;
        }

        if (!in_array(Filament::getCurrentPanel()?->getId(), self::PANELS, true)) {
            return false;
        }

        if (!user()) {
            return false;
        }

        return static::tokens() !== null || static::credits() !== null || static::resources() !== null;
    }

    private static function resources(): ?array
    {
        $user = user();
        if (!$user) {
            return null;
        }

        if (class_exists(\PluggedHost\Core\Services\AccountSummary::class)) {
            try {
                return app(\PluggedHost\Core\Services\AccountSummary::class)->forUser($user);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * `use`-importing a class from plugged-host-economy is safe even when that plugin is not
     * installed — a use statement is an alias, not a load — so long as every touch is behind
     * class_exists(). This mirrors PluggedHost\Core\Services\AccountSummary::tokens().
     */
    private static function tokens(): ?int
    {
        return static::remember('tokens', function (): ?int {
            if (!class_exists(TokenService::class)) {
                return null;
            }

            try {
                $tokens = app(TokenService::class);

                return $tokens->isEnabled() ? $tokens->balance(user()) : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    private static function credits(): ?int
    {
        return static::remember('credits', function (): ?int {
            if (!class_exists(UserCredits::class)) {
                return null;
            }

            try {
                return UserCredits::forUser(user())?->credits;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * @param  callable(): ?int  $read
     */
    private static function remember(string $key, callable $read): ?int
    {
        $key .= '.' . (user()?->getKey() ?? 'guest');

        if (!array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $read();
        }

        return self::$memo[$key];
    }

    /**
     * Where the pill leads, standing in for UIExample's /wallet. Null when the economy plugin
     * is absent, in which case the view renders a plain div — a balance the user cannot act on
     * is still worth showing, but it must not look clickable.
     */
    private static function storeUrl(): ?string
    {
        if (!class_exists(ResourceStorePage::class)) {
            return null;
        }

        try {
            return ResourceStorePage::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
