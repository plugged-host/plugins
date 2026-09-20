<?php

namespace PluggedHost\Economy;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\TablerIcon;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

/**
 * The two currencies, and nothing that earns them.
 *
 * Credits and tokens live here together because they share a shape — a balance row
 * plus an append-only transaction ledger — and because keeping them in one plugin is
 * what makes "these are not exchangeable" a rule with a single place to enforce it.
 * What is deliberately *not* here: earning. Discord invites and boosts mint credits
 * from plugged-host-discord, Linkvertise mints tokens from plugged-host-ads, and both
 * reach the ledgers through this plugin's services rather than the models.
 *
 * Only the app panel gets Filament classes, and there is still no admin *resource* for
 * balances, because a resource with an editable `credits` column would be a way around
 * the rule that every write to a ledger goes through a service that also writes the
 * transaction row. Support does need to be able to correct a balance, though, so what
 * exists instead is an Economy tab on the admin user page — read-only balances plus two
 * modal actions that ask for a target, convert it to a delta and post the delta through
 * `UserCredits`/`TokenService` like every other writer does. See
 * PluggedHost\Economy\Providers\EconomyAdminPluginProvider, which is registered by
 * convention from src/Providers/ rather than from this class.
 *
 * Settings field names are prefixed (`credits_*`, `tokens_*`) even though it reads
 * long, because HasPluginSettings hands the whole form back as one flat array: two
 * sections with an `enabled` toggle each would silently overwrite one another.
 */
class PluggedHostEconomyPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'plugged-host-economy';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "PluggedHost\\Economy\\Filament\\$id\\Pages");
        $panel->discoverWidgets(plugin_path($this->getId(), "src/Filament/$id/Widgets"), "PluggedHost\\Economy\\Filament\\$id\\Widgets");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $data = [];

        foreach (config('plugged-host-economy.credits', []) as $key => $value) {
            $data['credits_' . $key] = $value;
        }

        foreach (config('plugged-host-economy.tokens', []) as $key => $value) {
            $data['tokens_' . $key] = $value;
        }

        return $data;
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Credits & Resource Store')
                ->description('Credits are the resource currency: earned from Discord rewards, spent in the store on CPU, memory, disk and server slots. Caps are the ceiling a user can reach however they got there — role profile, purchase, or both.')
                ->columns(4)
                ->schema([
                    Toggle::make('credits_enabled')
                        ->label('Enable credits & resource store')
                        ->helperText('Allow users to earn credits and purchase extra server resources.')
                        ->inline(false)
                        ->columnSpanFull()
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-economy.credits.enabled', true)),
                    TextInput::make('credits_starting_balance')
                        ->label('Starting Credit Balance')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('credits')
                        ->helperText('Credits a new account starts with, granted the first time their balance is read (default: 0).')
                        ->default(fn () => config('plugged-host-economy.credits.starting_balance', 0)),
                    TextInput::make('credits_max_cpu')
                        ->label('Max Total CPU Cap')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('%')
                        ->helperText('Highest CPU limit a user can reach (default: 600%)')
                        ->default(fn () => config('plugged-host-economy.credits.max_cpu', 600)),
                    TextInput::make('credits_max_memory')
                        ->label('Max Total Memory Cap')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                        ->helperText('Highest RAM limit a user can reach (default: 24,576 MB / 24 GB)')
                        ->default(fn () => config('plugged-host-economy.credits.max_memory', 24576)),
                    TextInput::make('credits_max_disk')
                        ->label('Max Total Disk Cap')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB')
                        ->helperText('Highest Disk limit a user can reach (default: 102,400 MB / 100 GB)')
                        ->default(fn () => config('plugged-host-economy.credits.max_disk', 102400)),
                    TextInput::make('credits_price_cpu_50')
                        ->label('Price (+50% CPU)')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->default(fn () => config('plugged-host-economy.credits.price_cpu_50', 100)),
                    TextInput::make('credits_price_memory_1024')
                        ->label('Price (+1 GB RAM)')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->default(fn () => config('plugged-host-economy.credits.price_memory_1024', 150)),
                    TextInput::make('credits_price_disk_5120')
                        ->label('Price (+5 GB Disk)')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->default(fn () => config('plugged-host-economy.credits.price_disk_5120', 75)),
                    TextInput::make('credits_price_server_slot')
                        ->label('Price (+1 Server Slot)')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('credits')
                        ->default(fn () => config('plugged-host-economy.credits.price_server_slot', 250)),
                    Toggle::make('credits_reclaim_resources')
                        ->label('Repossess purchased resources on clawback')
                        ->helperText('When a reward is reversed (a Discord invite that left the server) and the credit balance cannot cover it, sell purchased resources back at the price they were bought for — only out of headroom no server is using. Off leaves resources alone and carries the whole remainder as debt.')
                        ->inline(false)
                        ->columnSpanFull()
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-economy.credits.reclaim_resources', true)),
                ]),
            Section::make('Tokens')
                ->description('Tokens are the priority currency: earned by completing a sponsored link (plugged.host Ads), spent on priority in the start queue. They are deliberately not interchangeable with credits — credits grow the Discord, tokens pay for hardware.')
                ->columns(2)
                ->schema([
                    Toggle::make('tokens_enabled')
                        ->label('Enable tokens')
                        ->helperText('Off hides every token surface and stops the queue selling priority. Balances are kept, not zeroed.')
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-economy.tokens.enabled', true)),
                    TextInput::make('tokens_daily_grant')
                        ->label('Free tokens per day')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Handed out lazily the first time a balance is read each day. 0 means tokens must be earned — at 1/day a priority pass funds itself every couple of weeks.')
                        ->default(fn () => config('plugged-host-economy.tokens.daily_grant', 0)),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'CREDITS_ENABLED' => !empty($data['credits_enabled']) ? 'true' : 'false',
            'CREDITS_STARTING_BALANCE' => (string) max(0, (int) ($data['credits_starting_balance'] ?? 0)),
            'CREDITS_MAX_TOTAL_CPU' => $data['credits_max_cpu'] ?? '600',
            'CREDITS_MAX_TOTAL_MEMORY' => $data['credits_max_memory'] ?? '24576',
            'CREDITS_MAX_TOTAL_DISK' => $data['credits_max_disk'] ?? '102400',
            'CREDITS_PRICE_CPU_50' => $data['credits_price_cpu_50'] ?? '100',
            'CREDITS_PRICE_MEMORY_1024' => $data['credits_price_memory_1024'] ?? '150',
            'CREDITS_PRICE_DISK_5120' => $data['credits_price_disk_5120'] ?? '75',
            'CREDITS_PRICE_SERVER_SLOT' => $data['credits_price_server_slot'] ?? '250',
            'CREDITS_RECLAIM_RESOURCES' => !empty($data['credits_reclaim_resources']) ? 'true' : 'false',
            'TOKENS_ENABLED' => !empty($data['tokens_enabled']) ? 'true' : 'false',
            'TOKENS_DAILY_GRANT' => $data['tokens_daily_grant'] ?? '0',
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
