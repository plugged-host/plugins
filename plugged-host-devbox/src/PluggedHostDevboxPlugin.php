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
            'enabled' => (bool) config('plugged-host-devbox.enabled', true),
            'image' => (string) config('plugged-host-devbox.image'),
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
                        ->helperText('Off hides "Open in VS Code" and stops new devboxes; running ones stop at the next reconcile.'),
                    TextInput::make('image')
                        ->label('Image')
                        ->helperText('What Wings runs. A leading ~ uses a local image without pulling.')
                        ->required()
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
        if ($image !== '' && preg_match('/^~?[A-Za-z0-9._\/:@-]+$/', $image)) {
            $environment['DEVBOX_IMAGE'] = $image;
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
