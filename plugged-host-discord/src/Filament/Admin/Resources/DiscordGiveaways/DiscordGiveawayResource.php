<?php

namespace PluggedHost\Discord\Filament\Admin\Resources\DiscordGiveaways;

use App\Enums\CustomizationKey;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PluggedHost\Discord\Filament\Admin\Resources\DiscordGiveaways\Pages\ManageDiscordGiveaways;
use PluggedHost\Discord\Models\DiscordGiveaway;
use PluggedHost\Discord\Models\DiscordGiveawayRecipient;
use PluggedHost\Discord\Services\GiveawayRewardService;

/**
 * Admin → Giveaway Rewards: paste a giveaway's winners by Discord id and pay each of
 * them credits or tokens.
 *
 * Create-and-view only. A giveaway cannot be edited or deleted, because either would
 * leave the record disagreeing with the ledger rows it already wrote; a mistake is
 * corrected per user from the Economy tab on the admin user page, like any other
 * balance. The one follow-up action is a retry, for winners who were not linked yet.
 *
 * Gated on the `update user` permission, the same one that unlocks that Economy tab:
 * paying a winner is a balance change, and a new permission would be unassigned on
 * every existing role.
 */
class DiscordGiveawayResource extends Resource
{
    protected static ?string $model = DiscordGiveaway::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-gift';

