<?php

namespace PluggedHost\Servers\Filament\App\Pages;

use BackedEnum;
use Filament\Pages\Page;
use PluggedHost\Servers\Filament\App\Widgets\UserResourceLimitsOverview;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Servers\Services\GameCatalog;

/**
 * Step one of server creation: pick a game.
 *
 * Keeps the `create-server` slug so CreateServerAction::getUrl() and any bookmarked
 * link keep working; the actual configuration moved to ConfigureServerPage.
 */
class CreateServerPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-cube-plus';

    protected static ?string $slug = 'create-server';

    protected string $view = 'plugged-host-servers::filament.pages.create-server';

    public function getTitle(): string
    {
        return trans('plugged-host-servers::strings.create_server');
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-servers::strings.create_server');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-servers::strings.choose_game_description');
    }

    public static function canAccess(): bool
    {
        return RoleResourceLimits::forUser(auth()->user()) !== null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            UserResourceLimitsOverview::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'games' => app(GameCatalog::class)->games(),
        ];
    }
}
