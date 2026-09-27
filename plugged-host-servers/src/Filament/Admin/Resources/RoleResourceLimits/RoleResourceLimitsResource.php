<?php

namespace PluggedHost\Servers\Filament\Admin\Resources\RoleResourceLimits;

use App\Enums\CustomizationKey;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Models\Role;
use PluggedHost\Servers\Filament\Admin\Resources\RoleResourceLimits\Pages\ManageRoleResourceLimits;
use PluggedHost\Servers\Models\RoleResourceLimits;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoleResourceLimitsResource extends Resource
{
    protected static ?string $model = RoleResourceLimits::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-cube-plus';

    public static function getNavigationLabel(): string
    {
        return trans_choice('plugged-host-servers::strings.role_resource_limits', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('plugged-host-servers::strings.role_resource_limits', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('plugged-host-servers::strings.role_resource_limits', 2);
    }

    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.user');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count() ?: null;
    }

    public static function table(Table $table): Table
    {
        $suffix = config('panel.use_binary_prefix') ? ' MiB' : ' MB';

        return $table
            ->columns([
                TextColumn::make('role.name')
                    ->label(trans_choice('plugged-host-servers::strings.role', 1))
                    ->url(fn (RoleResourceLimits $limits) => auth()->user()->can('update', $limits->role) ? EditRole::getUrl(['record' => $limits->role]) : null),
                TextColumn::make('cpu')
                    ->label(trans('plugged-host-servers::strings.cpu'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state . '%' : trans('plugged-host-servers::strings.unlimited')),
                TextColumn::make('memory')
                    ->label(trans('plugged-host-servers::strings.memory'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state . $suffix : trans('plugged-host-servers::strings.unlimited')),
                TextColumn::make('disk')
                    ->label(trans('plugged-host-servers::strings.disk'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state . $suffix : trans('plugged-host-servers::strings.unlimited')),
                TextColumn::make('server_limit')
                    ->label(trans('plugged-host-servers::strings.server_limit'))
                    ->badge()
                    ->placeholder(trans('plugged-host-servers::strings.no_limit')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn ($record) => static::getEditAuthorizationResponse($record)->allowed()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->emptyStateIcon('tabler-cube-plus')
            ->emptyStateDescription('');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role_id')
                    ->label(trans_choice('plugged-host-servers::strings.role', 1))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->relationship('role', 'name')
                    ->searchable(['name'])
                    ->getOptionLabelFromRecordUsing(fn (Role $role) => $role->name)
                    ->selectablePlaceholder(false)
                    ->hiddenOn('edit')
                    ->columnSpanFull(),
                ...self::limitFields(),
            ]);
    }

    /** @return Component[] */
    public static function limitFields(): array
    {
        return [
            TextInput::make('cpu')
                ->label(trans('plugged-host-servers::strings.cpu'))
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->suffix('%')
                ->hint(trans('plugged-host-servers::strings.hint_unlimited')),
            TextInput::make('memory')
                ->label(trans('plugged-host-servers::strings.memory'))
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                ->hint(trans('plugged-host-servers::strings.hint_unlimited')),
            TextInput::make('disk')
                ->label(trans('plugged-host-servers::strings.disk'))
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                ->hint(trans('plugged-host-servers::strings.hint_unlimited')),
            TextInput::make('server_limit')
                ->label(trans('plugged-host-servers::strings.server_limit'))
                ->numeric()
                ->minValue(0)
                ->nullable()
                ->placeholder(trans('plugged-host-servers::strings.no_limit')),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('role.name')
                    ->label(trans_choice('plugged-host-servers::strings.role', 1))
                    ->columnSpanFull(),
                TextEntry::make('cpu')
                    ->label(trans('plugged-host-servers::strings.cpu'))
                    ->badge(),
                TextEntry::make('memory')
                    ->label(trans('plugged-host-servers::strings.memory'))
                    ->badge(),
                TextEntry::make('disk')
                    ->label(trans('plugged-host-servers::strings.disk'))
                    ->badge(),
                TextEntry::make('server_limit')
                    ->label(trans('plugged-host-servers::strings.server_limit'))
                    ->badge()
                    ->placeholder(trans('plugged-host-servers::strings.no_limit')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoleResourceLimits::route('/'),
        ];
    }
}
