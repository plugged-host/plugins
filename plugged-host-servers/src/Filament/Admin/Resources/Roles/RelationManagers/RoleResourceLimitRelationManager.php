<?php

namespace PluggedHost\Servers\Filament\Admin\Resources\Roles\RelationManagers;

use PluggedHost\Servers\Filament\Admin\Resources\RoleResourceLimits\RoleResourceLimitsResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RoleResourceLimitRelationManager extends RelationManager
{
    protected static string $relationship = 'resourceLimits';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (static::shouldSkipAuthorization()) {
            return true;
        }

        return RoleResourceLimitsResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        $suffix = config('panel.use_binary_prefix') ? ' MiB' : ' MB';

        return $table
            ->heading(trans_choice('plugged-host-servers::strings.role_resource_limits', 2))
            ->columns([
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
                    ->hidden(fn ($record) => RoleResourceLimitsResource::getEditAuthorizationResponse($record)->allowed()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->visible(fn () => !$this->getOwnerRecord()->resourceLimits()->exists()),
            ])
            ->emptyStateIcon('tabler-cube-plus')
            ->emptyStateDescription('');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(RoleResourceLimitsResource::limitFields());
    }
}
