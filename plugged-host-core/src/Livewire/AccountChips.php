<?php

namespace PluggedHost\Core\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use PluggedHost\Core\Services\AccountSummary;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;
use Throwable;

/**
 * The tokens / credits / resources chips in the topbar.
 *
 * A Livewire component rather than a render-hook fragment, and that is not a style choice.
 * Filament wraps `.fi-topbar-end` in `x-persist`, and Livewire's navigate plugin implements
 * persistence by *re-inserting the old DOM node into the new page* — putPersistantElementsBack()
 * ends in `i.replaceWith(old)` (livewire.esm.js). Static markup placed in that container would
 * therefore keep whatever numbers it was first rendered with for the rest of the SPA session,
 * and only a hard reload would correct it. A Livewire component survives because the persisted
 * node carries a live component that can still poll — the same reason Filament's notification
 * bell is a component and not a partial.
 *
 * Numbers come from AccountSummary, which is scoped() and does the whole thing in one aggregate
 * query, because this renders on every page in two panels.
 */
class AccountChips extends Component
{
    /**
     * Only the panels an end user actually lives in. The admin panel has its own dense
     * information layout and does not need a wallet in the corner.
     *
     * @var string[]
     */
    public const PANELS = ['app', 'server'];

    public function render(): View
    {
        return view('plugged-host-core::livewire.account-chips', [
            'summary' => app(AccountSummary::class)->forUser(auth()->user()),
            'storeUrl' => $this->storeUrl(),
            'storeLabel' => $this->storeLabel(),
            'suffix' => config('panel.use_binary_prefix') ? 'MiB' : 'MB',
        ]);
    }

    /**
     * Format a mebibyte figure the way a person would say it: GB past a gigabyte, MB below.
     *
     * Public so the view can call it on both halves of a used/limit pair without repeating
     * the rounding rules, and so a probe can assert them directly.
     */
    public function size(int $amount, string $suffix): string
    {
        if ($amount >= 1024) {
            $value = $amount / 1024;
            $decimals = $value >= 100 ? 0 : 1;

            return number_format($value, $decimals) . ' ' . ($suffix === 'MiB' ? 'GiB' : 'GB');
        }

        return number_format($amount) . ' ' . $suffix;
    }

    /**
     * `0` means unlimited for cpu/memory/disk, `null` means unlimited for server slots —
     * both render as ∞ rather than as a misleading "0".
     */
    public function limit(int|null $limit, string $suffix, bool $isSize = true): string
    {
        if ($limit === null || $limit === 0) {
            return '∞';
        }

        return $isSize ? $this->size($limit, $suffix) : (string) $limit;
    }

    /**
     * The store lives in plugged-host-economy, which may be uninstalled; a missing store means
     * the credits chip is plain text instead of a link, never a broken route.
     */
    private function storeUrl(): ?string
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

    /**
     * What to call that page on the button next to the chips.
     *
     * Read off the page rather than out of this plugin's own strings, because the label is
     * the page's to decide and this is the second place it appears — the rename to
     * "Economy" was a label change, and a topbar button still saying "Resource Store"
     * would be the exact confusion this pass is about. `chips.store` is the fallback for a
     * page that resolves but cannot answer; with economy uninstalled `storeUrl()` is null
     * and no label is needed at all.
     */
    private function storeLabel(): string
    {
        if (class_exists(ResourceStorePage::class)) {
            try {
                $label = ResourceStorePage::getNavigationLabel();

                if (trim($label) !== '') {
                    return $label;
                }
            } catch (Throwable) {
                // Fall through to this plugin's own word for it.
            }
        }

        return trans('plugged-host-core::strings.chips.store');
    }

    /**
     * True when this component has anything at all to draw.
     *
     * Mirrors UserResourceLimitsOverview::canView(): a user with no RoleResourceLimits profile
     * has no quota to report, and showing three empty chips to them would be worse than
     * showing nothing.
     */
    public static function shouldRender(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        return in_array(Filament::getCurrentOrDefaultPanel()?->getId(), self::PANELS, true);
    }
}
