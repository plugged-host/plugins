<?php

namespace PluggedHost\Status\Filament\Admin\Resources\StatusComponentOverrides;

use App\Models\Node;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use PluggedHost\Status\Filament\Admin\Clusters\StatusPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PluggedHost\Status\Filament\Admin\Resources\StatusComponentOverrides\Pages\ManageStatusComponentOverrides;
use PluggedHost\Status\Models\StatusComponentOverride;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Services\StatusExporter;

class StatusComponentOverrideResource extends Resource
{
    protected static ?string $model = StatusComponentOverride::class;

    // A tab of the Status page cluster: no icon of its own, since the tab says what it is.
    protected static ?string $cluster = StatusPage::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'component_key';

    public static function getNavigationLabel(): string
    {
        return 'Components';
    }

    public static function getModelLabel(): string
    {
        return 'component control';
    }

    public static function getPluralModelLabel(): string
    {
        return 'component controls';
    }

    public static function canViewAny(): bool
    {
        return user()?->can('viewAny', Node::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Component control')
                ->description('Force a component to a chosen status and optionally show a public note. Controls win over live probes and incidents while active — clear them when the situation resolves.')
                ->columns(2)
                ->schema([
                    Select::make('component_key')
                        ->label('Component')
                        ->options(self::componentOptions())
                        ->required()
                        ->searchable(),
                    Select::make('status')
                        ->options(self::statusOptions())
                        ->required()
                        ->default('maintenance'),
                    Textarea::make('note')
                        ->label('Public note')
                        ->helperText('Optional. Shown publicly next to the component on the status page.')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    DateTimePicker::make('starts_at')
                        ->seconds(false)
                        ->helperText('Leave blank to apply immediately.'),
                    DateTimePicker::make('ends_at')
                        ->seconds(false)
                        ->helperText('Leave blank to keep applied until you delete this control.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('component_key')
                    ->label('Component')
                    ->formatStateUsing(fn (string $state) => self::componentOptions()[$state] ?? $state)
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str($state)->replace('_', ' ')->title())
                    ->color(fn (string $state) => match ($state) {
                        'operational' => 'success',
                        'maintenance' => 'info',
                        'degraded' => 'warning',
                        'partial_outage' => 'warning',
                        'major_outage', 'offline' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('note')
                    ->wrap()
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->placeholder('Immediately'),
                TextColumn::make('ends_at')
                    ->dateTime()
                    ->placeholder('Until cleared'),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn () => app(StatusExporter::class)->export()),
                DeleteAction::make()
                    ->after(fn () => app(StatusExporter::class)->export()),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->after(fn () => app(StatusExporter::class)->export()),
            ])
            ->emptyStateIcon('tabler-adjustments-alt')
            ->emptyStateHeading('No component controls')
            ->emptyStateDescription('Add a control to manually pin a component status without opening a full incident.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageStatusComponentOverrides::route('/')];
    }

    /** @return array<string, string> */
    private static function componentOptions(): array
    {
        $options = [
            'all' => 'All systems',
            'site' => 'Website',
            'panel' => 'Panel',
        ];

        StatusNodeSetting::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->each(function (StatusNodeSetting $setting) use (&$options): void {
                $options["node:{$setting->public_id}"] = $setting->display_name;
            });

        return $options;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return [
            'operational' => 'Operational',
            'maintenance' => 'Maintenance',
            'degraded' => 'Degraded',
            'partial_outage' => 'Partial outage',
            'major_outage' => 'Major outage',
            'offline' => 'Offline',
        ];
    }
}
