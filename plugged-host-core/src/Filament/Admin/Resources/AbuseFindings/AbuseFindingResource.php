<?php

namespace PluggedHost\Core\Filament\Admin\Resources\AbuseFindings;

use App\Enums\SuspendAction;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use PluggedHost\Core\Filament\Admin\Clusters\Abuse;
use PluggedHost\Core\Filament\Admin\Resources\AbuseFindings\Pages\ManageAbuseFindings;
use PluggedHost\Core\Models\AbuseFinding;
use PluggedHost\Core\Services\Abuse\Signatures;
use PluggedHost\Core\Support\SuspensionReason;
use Throwable;

/**
 * What the abuse checks found (Services\Abuse). Open and suspended findings by default, newest
 * activity first. Dismiss marks a false positive: that rule is never acted on again for that
 * server. Resolve closes a finding until the checks see it again.
 */
class AbuseFindingResource extends Resource
{
    protected static ?string $model = AbuseFinding::class;

    protected static ?string $cluster = Abuse::class;

    protected static ?string $slug = 'findings';

    protected static ?int $navigationSort = 1;

    private const STATUSES = [
        AbuseFinding::OPEN => 'Open',
        AbuseFinding::ACTIONED => 'Suspended',
        AbuseFinding::DISMISSED => 'Dismissed',
        AbuseFinding::RESOLVED => 'Resolved',
    ];

    private const SOURCES = [
        'files' => 'Files',
        'startup' => 'Startup',
        'console' => 'Console',
        'process' => 'Process',
        'network' => 'Network',
        'cpu' => 'CPU',
        'host' => 'Node host',
    ];

    public static function getNavigationLabel(): string
    {
        return 'Findings';
    }

    public static function getModelLabel(): string
    {
        return 'abuse finding';
    }

    public static function canViewAny(): bool
    {
        return (user()?->can('viewAny', Server::class) ?? false) && AbuseFinding::ready();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['server.user', 'node']))
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('summary')
                    ->label('Finding')
                    ->searchable()
                    ->wrap()
                    ->description(fn (AbuseFinding $record) => Signatures::categoryLabel($record->category) . ' · ' . (self::SOURCES[$record->source] ?? $record->source)),
                TextColumn::make('server.name')
                    ->label('Server')
                    ->searchable()
                    ->placeholder('Node host')
                    ->description(fn (AbuseFinding $record) => $record->server
                        ? '#' . $record->server->id . ' · ' . ($record->server->user?->username ?? '?')
                        : null)
                    ->url(fn (AbuseFinding $record) => $record->server ? ServerResource::getUrl('edit', ['record' => $record->server]) : null),
                TextColumn::make('node.name')
                    ->label('Node'),
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => $state === 'critical' ? 'danger' : 'warning'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        AbuseFinding::OPEN => 'warning',
                        AbuseFinding::ACTIONED => 'danger',
                        AbuseFinding::RESOLVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('hits')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::STATUSES)
                    ->multiple()
                    ->default([AbuseFinding::OPEN, AbuseFinding::ACTIONED]),
                SelectFilter::make('severity')
                    ->options(['critical' => 'Critical', 'review' => 'Review']),
                SelectFilter::make('category')
                    ->options(Signatures::categories()),
                SelectFilter::make('source')
                    ->options(self::SOURCES),
            ])
            ->recordActions([
                Action::make('evidence')
                    ->label('Evidence')
                    ->modalHeading(fn (AbuseFinding $record) => $record->summary)
                    ->modalContent(fn (AbuseFinding $record) => view('plugged-host-core::filament.admin.abuse-evidence', ['finding' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                ActionGroup::make([
                    Action::make('suspend')
                        ->label('Suspend server')
                        ->requiresConfirmation()
                        ->modalDescription(fn (AbuseFinding $record) => "The owner sees this finding as the reason: {$record->summary}")
                        ->modalSubmitAction(fn ($action) => $action->color('danger'))
                        ->visible(fn (AbuseFinding $record) => $record->server !== null && !$record->server->isSuspended())
                        ->action(function (AbuseFinding $record): void {
                            try {
                                SuspensionReason::with(
                                    ['source' => SuspensionReason::SOURCE_ADMIN, 'reasons' => [$record->summary]],
                                    fn () => app(SuspensionService::class)->handle($record->server, SuspendAction::Suspend),
                                );
                                $record->forceFill(['status' => AbuseFinding::ACTIONED, 'action' => 'suspended', 'actioned_at' => now()])->save();
                                Notification::make()->success()->title('Server suspended')->send();
                            } catch (Throwable $e) {
                                Notification::make()->danger()->title('Could not suspend')->body($e->getMessage())->send();
                            }
                        }),
                    Action::make('dismiss')
                        ->label('Dismiss as false positive')
                        ->requiresConfirmation()
                        ->modalDescription('It stays listed, but this rule is never acted on again for this server.')
                        ->visible(fn (AbuseFinding $record) => $record->status !== AbuseFinding::DISMISSED)
                        ->action(fn (AbuseFinding $record) => self::close($record, AbuseFinding::DISMISSED)),
                    Action::make('resolve')
                        ->label('Resolve')
                        ->visible(fn (AbuseFinding $record) => in_array($record->status, [AbuseFinding::OPEN, AbuseFinding::ACTIONED], true))
                        ->action(fn (AbuseFinding $record) => self::close($record, AbuseFinding::RESOLVED)),
                    Action::make('reopen')
                        ->label('Reopen')
                        ->visible(fn (AbuseFinding $record) => in_array($record->status, [AbuseFinding::DISMISSED, AbuseFinding::RESOLVED], true))
                        ->action(fn (AbuseFinding $record) => $record->forceFill(['status' => AbuseFinding::OPEN, 'resolved_by' => null, 'resolved_at' => null])->save()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolve')
                        ->label('Resolve')
                        ->action(fn (Collection $records) => $records->each(fn (AbuseFinding $record) => self::close($record, AbuseFinding::RESOLVED))),
                    BulkAction::make('dismiss')
                        ->label('Dismiss as false positive')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (AbuseFinding $record) => self::close($record, AbuseFinding::DISMISSED))),
                ]),
            ]);
    }

    private static function close(AbuseFinding $record, string $status): void
    {
        $record->forceFill(['status' => $status, 'resolved_by' => user()?->id, 'resolved_at' => now()])->save();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAbuseFindings::route('/'),
        ];
    }
}
