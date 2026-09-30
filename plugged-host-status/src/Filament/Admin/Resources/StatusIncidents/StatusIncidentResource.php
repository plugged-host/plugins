<?php

namespace PluggedHost\Status\Filament\Admin\Resources\StatusIncidents;

use App\Models\Node;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use PluggedHost\Status\Filament\Admin\Clusters\StatusPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PluggedHost\Status\Filament\Admin\Resources\StatusIncidents\Pages\ManageStatusIncidents;
use PluggedHost\Status\Models\StatusIncident;
use PluggedHost\Status\Models\StatusNodeSetting;
use PluggedHost\Status\Services\StatusExporter;

class StatusIncidentResource extends Resource
{
    protected static ?string $model = StatusIncident::class;

    // A tab of the Status page cluster: no icon of its own, since the tab says what it is.
    protected static ?string $cluster = StatusPage::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return 'Incidents';
    }

    public static function getModelLabel(): string
    {
        return 'status incident';
    }

    public static function canViewAny(): bool
    {
        return user()?->can('viewAny', Node::class) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Incident')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('impact')
                        ->options([
                            'maintenance' => 'Maintenance',
                            'minor' => 'Minor',
                            'major' => 'Major',
                            'critical' => 'Critical',
                        ])
                        ->required()
                        ->default('minor'),
                    Select::make('status')
                        ->options(self::updateStatuses())
                        ->required()
                        ->default('investigating'),
                    CheckboxList::make('affected_components')
                        ->label('Affected components')
                        ->options(self::componentOptions())
                        ->required()
                        ->bulkToggleable()
                        ->columns(2)
                        ->columnSpanFull(),
                    DateTimePicker::make('started_at')
                        ->seconds(false)
                        ->default(fn () => now())
                        ->required(),
                    DateTimePicker::make('resolved_at')
                        ->seconds(false)
                        ->disabled()
                        ->helperText('Set automatically when the incident status becomes Resolved.'),
                ]),
            Section::make('Public updates')
                ->description('Add concise updates in newest-to-oldest publication order. These messages are exported publicly and included in the RSS feed.')
                ->schema([
                    Repeater::make('updates')
                        ->relationship()
                        ->schema([
                            Select::make('status')
                                ->options(self::updateStatuses())
                                ->required()
                                ->default('investigating'),
                            DateTimePicker::make('published_at')
                                ->seconds(false)
                                ->default(fn () => now())
                                ->required(),
                            Textarea::make('message')
                                ->required()
                                ->rows(4)
                                ->maxLength(5000)
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->reorderable(false)
                        ->defaultItems(1)
                        ->addActionLabel('Add public update')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('impact')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'critical' => 'danger',
                        'major' => 'warning',
                        'maintenance' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => str($state)->title())
                    ->color(fn (string $state) => $state === 'resolved' ? 'success' : 'warning'),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->dateTime()
                    ->placeholder('Active'),
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
            ->emptyStateIcon('tabler-circle-check')
            ->emptyStateHeading('No incidents published')
            ->emptyStateDescription('Create an incident when customers need an operational update.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageStatusIncidents::route('/')];
    }

    /** @return array<string, string> */
    private static function updateStatuses(): array
    {
        return [
            'investigating' => 'Investigating',
            'identified' => 'Identified',
            'monitoring' => 'Monitoring',
            'resolved' => 'Resolved',
        ];
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
}
