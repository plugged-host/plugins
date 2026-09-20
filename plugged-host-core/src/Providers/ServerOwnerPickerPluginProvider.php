<?php

namespace PluggedHost\Core\Providers;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\ServiceProvider;

/**
 * Makes the Owner picker on Admin Area → Servers → Create / Edit actually usable.
 *
 * Upstream builds that field as
 *
 *     Select::make('owner_id')->preload()->relationship('user', 'username')
 *         ->searchable(['username', 'email'])->getOptionLabelFromRecordUsing(…)
 *
 * in both app/Filament/Admin/Resources/Servers/Pages/{CreateServer,EditServer}.php, and leaves
 * Filament's two defaults in place. On an install of any size those defaults combine into a
 * search box that looks broken:
 *
 * 1. `Select::$optionsLimit` is 50, and it caps *both* halves of the field. `preload()` means the
 *    dropdown opens on a hard `limit 50` over `users` ordered by username, and
 *    getSearchResultsFromRelationship() applies the same 50 to every search. This panel has 394
 *    users, so the list you are handed is 13% of them with nothing saying so, and a search that
 *    genuinely matches more than 50 is silently cut.
 * 2. `CanBeSearchable::$searchDebounce` is 1000 ms — and the select's JS shows no loading state
 *    until that timer fires (`handleSearch()` in filament/forms/dist/components/select.js calls
 *    showLoadingState() *inside* the setTimeout callback). Because the field has server-side
 *    search results, it also does no local filtering in the meantime. So for a full second after
 *    the last keystroke the dropdown just sits there showing the unfiltered preloaded 50 — which
 *    reads as "typing does nothing", and if you keep typing it never resolves at all.
 *
 * Both are set here rather than left to the definition site because `app/` is upstream and stays
 * byte-identical to the release tarball.
 *
 * What is deliberately *not* fixed here: `preload()` itself, which is the root of (1). A
 * configureUsing() closure runs inside `Select::make()` — ComponentManager::configure() is called
 * from make(), before the fluent chain — so it can only set things the chain does not set again.
 * Upstream never calls `optionsLimit()` or `searchDebounce()`, which is why those two stick;
 * `->preload()` assigns a literal `true` after this closure has run, so `preload(false)` here
 * would be discarded. Raising the limit is therefore the whole lever available, and it buys a
 * complete list by making the preload heavier — see OPTIONS_LIMIT_MAX.
 *
 * `getName()` is the only scope available: a Select's container (and so `getLivewire()`) is not
 * attached until after make(), unlike Table::configureUsing() in UserCreatableServersPluginProvider
 * which can test the Livewire component. It is exact anyway — `owner_id` appears on no other
 * Select in `app/` or in any plugin, so this closure touches those two fields and nothing else.
 */
class ServerOwnerPickerPluginProvider extends ServiceProvider
{
    /**
     * Never below Filament's own default: this provider is here to widen the field, and an
     * install with three users should not end up with a *narrower* list than upstream gives it.
     */
    private const OPTIONS_LIMIT_MIN = 50;

    /**
     * The ceiling on that widening, and the one number here that is a judgement call rather than
     * a fact. Because `optionsLimit` is shared between preloading and searching, raising it also
     * raises what every render of these two pages loads — and with a
     * getOptionLabelFromRecordUsing() callback in play Filament hydrates full User models rather
     * than plucking two columns. 1000 is comfortably above this panel's user count, so today the
     * list is complete and the cost is one query; past 1000 the old silent truncation returns at
     * the higher mark, which is the right trade to keep an admin form from loading tens of
     * thousands of models. If it is ever reached, the real fix is for the field to stop
     * preloading, and that has to happen upstream for the reason given in the class docblock.
     */
    private const OPTIONS_LIMIT_MAX = 1000;

    /**
     * Long enough not to fire a query per keystroke, short enough to read as instant. Filament
     * uses 0 for CheckboxList (local filtering) and 1000 for everything server-backed; this sits
     * with the debounce Livewire itself defaults to for `wire:model.live.debounce`.
     */
    private const SEARCH_DEBOUNCE_MS = 250;

    public function register(): void
    {
        Select::configureUsing(function (Select $select): void {
            if ($select->getName() !== 'owner_id') {
                return;
            }

            // A closure, so the count runs only when one of these two pages actually renders the
            // field — not on every Select constructed anywhere in the panel. getOptionsLimit()
            // evaluates it at render time.
            $select->optionsLimit(fn (): int => max(
                self::OPTIONS_LIMIT_MIN,
                min(self::OPTIONS_LIMIT_MAX, User::query()->count()),
            ));

            $select->searchDebounce(self::SEARCH_DEBOUNCE_MS);
        });

        SelectFilter::configureUsing(function (SelectFilter $filter): void {
            if ($filter->getName() !== 'owner') {
                return;
            }

            $filter->optionsLimit(fn (): int => max(
                self::OPTIONS_LIMIT_MIN,
                min(self::OPTIONS_LIMIT_MAX, User::query()->count()),
            ));

            $filter->modifyFormFieldUsing(function (Select $field): Select {
                // Lock dropdown to bottom so Floating UI never flips it upward above the input
                $field->position('bottom');
                $field->searchDebounce(0);

                // Nulling out the dynamic search callback allows Filament select.js to filter
                // over the preloaded user options locally and immediately without AJAX round-trips
                // or collapsing the dropdown height.
                $field->getSearchResultsUsing(null);

                return $field;
            });
        });
    }
}
