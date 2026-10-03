<?php

namespace PluggedHost\ThemeV2\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;
use PluggedHost\Economy\Filament\App\Pages\EarnTokensPage;
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
 * Everything is styled from `--phv2-*` custom properties rather than Tailwind `dark:` variants:
 * Filament only puts `.dark` on `<html>` client-side when the stored preference resolves to
 * dark, and Theme v2's sidebar is dark regardless.
 *
 * Three things, each linking to where it is topped up: the usage card (what the account's
 * servers use of its plan) to the Resource Store, the token balance to the Tokens page and the
 * credit balance to the Resource Store.
 *
 * A Livewire component rather than a static fragment, for one reason: `wire:navigate` swaps
 * the body, so a fragment would be re-rendered on every page change and would look correct —
 * but buying something is a Livewire action on the *same* page, and a fragment would then sit
 * there showing the pre-purchase balance until the user navigated. `wire:poll.60s` in the view
 * closes that window. (There is no `x-persist` anywhere on a Theme v2 page — Filament puts it
 * on `.fi-topbar-end`, which this theme removes — so this component is not *forced* to be
 * Livewire to avoid freezing. It is Livewire because of the purchase case.)
 *
 * The balances are two cheap primary-key reads done here, not taken from core's
 * `AccountSummary`, which is null for an account without a plan and would blank the balance
 * for exactly the users most likely to be topping up.
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
            'usage' => static::usage(static::resources()),
            'resourceStoreUrl' => static::pageUrl(ResourceStorePage::class),
            // The token balance opens the Tokens page (earning and history).
            'tokenStoreUrl' => static::pageUrl(EarnTokensPage::class),
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
     * The usage card's rows: what the servers use against the plan, one row per resource, plus
     * servers while there is a server limit. `full` marks a row that is at or over its limit.
     *
     * @param  ?array<string, int|null>  $resources  AccountSummary::forUser()
     * @return list<array{label: string, used: string, limit: string, percent: int, full: bool}>
     */
    private static function usage(?array $resources): array
    {
        if ($resources === null) {
            return [];
        }

        $rows = [];

        foreach ([
            'cpu' => [trans('plugged-host-theme-v2::strings.usage.cpu'), 'percent'],
            'memory' => [trans('plugged-host-theme-v2::strings.usage.memory'), 'size'],
            'disk' => [trans('plugged-host-theme-v2::strings.usage.disk'), 'size'],
        ] as $key => [$label, $kind]) {
            $used = (int) ($resources["{$key}_used"] ?? 0);
            $limit = (int) ($resources["{$key}_limit"] ?? 0);

            [$usedText, $limitText] = static::pair($used, $limit, $kind);

            // 0 is unlimited.
            $unlimited = $limit === 0;

            $rows[] = [
                'label' => $label,
                'used' => $usedText,
                'limit' => $limitText,
                'percent' => $unlimited ? 0 : ($limit > 0 ? (int) min(100, round($used / $limit * 100)) : 100),
                'full' => !$unlimited && $used >= $limit,
            ];
        }

        return $rows;
    }

    /**
     * "200 / 500%", "4 / 16 GB" (the unit once when both sides share it), "512 MB / 16 GB".
     * A limit of 0 is unlimited.
     *
     * @return array{0: string, 1: string}
     */
    private static function pair(int $used, int $limit, string $kind): array
    {
        if ($kind === 'percent') {
            return [(string) $used, $limit > 0 ? $limit . '%' : '∞'];
        }

        $binary = (bool) config('panel.use_binary_prefix');
        $format = fn (int $value): array => $value >= 1024
            ? [rtrim(rtrim(number_format($value / 1024, 1), '0'), '.'), $binary ? 'GiB' : 'GB']
            : [(string) $value, $binary ? 'MiB' : 'MB'];

        [$usedValue, $usedUnit] = $format($used);

        if ($limit <= 0) {
            return ["{$usedValue} {$usedUnit}", '∞'];
        }

        [$limitValue, $limitUnit] = $format($limit);

        return [
            $usedUnit === $limitUnit || $used === 0 ? $usedValue : "{$usedValue} {$usedUnit}",
            "{$limitValue} {$limitUnit}",
        ];
    }

    /**
     * Where a balance or the usage card leads, or null when that page is absent or closed to
     * this user (its currency switched off), in which case the view renders it inert.
     *
     * @param  class-string  $page
     */
    private static function pageUrl(string $page): ?string
    {
        if (!class_exists($page)) {
            return null;
        }

        try {
            return $page::canAccess() ? $page::getUrl(panel: 'app') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
