<?php

namespace PluggedHost\Servers\Filament\Components\Actions;

use PluggedHost\Servers\Filament\App\Pages\CreateServerPage;
use PluggedHost\Servers\Support\AccountAllowance;
use Filament\Actions\Action;

class CreateServerAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_server';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn () => trans('plugged-host-servers::strings.create_server'));

        // The icon is not decoration. Upstream's Action::configureUsing() in FilamentServiceProvider
        // turns *every* non-excluded action into iconButton() whenever the acting user has
        // CustomizationKey::ButtonStyle set -- and that key defaults to true, so it is on for
        // practically everyone. An icon button with no icon renders as a blank clickable square,
        // with the label surviving only in title/aria-label. With an icon declared both styles
        // render: icon-only plus tooltip when the preference is on, icon plus label when it is off.
        $this->icon('tabler-plus');

        // …and the tooltip only when the label is not on screen. PluggedHostCorePlugin::boot()
        // reverses the icon-only styling on the app and server panels, so this normally renders as
        // "＋ Create server" and a tooltip would just repeat the words next to the cursor. The
        // closure is evaluated at render time, so this stays correct either way -- including on a
        // panel where the reversal does not apply.
        $this->tooltip(fn (): ?string => $this->isIconButton() || $this->isLabelHidden()
            ? trans('plugged-host-servers::strings.create_server')
            : null);

        // Shown to anyone the create page lets in (canAccess() is the same check), and never
        // greyed out: someone at their limit still gets to the page, whose Deploy button says
        // why it cannot deploy and where to get more, instead of a dead button here.
        $this->visible(fn () => AccountAllowance::forUser(auth()->user()) !== null);

        $this->url(fn () => CreateServerPage::getUrl());
    }
}
