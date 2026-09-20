<?php

namespace PluggedHost\Servers\Filament\Admin\Pages;

use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Models\Node;
use BackedEnum;
use Filament\Pages\Page;

class AvailableResources extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::DeviceDesktopAnalytics;

    protected string $view = 'plugged-host-servers::filament.pages.available-resources';

    public static function canAccess(): bool
    {
        return user()?->can('viewAny', Node::class) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return 'Available Resources';
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.server');
    }

    public function getTitle(): string
    {
        return 'Available Resources';
    }

    /** @return array{nodes: \Illuminate\Database\Eloquent\Collection<int, Node>} */
    protected function getViewData(): array
    {
        return [
            'nodes' => user()?->accessibleNodes()->orderBy('name')->get() ?? collect(),
        ];
    }
}
