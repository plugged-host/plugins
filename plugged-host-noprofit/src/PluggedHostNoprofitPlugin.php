<?php

namespace PluggedHost\Noprofit;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use PluggedHost\Noprofit\Http\Middleware\RequireNoprofitLink;

/**
 * noprofit.space as the panel's account system. Everything noprofit-specific lives in this
 * plugin; the other plugged.host plugins work without it (Pelican's own sign-in, panel-local
 * credits and plans, neo with its own providers) and this one swaps their defaults for
 * noprofit.space's (Providers\NoprofitBindingsServiceProvider).
 *
 * The URLs noprofit.space calls (/sso/callback, /migrate, /api/noprofit/*) and every env name
 * are what they were in plugged-host-core before 2.2.
 */
class PluggedHostNoprofitPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    /** Secret settings: a blank field keeps the current value, so none is ever echoed back. */
    private const SECRETS = [
        'sso_secret' => 'NOPROFIT_SSO_SECRET',
        'migration_secret' => 'NOPROFIT_MIGRATION_SECRET',
        'sync_secret' => 'NOPROFIT_SYNC_SECRET',
    ];

    public function getId(): string
    {
        return 'plugged-host-noprofit';
    }

    public function register(Panel $panel): void
    {
        // A panel session of an account not linked to noprofit.space yet (from before the
        // move) goes through noprofit.space once.
        $panel->authMiddleware([
            RequireNoprofitLink::class,
        ]);
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'accounts_url' => (string) config('plugged-host-noprofit.accounts_url'),
            'sso_secret' => '',
            'migration_secret' => '',
            'sync_secret' => '',
            'resolve_ip' => (string) config('plugged-host-noprofit.resolve_ip'),
            'ca_bundle' => (string) config('plugged-host-noprofit.ca_bundle'),
        ];
    }

    public function getSettingsForm(): array
    {
        $secret = fn (string $field, string $label) => TextInput::make($field)
            ->label($label)
            ->helperText(filled(config("plugged-host-noprofit.$field"))
                ? 'Set. Leave empty to keep it.'
                : 'Not set: every token of this kind is refused.')
            ->password()
            ->revealable()
            ->autocomplete(false);

        return [
            Section::make('noprofit.space')
                ->description('Each secret must be the same value on noprofit.space (accounts .env).')
                ->columns(2)
                ->schema([
                    TextInput::make('accounts_url')
                        ->label('Accounts URL')
                        ->url()
                        ->required()
                        ->columnSpanFull(),
                    $secret('sso_secret', 'Sign-in secret (NOPROFIT_SSO_SECRET)'),
                    $secret('migration_secret', 'Account secret (NOPROFIT_MIGRATION_SECRET)'),
                    $secret('sync_secret', 'Sync secret (NOPROFIT_SYNC_SECRET)'),
                    TextInput::make('resolve_ip')
                        ->label('Resolve the accounts host to (optional)')
                        ->helperText('e.g. 127.0.0.1 when noprofit.space runs on this box, so internal calls skip Cloudflare.'),
                    TextInput::make('ca_bundle')
                        ->label('CA bundle for that connection (optional)')
                        ->helperText('Path to the Cloudflare Origin CA bundle when the IP above is used.'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $environment = [
            'NOPROFIT_ACCOUNTS_URL' => rtrim(trim((string) ($data['accounts_url'] ?? '')), '/'),
            'NOPROFIT_RESOLVE_IP' => trim((string) ($data['resolve_ip'] ?? '')),
            'NOPROFIT_CA_BUNDLE' => trim((string) ($data['ca_bundle'] ?? '')),
        ];

        foreach (self::SECRETS as $field => $env) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '') {
                $environment[$env] = $value;
            }
        }

        $this->writeToEnvironment($environment);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
