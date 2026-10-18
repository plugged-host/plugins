<?php

namespace PluggedHost\Ai\NeoBot;

use Carbon\Carbon;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;

/**
 * neo on Discord's part of the plugged.host AI settings (its own tab, form state under
 * "neo_bot"): read from config, written to the panel's .env by PluggedHostAiPlugin.
 */
final class NeoBotSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [
            'enabled' => (bool) config('plugged-host-ai.neo_bot.enabled', false),
            // Secret: never shown again. Empty on save means unchanged.
            'bot_token' => '',
            'guild_id' => NeoBotConfig::guildId(),
        ];
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function form(): array
    {
        $t = fn (string $key): string => trans('plugged-host-ai::strings.neo_bot.settings.' . $key, ['app' => config('app.name')]);

        return [
            self::statusCallout(),
            Section::make($t('section'))
                ->description($t('section_hint'))
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label($t('enabled'))
                        ->columnSpanFull(),
                    TextInput::make('bot_token')
                        ->label($t('bot_token'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->nullable()
                        ->columnSpanFull()
                        ->placeholder(NeoBotConfig::token() !== '' ? $t('token_saved') : $t('token_unset'))
                        ->helperText($t('bot_token_hint')),
                    TextInput::make('guild_id')
                        ->label($t('guild_id'))
                        ->required()
                        ->regex('/^\d{17,20}$/')
                        ->helperText($t('guild_id_hint')),
                ]),
        ];
    }

    /**
     * The .env values for what the form sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function env(array $data): array
    {
        $values = [
            'NEO_BOT_ENABLED' => !empty($data['enabled']) ? 'true' : 'false',
            'NEO_BOT_GUILD_ID' => trim((string) ($data['guild_id'] ?? '')),
        ];

        $token = trim((string) ($data['bot_token'] ?? ''));
        if ($token !== '') {
            $values['NEO_BOT_TOKEN'] = $token;
        }

        return $values;
    }

    private static function statusCallout(): Callout
    {
        $t = fn (string $key, array $replace = []): string => trans('plugged-host-ai::strings.neo_bot.settings.' . $key, $replace);
        $blockers = NeoBotConfig::blockers();

        if ($blockers !== []) {
            return Callout::make($t('status_off'))
                ->warning()
                ->description(implode(' ', $blockers));
        }

        $supervisor = app(NeoBotSupervisor::class);
        $status = $supervisor->readStatus();
        $invite = NeoBotConfig::inviteUrl();
        $inviteLine = $invite !== null ? ' ' . $t('invite', ['url' => $invite]) : '';

        if (!$supervisor->isRunning()) {
            return Callout::make($t('status_starting'))
                ->info()
                ->description($t('status_starting_hint') . $inviteLine);
        }

        if (($status['message_content'] ?? true) === false) {
            return Callout::make($t('status_no_content'))
                ->warning()
                ->description($t('status_no_content_hint'));
        }

        $since = (int) ($status['ready_at'] ?? 0);

        return Callout::make($t('status_online'))
            ->success()
            ->description($t('connected_as', [
                'name' => (string) ($status['bot_username'] ?? 'neo'),
                'since' => $since > 0 ? Carbon::createFromTimestamp($since)->diffForHumans() : '?',
            ]) . $inviteLine);
    }
}
