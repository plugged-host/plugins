<?php

namespace PluggedHost\Core;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Actions\AssociateAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use PluggedHost\Core\Filament\App\Pages\WelcomeTourPage;
use PluggedHost\Core\Filament\HiddenNavigationManager;
use PluggedHost\Core\Filament\ServerNavigation;
use PluggedHost\Core\Http\Middleware\EnsureRememberMeCookie;
use PluggedHost\Core\Http\Middleware\RedirectToSuspendedPage;
use PluggedHost\Core\Http\Middleware\RequirePanelTour;
use Throwable;

class PluggedHostCorePlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-core';
    }

    public function register(Panel $panel): void
    {
        $panel->topbar(true);

        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Core\\Filament\\$id\\Pages");
        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "PluggedHost\\Core\\Filament\\$id\\Resources");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Core\\Filament\\$id\\Widgets");
        $panel->discoverClusters(plugin_path($this->getId(), "src/Filament/$id/Clusters"), "PluggedHost\\Core\\Filament\\$id\\Clusters");

        // Sign-in itself is upstream's, or noprofit.space's when plugged-host-noprofit is enabled
        // (it replaces the login routes and adds its own link check to this middleware list).
        $panel->authMiddleware([
            EnsureRememberMeCookie::class,
        ]);

        if ($panel->getId() === 'server') {
            // The collapsed group ServerNavigation folds the rarely used server pages into.
            $panel->navigationGroups([ServerNavigation::moreGroup()]);

            // A suspended server's pages show why it is suspended (SuspendedServerPage).
            $panel->tenantMiddleware([
                RedirectToSuspendedPage::class,
            ]);
        }

        if ($panel->getId() === 'app') {
            // The welcome-tour gate. authMiddleware(), not pushMiddlewareToGroup('web'):
            // PanelProvider gives each panel an explicit middleware list that never includes
            // the `web` group, so a gate pushed there reaches ~20 non-panel routes and no page
            // anyone browses. See RequirePanelTour for the rest of that argument.
            //
            // Appended, so it runs after Authenticate — and not persistent, so
            // POST /livewire/update (which the tour's own buttons use) never sees it.
            $panel->authMiddleware([
                RequirePanelTour::class,
            ]);
        }

        if (in_array($panel->getId(), ['app', 'server'], true)) {
            // Getting back in afterwards, from the account menu on both panels people browse
            // (the tour's last step, on a server's console, points at it). The URL is resolved
            // in a closure because routes do not exist yet at panel-build time, and
            // restartUrl() returns null rather than throwing — a user menu that throws takes
            // the sidebar footer down on every page. It is an absolute app-panel URL, so it
            // works from the server panel too.
            $panel->userMenuItems([
                Action::make('plugged_host_tour')
                    ->label(fn () => trans('plugged-host-core::strings.tour.menu'))
                    ->icon('tabler-compass')
                    ->url(fn () => WelcomeTourPage::restartUrl())
                    ->visible(fn () => WelcomeTourPage::restartUrl() !== null),
            ]);
        }
    }

    /**
     * Undo upstream's icon-only action styling, on the two panels end users actually browse.
     *
     * app/Providers/Filament/FilamentServiceProvider.php calls ->hiddenLabel() unconditionally on
     * six Action subclasses, and ->iconButton() on nearly every action whenever the user has
     * CustomizationKey::ButtonStyle — which defaults to true, so every account ships icon-only and
     * support ends up saying "the third square from the left".
     *
     * Four things make this safe, and each one is why a line below is shaped the way it is:
     *
     * 1. isImportant: true is mandatory. ComponentManager::configure() walks the class hierarchy
     *    twice — every normal configureUsing closure (ancestors first, so a normal closure of ours
     *    on Action would run *before* DeleteAction's own), then every important one. So a single
     *    important closure on Filament\Actions\Action lands after all six subclass closures, and
     *    after the setUp() of a custom Action subclass.
     * 2. Only the six subclasses get their label back. Upstream's generic closure never hides a
     *    label — it only swaps the view for one that ignores it — so nothing else needs touching.
     * 3. An action whose label is hidden is left completely alone: DeleteIcon, UploadIcon,
     *    RotateDatabasePasswordAction and ExportScheduleAction hide theirs in setUp() and lean on
     *    a tooltip instead, and turning those into labelless buttons would swap a bare icon for a
     *    solid square with no affordance at all. hasLabelHidden() rather than isLabelHidden(),
     *    because the latter evaluates a closure that may want a $record we do not have here.
     * 4. The reversal clears the view instead of replacing it. Filament resolves the right view per
     *    surface through defaultView() — BUTTON_VIEW on a page, LINK_VIEW in a table row,
     *    GROUPED_VIEW inside a ⋮ menu — and an explicit view outranks all three. Calling button()
     *    would render full buttons inside dropdowns and table rows.
     *
     * Definition-site fluent calls still win over all of it, because this happens inside make(),
     * before the chain: the file-manager toolbar, the schedule condition rows and Filament's own
     * Repeater/KeyValue controls keep their icons with no exclusion list to maintain here.
     *
     * The per-user escape hatch is untouched: the Button style toggle on the profile page still
     * flips upstream's behaviour, and this only reverses the icon-only half of it.
     */
    public function boot(Panel $panel): void
    {
        // The admin panel stays as upstream ships it: it is dense, the people in it know it, and
        // every row there already carries an ⋮ menu with words in it.
        if (!in_array($panel->getId(), ['app', 'server'], true)) {
            return;
        }

        Action::configureUsing(function (Action $action): void {
            $hiddenByUpstream = $action instanceof AssociateAction
                || $action instanceof CreateAction
                || $action instanceof DeleteAction
                || $action instanceof DissociateAction
                || $action instanceof EditAction
                || $action instanceof ViewAction;

            if ($hiddenByUpstream) {
                $action->hiddenLabel(false);
                $action->tooltip(null); // it was fn () => $action->getLabel(); now that is visible
            }

            if ($action->hasLabelHidden()) {
                return;
            }

            if (!$action->isIconButton()) {
                return;
            }

            // $view is a typed, uninitialised property that getView() probes with isset(), so
            // unsetting it is the documented "no explicit view" state — there is just no public
            // setter for it, and view(null) is a deliberate no-op. Bound closure because the
            // property is protected. If Filament ever renames it this degrades to "buttons stay
            // icon-only", which is cosmetic rather than a break.
            (function (): void {
                unset($this->view);
            })->call($action);

            $action->iconSize(null); // undo iconSize(ExtraLarge); XL beside text is oversized
        }, isImportant: true);
    }

    public function getSettingsFormData(): array
    {
        $data = [
            'tour_enabled' => (bool) config('plugged-host-core.tour.enabled', true),
            'tour_version' => (string) config('plugged-host-core.tour.version', '2'),
        ];

        // One Select per panel, because a navigation key is a class name and a class only
        // ever belongs to one panel. Split the single stored string across them by asking
        // each panel which classes it owns; anything stored for a panel that no longer
        // exists (a removed plugin) is preserved by saveSettings() rather than silently
        // dropped, so uninstalling and reinstalling a plugin keeps its entry hidden.
        $hidden = HiddenNavigationManager::configured();

        foreach (Filament::getPanels() as $panel) {
            $owned = array_keys($this->navigationOptions($panel));

            $data['hidden_navigation_' . $panel->getId()] = array_values(array_intersect($hidden, $owned));
        }

        return $data;
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('plugged-host-core::strings.settings.section_tour'))
                ->description(trans('plugged-host-core::strings.settings.section_tour_hint'))
                ->columns(2)
                ->schema([
                    Toggle::make('tour_enabled')
                        ->label(trans('plugged-host-core::strings.settings.tour_enabled'))
                        ->helperText(trans('plugged-host-core::strings.settings.tour_enabled_hint'))
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => (bool) config('plugged-host-core.tour.enabled', true)),
                    TextInput::make('tour_version')
                        ->label(trans('plugged-host-core::strings.settings.tour_version'))
                        ->helperText(trans('plugged-host-core::strings.settings.tour_version_hint'))
                        ->required()
                        ->maxLength(64)
                        ->default(fn () => (string) config('plugged-host-core.tour.version', '2')),
                ]),
            Section::make('Sidebar visibility')
                ->description('Hide navigation entries you do not want users to see. The pages stay reachable by URL — this only removes them from the sidebar.')
                ->columns()
                ->schema($this->sidebarVisibilityFields()),
        ];
    }

    /**
     * One multiple-Select per panel, listing that panel's navigable pages and resources.
     *
     * Options are enumerated at render time rather than hard-coded, so the list stays
     * correct as pages come and go — including pages added by other plugins.
     *
     * @return array<int, Select>
     */
    protected function sidebarVisibilityFields(): array
    {
        $fields = [];

        foreach (Filament::getPanels() as $panel) {
            $options = $this->navigationOptions($panel);

            if ($options === []) {
                continue;
            }

            $id = $panel->getId();

            $fields[] = Select::make('hidden_navigation_' . $id)
                ->label(str($id)->title() . ' panel')
                ->options($options)
                ->multiple()
                ->searchable()
                ->preload()
                ->placeholder('Nothing hidden')
                ->default(fn () => array_values(array_intersect(
                    HiddenNavigationManager::configured(),
                    array_keys($options),
                )));
        }

        return $fields;
    }

    /**
     * The navigable pages and resources of one panel, as class name => label.
     *
     * @return array<class-string, string>
     */
    protected function navigationOptions(Panel $panel): array
    {
        $options = [];

        foreach ([...$panel->getPages(), ...$panel->getResources()] as $class) {
            // The combined Settings page replaces these outright; offering them here would
            // imply they can be shown again, which HiddenNavigationManager does not allow.
            if (in_array($class, HiddenNavigationManager::REPLACED, true)) {
                continue;
            }

            if (!$this->registersNavigation($class)) {
                continue;
            }

            $options[$class] = $this->navigationLabel($class);
        }

        asort($options);

        return $options;
    }

    /**
     * Whether a page/resource puts itself in the sidebar at all.
     *
     * Evaluated in the admin panel, where a server-panel page sees a null tenant — that is
     * exactly how ServerInstallingPage correctly falls out of this list. A class that
     * *throws* is kept: not being able to answer is not the same as answering no, and a
     * missing option would be an entry an admin can never hide.
     *
     * @param  class-string  $class
     */
    protected function registersNavigation(string $class): bool
    {
        if (!method_exists($class, 'shouldRegisterNavigation')) {
            return false;
        }

        try {
            return (bool) $class::shouldRegisterNavigation();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The label Filament would show in the sidebar, or a readable fallback.
     *
     * getNavigationLabel() can reach for the tenant (server-panel pages) or the model
     * (resources), neither of which is guaranteed here, so a failure degrades to the class
     * name rather than taking down the settings page.
     *
     * @param  class-string  $class
     */
    protected function navigationLabel(string $class): string
    {
        try {
            $label = $class::getNavigationLabel();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        } catch (Throwable) {
            // Fall through to the class name.
        }

        return (string) str(class_basename($class))
            ->kebab()
            ->replace('-', ' ')
            ->ucwords();
    }

    public function saveSettings(array $data): void
    {
        $environment = [
            'TOUR_ENABLED' => $data['tour_enabled'] ? 'true' : 'false',
            'TOUR_VERSION' => (string) $data['tour_version'],
            'NAV_HIDDEN_ITEMS' => implode(',', $this->hiddenNavigationFromForm($data)),
        ];

        $this->writeToEnvironment($environment);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * Collapse the per-panel selects back into the single comma-joined string .env holds.
     *
     * Entries that no panel currently claims are carried through untouched. They are almost
     * always a page from a plugin that is disabled right now: dropping them would quietly
     * un-hide that page the moment it came back, and an admin editing an unrelated setting
     * is not asking for that.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function hiddenNavigationFromForm(array $data): array
    {
        $owned = [];
        $selected = [];

        foreach (Filament::getPanels() as $panel) {
            $key = 'hidden_navigation_' . $panel->getId();

            $owned = [...$owned, ...array_keys($this->navigationOptions($panel))];

            if (array_key_exists($key, $data)) {
                $selected = [...$selected, ...array_filter((array) $data[$key], 'is_string')];
            }
        }

        $orphans = array_diff(HiddenNavigationManager::configured(), $owned);

        return array_values(array_unique([...$selected, ...$orphans]));
    }
}
