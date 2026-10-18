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
use PluggedHost\Economy\Contracts\ComputePlans;

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

        foreach (self::COMPUTE_FIELDS as $field => [$key, $env, $default]) {
            $data[$field] = (int) config("plugged-host-economy.compute.$key", $default);
        }

        foreach (config('plugged-host-economy.membership', []) as $key => $value) {
            $data['membership_' . $key] = $value;
        }

        return $data;
    }

    /**
     * The plan settings this panel keeps when no other system sets them (ComputePlans): form
     * field => [config key under compute, env name, default].
     */
    private const COMPUTE_FIELDS = [
        'compute_default_cpu' => ['tiers.default.cpu', 'COMPUTE_DEFAULT_CPU', 400],
        'compute_default_memory' => ['tiers.default.memory', 'COMPUTE_DEFAULT_MEMORY', 12288],
        'compute_default_disk' => ['tiers.default.disk', 'COMPUTE_DEFAULT_DISK', 51200],
        'compute_discord_cpu' => ['tiers.discord.cpu', 'COMPUTE_DISCORD_CPU', 400],
        'compute_discord_memory' => ['tiers.discord.memory', 'COMPUTE_DISCORD_MEMORY', 16384],
        'compute_discord_disk' => ['tiers.discord.disk', 'COMPUTE_DISCORD_DISK', 51200],
        'compute_max_cpu' => ['max_cpu', 'COMPUTE_MAX_CPU', 600],
        'compute_max_memory' => ['max_memory', 'COMPUTE_MAX_MEMORY', 24576],
        'compute_max_disk' => ['max_disk', 'COMPUTE_MAX_DISK', 102400],
        'compute_cpu_step' => ['pricing.cpu_unit_percent', 'COMPUTE_CPU_STEP', 50],
        'compute_cpu_price' => ['pricing.cpu_price_credits', 'COMPUTE_CPU_PRICE', 100],
        'compute_memory_step' => ['pricing.memory_unit_mb', 'COMPUTE_MEMORY_STEP', 1024],
        'compute_memory_price' => ['pricing.memory_price_credits', 'COMPUTE_MEMORY_PRICE', 150],
        'compute_disk_step' => ['pricing.storage_unit_mb', 'COMPUTE_DISK_STEP', 5120],
        'compute_disk_price' => ['pricing.storage_price_credits', 'COMPUTE_DISK_PRICE', 75],
        'compute_starting_credits' => ['starting_credits', 'CREDITS_STARTING_BALANCE', 0],
    ];

    public function getSettingsForm(): array
    {
        $number = fn (string $field, string $label, ?string $suffix = null) => TextInput::make($field)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->suffix($suffix)
            ->required();

        return [
            Section::make('Plans & store prices')
                ->description('What every account may use, and what more costs in the store. CPU in %, memory and disk in MB. The Discord tier applies while the account\'s linked Discord account is in the server.')
                ->visible(fn () => app(ComputePlans::class)->editableHere())
                ->columns(3)
                ->schema([
                    $number('compute_default_cpu', 'Default tier: CPU', '%'),
                    $number('compute_default_memory', 'Default tier: memory', 'MB'),
                    $number('compute_default_disk', 'Default tier: disk', 'MB'),
                    $number('compute_discord_cpu', 'Discord tier: CPU', '%'),
                    $number('compute_discord_memory', 'Discord tier: memory', 'MB'),
                    $number('compute_discord_disk', 'Discord tier: disk', 'MB'),
                    $number('compute_max_cpu', 'Limit: CPU', '%'),
                    $number('compute_max_memory', 'Limit: memory', 'MB'),
                    $number('compute_max_disk', 'Limit: disk', 'MB'),
                    $number('compute_cpu_step', 'CPU sold per step', '%'),
                    $number('compute_memory_step', 'Memory sold per step', 'MB'),
                    $number('compute_disk_step', 'Disk sold per step', 'MB'),
                    $number('compute_cpu_price', 'CPU step price', 'credits'),
                    $number('compute_memory_price', 'Memory step price', 'credits'),
                    $number('compute_disk_price', 'Disk step price', 'credits'),
                    $number('compute_starting_credits', 'Starting credits for new accounts', 'credits'),
                ]),
            Section::make('Credits & Resource Store')
                ->description(fn () => 'Credits are the resource currency: earned from Discord rewards, spent in the store on CPU, memory and disk.'
                    . (app(ComputePlans::class)->editableHere() ? '' : ' Plans and prices are set on noprofit.space (Admin › Settings).'))
                ->columns(2)
                ->schema([
                    Toggle::make('credits_enabled')
                        ->label('Enable credits & resource store')
                        ->helperText('Allow users to earn credits and purchase extra server resources.')
                        ->inline(false)
                        ->columnSpanFull()
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-economy.credits.enabled', true)),
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
                ->description('Tokens are the membership currency: earned by completing a sponsored link (plugged.host Ads), spent on ' . config('app.name') . ' Plus and PlusPlus. They are deliberately not interchangeable with credits — credits grow the Discord, tokens pay for hardware.')
                ->columns(2)
                ->schema([
                    Toggle::make('tokens_enabled')
                        ->label('Enable tokens')
                        ->helperText('Off hides every token surface and stops selling Plus and PlusPlus. Balances are kept, not zeroed.')
                        ->inline(false)
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-economy.tokens.enabled', true)),
                    TextInput::make('tokens_daily_grant')
                        ->label('Free tokens per day')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Handed out lazily the first time a balance is read each day. 0 means tokens must be earned. At 1/day a month buys Plus.')
                        ->default(fn () => config('plugged-host-economy.tokens.daily_grant', 0)),
                ]),
            Section::make(config('app.name') . ' Plus & PlusPlus')
                ->description('Plus is bought once with tokens: Plus locations, priority in the start queue, no in-game ads. PlusPlus needs Plus and is bought for days at a time with tokens or credits: Premium locations, no ads anywhere, the front of the queue, extra CPU and more neo (AI) usage. When it runs out, servers leave Premium locations and CPU over the allowance comes off evenly.')
                ->columns(2)
                ->schema([
                    Toggle::make('membership_enabled')
                        ->label('Sell Plus and PlusPlus')
                        ->helperText('Off stops selling. What people already have keeps working.')
                        ->inline(false)
                        ->columnSpanFull()
                        ->onIcon(TablerIcon::Check)
                        ->offIcon(TablerIcon::X)
                        ->default(fn () => config('plugged-host-economy.membership.enabled', true)),
                    TextInput::make('membership_plus_token_cost')->label('Plus (tokens, once)')->required()->numeric()->minValue(0)
                        ->default(fn () => config('plugged-host-economy.membership.plus_token_cost', 25)),
                    TextInput::make('membership_plusplus_cpu_bonus')->label('PlusPlus CPU bonus (%)')->required()->numeric()->minValue(0)->maxValue(1000)
                        ->default(fn () => config('plugged-host-economy.membership.plusplus_cpu_bonus', 50)),
                    TextInput::make('membership_plus_ai_percent')->label('Plus neo (AI) usage (% of free)')->required()->numeric()->minValue(100)->maxValue(1000)
                        ->default(fn () => config('plugged-host-economy.membership.plus_ai_percent', 125)),
                    TextInput::make('membership_plusplus_ai_percent')->label('PlusPlus neo (AI) usage (% of Plus)')->required()->numeric()->minValue(100)->maxValue(1000)
                        ->default(fn () => config('plugged-host-economy.membership.plusplus_ai_percent', 125)),
                    TextInput::make('membership_plusplus_token_cost')->label('PlusPlus price in tokens')->required()->numeric()->minValue(0)
                        ->default(fn () => config('plugged-host-economy.membership.plusplus_token_cost', 10)),
                    TextInput::make('membership_plusplus_token_days')->label('Days for the token price')->required()->numeric()->minValue(1)
                        ->default(fn () => config('plugged-host-economy.membership.plusplus_token_days', 7)),
                    TextInput::make('membership_plusplus_credit_cost')->label('PlusPlus price in credits')->required()->numeric()->minValue(0)
                        ->default(fn () => config('plugged-host-economy.membership.plusplus_credit_cost', 150)),
                    TextInput::make('membership_plusplus_credit_days')->label('Days for the credit price')->required()->numeric()->minValue(1)
                        ->default(fn () => config('plugged-host-economy.membership.plusplus_credit_days', 14)),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'CREDITS_ENABLED' => !empty($data['credits_enabled']) ? 'true' : 'false',
            'CREDITS_RECLAIM_RESOURCES' => !empty($data['credits_reclaim_resources']) ? 'true' : 'false',
            'TOKENS_ENABLED' => !empty($data['tokens_enabled']) ? 'true' : 'false',
            'TOKENS_DAILY_GRANT' => $data['tokens_daily_grant'] ?? '0',
            'MEMBERSHIP_ENABLED' => !empty($data['membership_enabled']) ? 'true' : 'false',
            'PLUS_TOKEN_COST' => (string) max(0, (int) ($data['membership_plus_token_cost'] ?? 25)),
            'PLUSPLUS_TOKEN_COST' => (string) max(0, (int) ($data['membership_plusplus_token_cost'] ?? 10)),
            'PLUSPLUS_TOKEN_DAYS' => (string) max(1, (int) ($data['membership_plusplus_token_days'] ?? 7)),
            'PLUSPLUS_CREDIT_COST' => (string) max(0, (int) ($data['membership_plusplus_credit_cost'] ?? 150)),
            'PLUSPLUS_CREDIT_DAYS' => (string) max(1, (int) ($data['membership_plusplus_credit_days'] ?? 14)),
            'PLUSPLUS_CPU_BONUS' => (string) max(0, (int) ($data['membership_plusplus_cpu_bonus'] ?? 50)),
            'PLUS_AI_PERCENT' => (string) max(100, min(1000, (int) ($data['membership_plus_ai_percent'] ?? 125))),
            'PLUSPLUS_AI_PERCENT' => (string) max(100, min(1000, (int) ($data['membership_plusplus_ai_percent'] ?? 125))),
        ]);

        if (app(ComputePlans::class)->editableHere()) {
            $compute = [];

            foreach (self::COMPUTE_FIELDS as $field => [, $env, $default]) {
                $compute[$env] = (string) max(0, (int) ($data[$field] ?? $default));
            }

            $this->writeToEnvironment($compute);
        }

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
