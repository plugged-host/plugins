<?php

namespace Boy132\Subdomains;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

use Filament\Schemas\Components\Section;

class SubdomainsPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'subdomains';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Boy132\\Subdomains\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Boy132\\Subdomains\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $data = config('subdomains');

        $blacklistString = $data['subdomain_blacklist'] ?? $data['blacklist'] ?? '';
        $data['blacklist'] = array_filter(explode(',', is_string($blacklistString) ? $blacklistString : ''));
        // Secret: never surface the stored Cloudflare API token. Empty means "unchanged" on save.
        $data['token'] = '';
        $data['default_limit'] = (int) ($data['default_limit'] ?? 3);
        $data['require_discord'] = (bool) ($data['require_discord'] ?? true);
        $data['require_discord_guild'] = (bool) ($data['require_discord_guild'] ?? true);
        $data['dual_records'] = (bool) ($data['dual_records'] ?? true);
        $data['supported_eggs'] = is_array($data['supported_eggs'] ?? null)
            ? implode(',', $data['supported_eggs'])
            : (string) ($data['supported_eggs'] ?? '11,12');
        $data['minecraft_eggs'] = is_array($data['egg_mappings']['minecraft'] ?? null)
            ? implode(',', $data['egg_mappings']['minecraft'])
            : (string) ($data['egg_mappings']['minecraft'] ?? '12');
        $data['fivem_eggs'] = is_array($data['egg_mappings']['fivem'] ?? null)
            ? implode(',', $data['egg_mappings']['fivem'])
            : (string) ($data['egg_mappings']['fivem'] ?? '11');
        $data['token_cost_1'] = (int) ($data['token_costs'][1] ?? 1);
        $data['token_cost_2'] = (int) ($data['token_costs'][2] ?? 3);
        $data['token_cost_3'] = (int) ($data['token_costs'][3] ?? 8);
        $data['token_cost_default'] = (int) ($data['token_costs']['default'] ?? 15);

        return $data;
    }

    public function getSettingsForm(): array
    {
        $hasToken = filled(config('subdomains.token'));

        return [
            Section::make('Cloudflare & Infrastructure')
                ->description('Configure your Cloudflare credentials and default server subdomain limits.')
                ->columns(2)
                ->schema([
                    TextInput::make('token')
                        ->label(trans('subdomains::strings.api_token'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('subdomains::strings.api_token_help'))
                        ->columnSpanFull()
                        ->placeholder($hasToken ? '•••••••• ' . trans('subdomains::strings.secret_unchanged') : trans('subdomains::strings.secret_unset'))
                        ->helperText(trans('subdomains::strings.' . ($hasToken ? 'api_token_set_help' : 'api_token_unset_help'))),
                    TextInput::make('default_limit')
                        ->label('Default Subdomain Limit')
                        ->helperText('Maximum subdomains allowed per server by default.')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->columnSpanFull()
                        ->default(fn () => (int) config('subdomains.default_limit', 3)),
                    TextInput::make('supported_eggs')
                        ->label('Supported Egg IDs')
                        ->helperText('Comma-separated list of Egg IDs where subdomains are enabled (default: 11,12). The subdomains page is hidden for any other egg.')
                        ->columnSpanFull()
                        ->default(fn () => implode(',', config('subdomains.supported_eggs', [11, 12]))),
                    Toggle::make('require_discord')
                        ->label('Require Linked Discord Account')
                        ->helperText('Require the server owner to have a linked Discord account to create subdomains.')
                        ->columnSpanFull()
                        ->default(fn () => (bool) config('subdomains.require_discord', true)),
                    Toggle::make('require_discord_guild')
                        ->label('Require Discord Server Membership')
                        ->helperText('Require the server owner to be a member of your Discord server.')
                        ->columnSpanFull()
                        ->default(fn () => (bool) config('subdomains.require_discord_guild', true)),
                    Toggle::make('dual_records')
                        ->label(trans('subdomains::strings.dual_records'))
                        ->helperText(trans('subdomains::strings.dual_records_help'))
                        ->columnSpanFull()
                        ->default(fn () => (bool) config('subdomains.dual_records', true)),
                    TagsInput::make('blacklist')
                        ->label(trans('subdomains::strings.subdomain_blacklist'))
                        ->hintIcon('tabler-question-mark')
                        ->hintIconTooltip(trans('subdomains::strings.subdomain_blacklist_help'))
                        ->columnSpanFull()
                        ->default(fn () => array_filter(explode(',', config('subdomains.subdomain_blacklist')))),
                ]),

            Section::make('Token Pricing & Deposits')
                ->description('Configure the refundable token deposit for each subdomain tier. Deposits are refunded automatically when a subdomain or server is deleted.')
                ->columns(2)
                ->schema([
                    TextInput::make('token_cost_1')
                        ->label('1st Subdomain Cost')
                        ->helperText('Tokens for the 1st subdomain on a server.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->suffix('tokens')
                        ->default(fn () => (int) config('subdomains.token_costs.1', 1)),
                    TextInput::make('token_cost_2')
                        ->label('2nd Subdomain Cost')
                        ->helperText('Tokens for the 2nd subdomain on a server.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->suffix('tokens')
                        ->default(fn () => (int) config('subdomains.token_costs.2', 3)),
                    TextInput::make('token_cost_3')
                        ->label('3rd Subdomain Cost')
                        ->helperText('Tokens for the 3rd subdomain on a server.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->suffix('tokens')
                        ->default(fn () => (int) config('subdomains.token_costs.3', 8)),
                    TextInput::make('token_cost_default')
                        ->label('4th+ Subdomain Cost')
                        ->helperText('Tokens for any subsequent subdomains beyond the 3rd.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->suffix('tokens')
                        ->default(fn () => (int) config('subdomains.token_costs.default', 15)),
                ]),

            Section::make(trans('subdomains::strings.srv_service_section'))
                ->description(trans('subdomains::strings.srv_service_section_help'))
                ->columns(2)
                ->schema([
                    TextInput::make('minecraft_eggs')
                        ->label(trans('subdomains::strings.minecraft_eggs'))
                        ->helperText(trans('subdomains::strings.minecraft_eggs_help'))
                        ->columnSpanFull()
                        ->default(fn () => implode(',', config('subdomains.egg_mappings.minecraft', [12]))),
                    TextInput::make('fivem_eggs')
                        ->label(trans('subdomains::strings.fivem_eggs'))
                        ->helperText(trans('subdomains::strings.fivem_eggs_help'))
                        ->columnSpanFull()
                        ->default(fn () => implode(',', config('subdomains.egg_mappings.fivem', [11]))),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $blacklist = is_array($data['blacklist'] ?? null)
            ? implode(',', $data['blacklist'])
            : (string) ($data['blacklist'] ?? '');

        $normalizeEggs = static function ($value, string $fallback): string {
            $ids = array_filter(array_map('intval', explode(',', (string) $value)));
            return $ids === [] ? $fallback : implode(',', $ids);
        };

        $values = [
            'SUBDOMAINS_BLACKLIST' => $blacklist,
            'SUBDOMAINS_DEFAULT_LIMIT' => max(1, min(50, (int) ($data['default_limit'] ?? 3))),
            'SUBDOMAINS_SUPPORTED_EGGS' => $normalizeEggs($data['supported_eggs'] ?? '11,12', '11,12'),
            'SUBDOMAINS_MINECRAFT_EGGS' => $normalizeEggs($data['minecraft_eggs'] ?? '12', '12'),
            'SUBDOMAINS_FIVEM_EGGS' => $normalizeEggs($data['fivem_eggs'] ?? '11', '11'),
            'SUBDOMAINS_DUAL_RECORDS' => !empty($data['dual_records']) ? 'true' : 'false',
            'SUBDOMAINS_REQUIRE_DISCORD' => !empty($data['require_discord']) ? 'true' : 'false',
            'SUBDOMAINS_REQUIRE_DISCORD_GUILD' => !empty($data['require_discord_guild']) ? 'true' : 'false',
            'SUBDOMAINS_TOKEN_COST_1' => max(0, min(1000, (int) ($data['token_cost_1'] ?? 1))),
            'SUBDOMAINS_TOKEN_COST_2' => max(0, min(1000, (int) ($data['token_cost_2'] ?? 3))),
            'SUBDOMAINS_TOKEN_COST_3' => max(0, min(1000, (int) ($data['token_cost_3'] ?? 8))),
            'SUBDOMAINS_TOKEN_COST_DEFAULT' => max(0, min(1000, (int) ($data['token_cost_default'] ?? 15))),
        ];

        // Save-if-changed: only rewrite the Cloudflare API token when the admin actually typed one.
        $token = trim((string) ($data['token'] ?? ''));
        if ($token !== '') {
            $values['SUBDOMAINS_CLOUDFLARE_TOKEN'] = $token;
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
