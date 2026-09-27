<?php

namespace PluggedHost\ThemeV2\Filament\Pages;

use App\Filament\Pages\Auth\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * The panel profile page, registered as the panels' profile() page and as two Livewire
 * component aliases in PluggedHostThemeV2ServiceProvider.
 *
 * Sign-in details live on noprofit.space: username, email, password, avatar, Discord,
 * passkeys, authenticator codes and deleting the account are all managed there (the
 * "Manage on noprofit.space" header action links to it). So only the panel's own tabs stay:
 * API keys, SSH keys, activity and customization, with language and time zone moved out of
 * the removed Account tab into Customization.
 *
 * Tabs are picked by upstream's translation keys rather than their Filament keys, which are
 * derived from the translated label.
 */
class ThemeAwareEditProfile extends EditProfile
{
    private const KEPT_TABS = ['api_keys', 'ssh_keys', 'activity', 'customization'];

    private const MOVED_FIELDS = ['language', 'timezone'];

    public function mount(): void
    {
        if (Filament::getCurrentPanel()?->getId() !== 'app') {
            $this->redirect(static::getUrl(panel: 'app'));

            return;
        }

        parent::mount();
    }

    /** @return Tab[] */
    protected function getDefaultTabs(): array
    {
        $tabs = [];
        $moved = [];

        foreach (parent::getDefaultTabs() as $tab) {
            $label = (string) $tab->getLabel();

            if ($label === trans('profile.tabs.account')) {
                $moved = $this->movedFields($tab);

                continue;
            }

            foreach (self::KEPT_TABS as $key) {
                if ($label === trans("profile.tabs.{$key}")) {
                    $tabs[$key] = $tab;
                }
            }
        }

        if ($moved !== [] && isset($tabs['customization'])) {
            $customization = $tabs['customization'];
            $current = $customization->getDefaultChildComponents();

            $customization->schema([
                Section::make(trans('plugged-host-theme-v2::strings.profile.language_time'))
                    ->collapsible()
                    ->schema($moved),
                ...(is_array($current) ? $current : [$current]),
            ]);
        }

        return array_values($tabs);
    }

    /**
     * @return Field[]
     */
    private function movedFields(Tab $account): array
    {
        $components = $account->getDefaultChildComponents();

        return array_values(array_filter(
            is_array($components) ? $components : [],
            fn ($component): bool => $component instanceof Field && in_array($component->getName(), self::MOVED_FIELDS, true),
        ));
    }
}
