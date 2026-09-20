<?php

namespace PluggedHost\Status\Filament\Admin\Resources\StatusNodes;

use App\Enums\CustomizationKey;
use App\Models\Node;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PluggedHost\Status\Filament\Admin\Resources\StatusNodes\Pages\ManageStatusNodes;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Services\StatusExporter;

class StatusNodeResource extends Resource
{
    protected static ?string $model = StatusNodeSetting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-server-cog';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function getNavigationLabel(): string
    {
        return 'Public status nodes';
    }

    public static function getModelLabel(): string
    {
        return 'public status node';
    }

    public static function getPluralModelLabel(): string
    {
        return 'public status nodes';
    }

    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.server');
    }

    public static function canViewAny(): bool
    {
        return user()?->can('viewAny', Node::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('node_id')
                ->label('Pelican node')
                ->relationship('node', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->unique(ignoreRecord: true)
                ->live()
                ->afterStateUpdated(fn ($state, callable $set) => $set('display_name', $state ? Node::find($state)?->name : null))
                ->hiddenOn('edit'),
            TextInput::make('display_name')
                ->label('Public label')
                ->helperText('Mirrors the Pelican node name automatically and re-syncs every minute. Rename the node itself to change what the status page shows.')
                ->disabled()
                ->dehydrated()
                ->maxLength(100),
            Toggle::make('enabled')
                ->label('Publish and monitor this node')
                ->default(true)
                ->inline(false),
            TextInput::make('sort_order')
                ->label('Display order')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('node.name')
                    ->label('Pelican node')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('display_name')
                    ->label('Public label')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->label('Published')
                    ->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str($state)->replace('_', ' ')->title())
                    ->color(fn (string $state) => match ($state) {
                        'operational' => 'success',
                        'maintenance' => 'info',
                        'degraded' => 'warning',
                        'offline' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_reported_at')
                    ->label('Last telemetry')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never'),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn () => app(StatusExporter::class)->export()),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->after(fn () => app(StatusExporter::class)->export()),
            ])
            ->emptyStateIcon('tabler-server-cog')
            ->emptyStateDescription('Run the status collector to discover Pelican nodes automatically.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageStatusNodes::route('/')];
    }
}