    protected static ?string $recordTitleAttribute = 'prize';

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-discord::strings.giveaways.navigation');
    }

    public static function getModelLabel(): string
    {
        return trans('plugged-host-discord::strings.giveaways.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('plugged-host-discord::strings.giveaways.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        if (user()?->getCustomization(CustomizationKey::TopNavigation) === 'topbar') {
            return null;
        }

        return trans('admin/dashboard.user');
    }

    public static function canViewAny(): bool
    {
        return app(GiveawayRewardService::class)->isAvailable()
            && (bool) user()?->can('update user');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && app(GiveawayRewardService::class)->currencies() !== [];
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $service = app(GiveawayRewardService::class);

        return $schema->components([
            TextInput::make('prize')
                ->label(trans('plugged-host-discord::strings.giveaways.prize'))
                ->helperText(trans('plugged-host-discord::strings.giveaways.prize_hint'))
                ->required()
                ->maxLength(120)
                ->columnSpanFull(),
            Select::make('currency')
                ->label(trans('plugged-host-discord::strings.giveaways.currency'))
                ->options(fn (): array => $service->currencies())
                ->default(fn (): ?string => array_key_first($service->currencies()))
                ->selectablePlaceholder(false)
                ->required(),
            TextInput::make('amount')
                ->label(trans('plugged-host-discord::strings.giveaways.amount'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(GiveawayRewardService::MAX_AMOUNT)
                ->required(),
            Textarea::make('discord_ids')
                ->label(trans('plugged-host-discord::strings.giveaways.discord_ids'))
                ->helperText(trans('plugged-host-discord::strings.giveaways.discord_ids_hint', [
                    'max' => GiveawayRewardService::MAX_RECIPIENTS,
                ]))
                ->placeholder("123456789012345678\n234567890123456789")
                ->rows(8)
                ->required()
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($service): void {
                        $parsed = $service->parseDiscordIds((string) $value);

                        if ($parsed['invalid'] !== []) {
                            $fail(trans('plugged-host-discord::strings.giveaways.discord_ids_invalid', [
                                'ids' => implode(', ', array_slice($parsed['invalid'], 0, 10)),
                            ]));
                        } elseif ($parsed['ids'] === []) {
                            $fail(trans('plugged-host-discord::strings.giveaways.discord_ids_empty'));
                        } elseif (count($parsed['ids']) > GiveawayRewardService::MAX_RECIPIENTS) {
                            $fail(trans('plugged-host-discord::strings.giveaways.discord_ids_too_many', [
                                'max' => GiveawayRewardService::MAX_RECIPIENTS,
                            ]));
                        }
                    },
                ])
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('prize')
                        ->label(trans('plugged-host-discord::strings.giveaways.prize'))
                        ->columnSpanFull(),
                    TextEntry::make('amount')
                        ->label(trans('plugged-host-discord::strings.giveaways.reward'))
                        ->formatStateUsing(fn (DiscordGiveaway $record): string => self::rewardLabel($record)),
                    TextEntry::make('creator.username')
                        ->label(trans('plugged-host-discord::strings.giveaways.created_by'))
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label(trans('plugged-host-discord::strings.giveaways.created_at'))
                        ->dateTime(),
                ]),
            RepeatableEntry::make('recipients')
                ->label(trans('plugged-host-discord::strings.giveaways.winners'))
                ->table([
                    TableColumn::make(trans('plugged-host-discord::strings.giveaways.discord_id')),
                    TableColumn::make(trans('plugged-host-discord::strings.giveaways.panel_user')),
                    TableColumn::make(trans('plugged-host-discord::strings.giveaways.status')),
                ])
                ->schema([
                    TextEntry::make('discord_id')
                        ->fontFamily('mono')
                        ->copyable(),
                    TextEntry::make('user.username')
                        ->placeholder(trans('plugged-host-discord::strings.giveaways.no_user')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => trans("plugged-host-discord::strings.giveaways.status_{$state}"))
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->tooltip(fn (DiscordGiveawayRecipient $record): ?string => $record->error),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('creator')
                ->withCount([
                    'recipients',
                    'recipients as paid_count' => fn (Builder $query) => $query->where('status', DiscordGiveawayRecipient::STATUS_PAID),
                ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('prize')
                    ->label(trans('plugged-host-discord::strings.giveaways.prize'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('amount')
                    ->label(trans('plugged-host-discord::strings.giveaways.reward'))
                    // Neutral: the currency is spelled out in the label, and colour is kept for state.
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (DiscordGiveaway $record): string => self::rewardLabel($record)),
                TextColumn::make('paid_count')
                    ->label(trans('plugged-host-discord::strings.giveaways.winners'))
                    ->badge()
                    ->color(fn (DiscordGiveaway $record): string => $record->paid_count >= $record->recipients_count ? 'success' : 'warning')
                    ->formatStateUsing(fn (DiscordGiveaway $record): string => trans('plugged-host-discord::strings.giveaways.winners_value', [
                        'paid' => number_format((int) $record->paid_count),
                        'total' => number_format((int) $record->recipients_count),
                    ])),
                TextColumn::make('creator.username')
                    ->label(trans('plugged-host-discord::strings.giveaways.created_by'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(trans('plugged-host-discord::strings.giveaways.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')
                    ->label(trans('plugged-host-discord::strings.giveaways.retry'))
                    ->color('gray')
                    ->visible(fn (DiscordGiveaway $record): bool => static::canViewAny() && $record->paid_count < $record->recipients_count)
                    ->requiresConfirmation()
                    ->modalDescription(trans('plugged-host-discord::strings.giveaways.retry_confirm'))
                    ->action(function (DiscordGiveaway $record): void {
                        if (!static::canViewAny()) {
                            return;
                        }

                        $service = app(GiveawayRewardService::class);
                        $service->pay($record, user());
                        self::notifySummary($record);
                    }),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label(trans('plugged-host-discord::strings.giveaways.create'))
                    ->createAnother(false)
                    ->successNotification(null)
                    ->using(function (array $data): DiscordGiveaway {
                        if (!static::canCreate()) {
                            throw new Halt();
                        }

                        $service = app(GiveawayRewardService::class);

                        try {
                            $giveaway = $service->create(
                                (string) $data['prize'],
                                (string) $data['currency'],
                                (int) $data['amount'],
                                $service->parseDiscordIds((string) $data['discord_ids'])['ids'],
                                user(),
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()
                                ->title(trans('plugged-host-discord::strings.giveaways.notify_error'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt();
                        }

                        self::notifySummary($giveaway);

                        return $giveaway;
                    }),
            ])
            ->emptyStateIcon('tabler-gift')
            ->emptyStateHeading(trans('plugged-host-discord::strings.giveaways.empty_heading'))
            ->emptyStateDescription(trans('plugged-host-discord::strings.giveaways.empty_description'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageDiscordGiveaways::route('/')];
    }

    public static function rewardLabel(DiscordGiveaway $giveaway): string
    {
        return trans('plugged-host-discord::strings.giveaways.reward_value', [
            'amount' => number_format($giveaway->amount),
            'currency' => trans("plugged-host-discord::strings.giveaways.currency_{$giveaway->currency}"),
        ]);
    }

    protected static function statusColor(string $status): string
    {
        return match ($status) {
            DiscordGiveawayRecipient::STATUS_PAID => 'success',
            DiscordGiveawayRecipient::STATUS_UNLINKED => 'warning',
            DiscordGiveawayRecipient::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Report where the whole giveaway stands, not just what the last run did — after
     * a retry, "2 of 5 paid" is the number the admin actually needs.
     */
    protected static function notifySummary(DiscordGiveaway $giveaway): void
    {
        $counts = $giveaway->recipients()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();
        $paid = (int) ($counts[DiscordGiveawayRecipient::STATUS_PAID] ?? 0);
        $unlinked = (int) ($counts[DiscordGiveawayRecipient::STATUS_UNLINKED] ?? 0);
        $failed = $total - $paid - $unlinked;

        $lines = [trans('plugged-host-discord::strings.giveaways.notify_body', [
            'paid' => number_format($paid),
            'total' => number_format($total),
            'reward' => self::rewardLabel($giveaway),
        ])];

        if ($unlinked > 0) {
            $lines[] = trans('plugged-host-discord::strings.giveaways.notify_unlinked', ['count' => number_format($unlinked)]);
        }

        if ($failed > 0) {
            $lines[] = trans('plugged-host-discord::strings.giveaways.notify_failed', ['count' => number_format($failed)]);
        }

        $notification = Notification::make()
            ->title(trans('plugged-host-discord::strings.giveaways.notify_title', ['prize' => $giveaway->prize]))
            ->body(implode(' ', $lines));

        match (true) {
            $paid === $total => $notification->success(),
            $paid === 0 => $notification->danger(),
            default => $notification->warning(),
        };

        $notification->send();
    }
}
