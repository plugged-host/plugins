<?php

namespace Boy132\Subdomains\Filament\Server\Resources\Subdomains;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use App\Traits\Filament\HasLimitBadge;
use Boy132\Subdomains\Enums\SRVServiceType;
use Boy132\Subdomains\Filament\Server\Resources\Subdomains\Pages\ListSubdomains;
use Boy132\Subdomains\Models\CloudflareDomain;
use Boy132\Subdomains\Models\Subdomain;
use Boy132\Subdomains\Rules\NotOnBlacklist;
use Boy132\Subdomains\Services\SubdomainService;
use Closure;
use Exception;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class SubdomainResource extends Resource
{
    use BlockAccessInConflict;
    use HasLimitBadge;

    protected static ?string $model = Subdomain::class;

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world-www';

    public static function canAccess(): bool
    {
        /** @var Server|null $server */
        $server = Filament::getTenant();

        if (!$server || !Subdomain::isServerEggSupported($server)) {
            return false;
        }

        return parent::canAccess() && Subdomain::publicIpFor($server) !== null && CloudflareDomain::count() > 0;
    }

    /**
     * Subdomains point at the server's address and are paid from the owner's tokens, so
     * subusers need the matching network (allocation) permission, like the panel's Network
     * page. There is no Subdomain policy, and without one Filament allows everything.
     */
    public static function canViewAny(): bool
    {
        return self::tenantAllows(SubuserPermission::AllocationRead);
    }

    public static function canCreate(): bool
    {
        return self::tenantAllows(SubuserPermission::AllocationCreate);
    }

    public static function canEdit(Model $record): bool
    {
        return self::tenantAllows(SubuserPermission::AllocationUpdate);
    }

    public static function canDelete(Model $record): bool
    {
        return self::tenantAllows(SubuserPermission::AllocationDelete);
    }

    protected static function tenantAllows(SubuserPermission $permission): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && (bool) user()?->can($permission, $server);
    }

    public static function shouldRegisterNavigation(): bool
    {
        /** @var Server|null $server */
        $server = Filament::getTenant();

        if (!$server || !Subdomain::isServerEggSupported($server)) {
            return false;
        }

        return static::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('subdomains::strings.subdomain', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('subdomains::strings.subdomain', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('subdomains::strings.subdomain', 2);
    }

    protected static function getBadgeCount(): int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server->subdomains->count(); // @phpstan-ignore property.notFound
    }

    protected static function getBadgeLimit(): int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $limit = $server->subdomain_limit ?? 0;
        return $limit > 0 ? $limit : (int) config('subdomains.default_limit', 1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->columns([
                TextColumn::make('label')
                    ->label(trans('subdomains::strings.name'))
                    ->state(fn (Subdomain $subdomain) => $subdomain->getLabel())
                    ->copyable()
                    ->copyMessage('Subdomain copied to clipboard'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Subdomain $subdomain) => $subdomain->status_label)
                    ->color(fn (Subdomain $subdomain) => $subdomain->status_color)
                    ->icon(fn (Subdomain $subdomain) => $subdomain->isPropagating() ? 'tabler-clock-hour-4' : 'tabler-circle-check')
                    ->tooltip(fn (Subdomain $subdomain) => $subdomain->isPropagating()
                        ? 'DNS propagation in progress. Changes typically take 1 to 3 minutes to be reachable worldwide.'
                        : 'Subdomain is active and propagated!'),
                TextColumn::make('game_profile')
                    ->label('Type')
                    ->badge()
                    ->state(fn (Subdomain $subdomain) => $subdomain->game_label)
                    ->color(fn (Subdomain $subdomain) => match ($subdomain->game_profile) {
                        'minecraft' => 'success',
                        'fivem' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('token_cost')
                    ->label('Deposit')
                    ->badge()
                    ->state(fn (Subdomain $subdomain) => $subdomain->token_cost . ' ' . ($subdomain->token_cost === 1 ? 'token' : 'tokens'))
                    ->color(fn (Subdomain $subdomain) => $subdomain->token_cost > 0 ? 'warning' : 'gray')
                    ->icon('tabler-coin')
                    ->tooltip('Refundable deposit. Automatically returned to your account if deleted.'),
                TextColumn::make('connection_command')
                    ->label('Connect With')
                    ->state(fn (Subdomain $subdomain) => $subdomain->connection_command)
                    ->copyable()
                    ->copyMessage('Connection command copied!')
                    ->icon('tabler-copy'),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (Subdomain $record) => static::canEdit($record))
                    ->action(function (array $data, Subdomain $subdomain, SubdomainService $service) {
                        try {
                            return $service->handle($data, $subdomain);
                        } catch (Exception $exception) {
                            Notification::make()
                                ->title(trans('subdomains::strings.notifications.not_synced'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            throw new Halt();
                        }
                    }),
                DeleteAction::make()
                    ->authorize(fn (Subdomain $record) => static::canDelete($record))
                    ->successNotification(function (Subdomain $record) {
                        $msg = $record->token_cost > 0 
                            ? "Subdomain deleted. {$record->token_cost} tokens refunded to your balance." 
                            : 'Subdomain deleted.';
                        return Notification::make()
                            ->title($msg)
                            ->success();
                    }),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->authorize(fn () => static::canCreate())
                    ->modalHeading(trans('subdomains::strings.create_subdomain'))
                    ->icon('tabler-world-plus')
                    ->tooltip(function () {
                        if (static::getBadgeCount() >= static::getBadgeLimit()) {
                            return trans('subdomains::strings.limit_reached');
                        }
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        if (Subdomain::requiresDiscord() && !Subdomain::isUserDiscordLinked($server->user)) {
                            return 'Server owner must link their Discord account in Account Settings';
                        }
                        if (Subdomain::requiresDiscordGuild() && !Subdomain::isUserInDiscordGuild($server->user)) {
                            return 'Server owner must be in our Discord server to create subdomains';
                        }
                        return trans('subdomains::strings.create_subdomain');
                    })
                    ->disabled(function () {
                        if (static::getBadgeCount() >= static::getBadgeLimit()) {
                            return true;
                        }
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        if (Subdomain::requiresDiscord() && !Subdomain::isUserDiscordLinked($server->user)) {
                            return true;
                        }
                        if (Subdomain::requiresDiscordGuild() && !Subdomain::isUserInDiscordGuild($server->user)) {
                            return true;
                        }
                        return false;
                    })
                    ->color(function () {
                        if (static::getBadgeCount() >= static::getBadgeLimit()) {
                            return 'danger';
                        }
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        $unmetDiscord = (Subdomain::requiresDiscord() && !Subdomain::isUserDiscordLinked($server->user))
                            || (Subdomain::requiresDiscordGuild() && !Subdomain::isUserInDiscordGuild($server->user));
                        return $unmetDiscord ? 'gray' : 'primary';
                    })
                    ->createAnother(false)
                    ->hiddenLabel()
                    ->iconButton()
                    ->iconSize(IconSize::ExtraLarge)
                    ->action(function (array $data, SubdomainService $service) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            $data['server_id'] = $server->id;

                            return $service->handle($data);
                        } catch (Exception $exception) {
                            Notification::make()
                                ->title(trans('subdomains::strings.notifications.not_synced'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            throw new Halt();
                        }
                    }),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('token_deposit_banner')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->content(function () {
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        $cost = Subdomain::calculateTokenCost($server);
                        $subNum = $server->subdomains()->count() + 1;
                        $balance = class_exists(\PluggedHost\Economy\Services\TokenService::class)
                            ? app(\PluggedHost\Economy\Services\TokenService::class)->balance($server->user)
                            : 0;

                        $costPlural = $cost === 1 ? 'token' : 'tokens';

                        $discordRow = '';
                        $isLinked = Subdomain::requiresDiscord() ? Subdomain::isUserDiscordLinked($server->user) : true;
                        $isInGuild = Subdomain::requiresDiscordGuild() ? Subdomain::isUserInDiscordGuild($server->user) : true;

                        if (!$isLinked) {
                            $discordRow = '
                                <div class="mt-2.5 flex items-center justify-between border-t border-gray-950/5 pt-2.5 dark:border-white/10">
                                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <svg class="size-4 shrink-0 text-[#5865F2]" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515a.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0a12.64 12.64 0 0 0-.617-1.25a.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057a19.9 19.9 0 0 0 5.993 3.03a.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892a.077.077 0 0 1-.008-.128c.126-.093.252-.19.372-.287a.075.075 0 0 1 .077-.01c3.931 1.793 8.18 1.793 12.061 0a.075.075 0 0 1 .078.01c.12.098.246.195.373.288a.077.077 0 0 1-.006.127c-.598.35-1.22.648-1.873.892c-.041.016-.062.066-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028a19.839 19.839 0 0 0 6.002-3.03a.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419c0-1.333.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42c0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419c0-1.333.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42c0 1.333-.946 2.418-2.157 2.418z"/></svg>
                                        <span>Discord account must be linked to server owner</span>
                                    </div>
                                    <a href="/profile" class="text-xs font-semibold text-primary-500 hover:underline" target="_blank">Link Discord &rarr;</a>
                                </div>
                            ';
                        } elseif (!$isInGuild) {
                            $inviteUrl = Subdomain::getDiscordInviteUrl();
                            $discordRow = '
                                <div class="mt-2.5 flex items-center justify-between border-t border-gray-950/5 pt-2.5 dark:border-white/10">
                                    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <svg class="size-4 shrink-0 text-[#5865F2]" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515a.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0a12.64 12.64 0 0 0-.617-1.25a.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057a19.9 19.9 0 0 0 5.993 3.03a.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892a.077.077 0 0 1-.008-.128c.126-.093.252-.19.372-.287a.075.075 0 0 1 .077-.01c3.931 1.793 8.18 1.793 12.061 0a.075.075 0 0 1 .078.01c.12.098.246.195.373.288a.077.077 0 0 1-.006.127c-.598.35-1.22.648-1.873.892c-.041.016-.062.066-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028a19.839 19.839 0 0 0 6.002-3.03a.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419c0-1.333.956-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42c0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419c0-1.333.955-2.419 2.157-2.419c1.21 0 2.176 1.096 2.157 2.42c0 1.333-.946 2.418-2.157 2.418z"/></svg>
                                        <span>Server owner must be in our Discord server</span>
                                    </div>
                                    <a href="' . htmlspecialchars($inviteUrl, ENT_QUOTES) . '" class="text-xs font-semibold text-primary-500 hover:underline" target="_blank">Join Discord &rarr;</a>
                                </div>
                            ';
                        }

                        return new HtmlString('
                            <div class="rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-warning-500 dark:bg-white/10 dark:text-warning-400">
                                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M14.8 9a2 2 0 0 0 -1.8 -1h-2a2 2 0 1 0 0 4h2a2 2 0 1 1 0 4h-2a2 2 0 0 1 -1.8 -1" /><path d="M12 7v10" /></svg>
                                        </div>
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                                Token Deposit &bull; Subdomain #' . $subNum . '
                                            </div>
                                            <div class="flex items-baseline gap-1.5">
                                                <span class="text-base font-bold tabular-nums text-gray-950 dark:text-white">
                                                    ' . $cost . '
                                                </span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    ' . $costPlural . ' (refundable on deletion)
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                            Your Balance
                                        </div>
                                        <div class="flex items-center justify-end gap-1.5 text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                                            <svg class="size-4 text-warning-500 dark:text-warning-400 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M14.8 9a2 2 0 0 0 -1.8 -1h-2a2 2 0 1 0 0 4h2a2 2 0 1 1 0 4h-2a2 2 0 0 1 -1.8 -1" /><path d="M12 7v10" /></svg>
                                            ' . $balance . ' ' . ($balance === 1 ? 'token' : 'tokens') . '
                                        </div>
                                    </div>
                                </div>
                                ' . $discordRow . '
                            </div>
                        ');
                    }),
                TextInput::make('name')
                    ->label(trans('subdomains::strings.name'))
                    ->required()
                    ->minLength(2)
                    ->maxLength(63)
                    ->regex('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i')
                    ->validationMessages([
                        'regex' => 'Subdomain may only contain lowercase letters, numbers, and hyphens (cannot start or end with a hyphen).',
                    ])
                    ->dehydrateStateUsing(fn ($state) => strtolower(trim((string) $state)))
                    ->rule(new NotOnBlacklist())
                    ->rules([
                        function () {
                            return function (string $attribute, $value, Closure $fail) {
                                /** @var Server $server */
                                $server = Filament::getTenant();

                                if (Subdomain::requiresDiscord() && !Subdomain::isUserDiscordLinked($server->user)) {
                                    $fail('The server owner must link their Discord account in Account Settings before creating a subdomain.');
                                }

                                if (Subdomain::requiresDiscordGuild() && !Subdomain::isUserInDiscordGuild($server->user)) {
                                    $invite = Subdomain::getDiscordInviteUrl();
                                    $fail("The server owner must join our Discord server ({$invite}) before creating a subdomain.");
                                }

                                $cost = Subdomain::calculateTokenCost($server);
                                if ($cost > 0 && class_exists(\PluggedHost\Economy\Services\TokenService::class)) {
                                    $tokenService = app(\PluggedHost\Economy\Services\TokenService::class);
                                    $balance = $tokenService->balance($server->user);
                                    if ($balance < $cost) {
                                        $costPlural = $cost === 1 ? 'token' : 'tokens';
                                        $fail("Insufficient tokens! This subdomain requires a deposit of {$cost} {$costPlural}, but your account only has {$balance} tokens.");
                                    }
                                }
                            };
                        },
                    ])
                    ->unique(
                        table: 'subdomains',
                        column: 'name',
                        modifyRuleUsing: fn ($rule, Get $get) => $rule->where('domain_id', $get('domain_id') ?: CloudflareDomain::first()?->id),
                        ignoreRecord: true
                    )
                    ->columnSpanFull()
                    ->placeholder('e.g. play')
                    ->suffix(fn (Get $get) => '.' . (CloudflareDomain::find($get('domain_id'))?->name ?? CloudflareDomain::first()?->name ?? ''))
                    ->helperText(function () {
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        $srv = SRVServiceType::fromServer($server);

                        if ($srv === SRVServiceType::Minecraft) {
                            return '🎮 Minecraft: Java Edition detected. Subdomain will route players directly to your port without typing :port.';
                        }

                        if ($srv === SRVServiceType::FiveM) {
                            return '🚗 FiveM / RedM detected. Subdomain will configure Cfx.re SRV records so players can use "connect" without typing :port.';
                        }

                        return '🌐 Standard server. Subdomain will point directly to ' . (Subdomain::publicIpFor($server) ?? 'server IP') . '.';
                    }),
                Select::make('domain_id')
                    ->label(trans_choice('subdomains::strings.domain', 1))
                    ->disabledOn('edit')
                    ->hidden(fn () => CloudflareDomain::count() <= 1)
                    ->dehydratedWhenHidden()
                    ->required()
                    ->selectablePlaceholder(false)
                    ->default(fn () => CloudflareDomain::first()?->id)
                    ->relationship('domain', 'name')
                    ->preload()
                    ->searchable()
                    ->live(),
                Hidden::make('record_type')
                    ->default(function () {
                        /** @var Server $server */
                        $server = Filament::getTenant();
                        $srv = SRVServiceType::fromServer($server);
                        if ($srv) {
                            return 'SRV';
                        }
                        return is_ipv6(Subdomain::publicIpFor($server) ?? '') ? 'AAAA' : 'A';
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubdomains::route('/'),
        ];
    }
}
