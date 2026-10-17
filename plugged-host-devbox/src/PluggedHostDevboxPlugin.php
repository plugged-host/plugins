<?php

namespace PluggedHost\Devbox;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Core\Support\Release;
use PluggedHost\Devbox\Services\DevboxProvisioner;

/**
 * Devboxes: VS Code on the server for Plus (see Providers\DevboxPluginProvider). Its UI is the
 * "Open in VS Code" part of the server settings' VS Code section (Livewire\OpenInVsCode); the
 * endpoints, schedule and cleanup run regardless of panel. Settings round-trip through .env.
 */
class PluggedHostDevboxPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    /** setting => [env, config key, min, max] */
    private const NUMBERS = [
        'plus_cpu' => ['DEVBOX_PLUS_CPU', 'tiers.plus.cpu', 50, 1600],
        'plus_memory' => ['DEVBOX_PLUS_MEMORY', 'tiers.plus.memory', 512, 65536],
        'plus_disk' => ['DEVBOX_PLUS_DISK', 'tiers.plus.disk', 1024, 262144],
        'plusplus_cpu' => ['DEVBOX_PLUSPLUS_CPU', 'tiers.plusplus.cpu', 50, 1600],
        'plusplus_memory' => ['DEVBOX_PLUSPLUS_MEMORY', 'tiers.plusplus.memory', 512, 65536],
        'plusplus_disk' => ['DEVBOX_PLUSPLUS_DISK', 'tiers.plusplus.disk', 1024, 262144],
        'idle_minutes' => ['DEVBOX_IDLE_MINUTES', 'idle_minutes', 1, 1440],
        'max_hours' => ['DEVBOX_MAX_HOURS', 'max_hours', 1, 168],
        'running_per_user' => ['DEVBOX_RUNNING_PER_USER', 'running_per_user', 1, 20],
        'cleanup_days' => ['DEVBOX_CLEANUP_DAYS', 'cleanup_days', 1, 365],
    ];

    public function getId(): string
    {
        return 'plugged-host-devbox';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $data = [
            'enabled' => (bool) config('plugged-host-devbox.enabled', false),
            'image' => (string) config('plugged-host-devbox.image'),
            'web_domain' => (string) config('plugged-host-devbox.web_domain'),
            'web_origin_ip' => (string) config('plugged-host-devbox.web_origin_ip'),
            // Not round-tripped: a secret; empty keeps the current one.
            'cloudflare_token' => '',
        ];

        foreach (self::NUMBERS as $field => [, $key]) {
            $data[$field] = (int) config("plugged-host-devbox.{$key}");
        }

        return $data;
    }

    public function getSettingsForm(): array
    {
        $number = fn (string $field, string $label, ?string $suffix = null) => TextInput::make($field)
            ->label($label)
            ->numeric()
            ->minValue(self::NUMBERS[$field][2])
            ->maxValue(self::NUMBERS[$field][3])
            ->suffix($suffix)
            ->required();

        return [
            Section::make('Devboxes')
                ->description('VS Code on the server for Plus: a private Linux box next to a game server, with its files at /server. Nodes need the volumes in Wings\' allowed_mounts (update-nodes.mjs --devbox).')
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label('Devboxes on')
                        ->helperText('Off hides "Open in VS Code" and stops new devboxes; running ones stop at the next reconcile. Needs plugged-host-abuse enabled, and the node firewall from the plugin\'s README on every node first.'),
                    TextInput::make('image')
                        ->label('Image')
                        ->placeholder(fn (): string => DevboxProvisioner::IMAGE_REPOSITORY . ':' . Release::version())
                        ->helperText('What Wings runs. Empty: this release\'s tag. A digest (…@sha256:…) pins one exact build; a leading ~ uses a local image without pulling. Applies at each devbox\'s next start.')
                        ->maxLength(255),
                ]),
            Section::make('Plus')
                ->description('Per devbox, on top of the game server\'s own resources. New limits apply to devboxes created afterwards.')
                ->columns(3)
                ->schema([
                    $number('plus_cpu', 'CPU', '%'),
                    $number('plus_memory', 'Memory', 'MiB'),
                    $number('plus_disk', 'Disk', 'MiB'),
                ]),
            Section::make('PlusPlus')
                ->columns(3)
                ->schema([
                    $number('plusplus_cpu', 'CPU', '%'),
                    $number('plusplus_memory', 'Memory', 'MiB'),
                    $number('plusplus_disk', 'Disk', 'MiB'),
                ]),
            Section::make('In the browser')
                ->description('VS Code in the browser at https://<devbox>.<domain>, through the edge (nginx on the panel box, docs/templates/nginx/devbox-web.conf). Never a plugged.host domain: the panels\' session cookie covers all of it. After changing it, run php artisan plugged-host:devbox-web-setup.')
                ->columns(2)
                ->schema([
                    TextInput::make('web_domain')
                        ->label('Domain')
                        ->placeholder('onplugged.host')
                        ->helperText('Empty switches the browser version off.')
                        ->rules(['nullable', 'regex:/^([a-z0-9-]+\\.)+[a-z]{2,}$/i', 'not_regex:/(^|\\.)plugged\\.host$/i']),
                    TextInput::make('web_origin_ip')
                        ->label('Edge IPv4')
                        ->helperText('Public address of the panel box; each devbox\'s DNS record points here.')
                        ->rules(['nullable', 'ipv4']),
                    TextInput::make('cloudflare_token')
                        ->label('Cloudflare API token')
                        ->helperText(filled(config('plugged-host-devbox.cloudflare_token'))
                            ? 'Set. Leave empty to keep it. Zone DNS edit on the domain\'s zone.'
                            : 'Zone DNS edit on the domain\'s zone, for the devbox DNS records. Needed because the subdomains plugin (whose token is used otherwise) isn\'t installed.')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->visible(fn (): bool => !Plugins::enabled('subdomains'))
                        ->required(fn (callable $get): bool => filled($get('web_domain')) && blank(config('plugged-host-devbox.cloudflare_token'))),
                ]),
            Section::make('Lifecycle')
                ->columns(2)
                ->schema([
                    $number('idle_minutes', 'Stop after no connection for', 'minutes'),
                    $number('max_hours', 'Stop after running for', 'hours'),
                    $number('running_per_user', 'Running at once per person'),
                    $number('cleanup_days', 'Delete when unused for', 'days'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $environment = [
            'DEVBOX_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
        ];

        $image = trim((string) ($data['image'] ?? ''));
        if ($image === '' || preg_match('/^~?[A-Za-z0-9._\/:@-]+$/', $image)) {
            $environment['DEVBOX_IMAGE'] = $image; // empty: back to this release's tag
        }

        $domain = strtolower(trim((string) ($data['web_domain'] ?? ''), '. '));
        if ($domain === '' || (preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain) && $domain !== 'plugged.host' && !str_ends_with($domain, '.plugged.host'))) {
            $environment['DEVBOX_WEB_DOMAIN'] = $domain;
        }
        $ip = trim((string) ($data['web_origin_ip'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $environment['DEVBOX_WEB_ORIGIN_IP'] = $ip;
        }
        $token = trim((string) ($data['cloudflare_token'] ?? ''));
        if ($token !== '') {
            $environment['DEVBOX_CLOUDFLARE_TOKEN'] = $token;
        }

        foreach (self::NUMBERS as $field => [$env, $key, $min, $max]) {
            $environment[$env] = (string) max($min, min($max, (int) ($data[$field] ?? config("plugged-host-devbox.{$key}"))));
        }

        $this->writeToEnvironment($environment);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
