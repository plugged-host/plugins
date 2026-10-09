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

        foreach (config('plugged-host-economy.membership', []) as $key => $value) {
            $data['membership_' . $key] = $value;
        }

        return $data;
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Credits & Resource Store')
                ->description('Credits are the resource currency: earned from Discord rewards, spent in the store on CPU, memory and disk. Caps and prices are set on noprofit.space (Admin › Settings).')
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
                ->description('Tokens are the membership currency: earned by completing a sponsored link (plugged.host Ads), spent on plugged.host Plus and PlusPlus. They are deliberately not interchangeable with credits — credits grow the Discord, tokens pay for hardware.')
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
            Section::make('plugged.host Plus & PlusPlus')
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

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
