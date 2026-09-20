<?php

namespace PluggedHost\Discord\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PluggedHost\Discord\Services\DiscordVerificationService;

class DiscordRegisterCommandsCommand extends Command
{
    protected $signature = 'plugged-host:discord-register-commands
                            {--global : Register globally instead of per-guild}';

    protected $description = 'Register Discord bot slash commands (/invites, /topinvites) with Discord API';

    public function handle(DiscordVerificationService $service): int
    {
        $this->info('Registering Discord slash commands...');

        $botToken = $service->getBotToken();
        $guildId = $service->getGuildId();

        if (empty($botToken)) {
            $this->error('Discord Bot Token is not configured. Please set DISCORD_BOT_TOKEN in .env or plugin settings.');

            return self::FAILURE;
        }

        // Get Bot Application ID from Discord API
        try {
            $appResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
            ])->get(DiscordVerificationService::DISCORD_API_BASE . '/users/@me');

            if (!$appResponse->successful()) {
                $this->error("Failed to authenticate bot token with Discord API: " . $appResponse->body());

                return self::FAILURE;
            }

            $appId = (string) $appResponse->json('id');
            $botName = $appResponse->json('username');
            $this->info("Connected as bot: {$botName} (Application ID: {$appId})");
        } catch (Exception $e) {
            $this->error("Failed to connect to Discord API: {$e->getMessage()}");

            return self::FAILURE;
        }

        $commands = [
            [
                'name' => 'invites',
                'description' => 'Check counted invites, pending rewards and credits — members who leave stop counting',
                'options' => [
                    [
                        'name' => 'user',
                        'description' => 'The user whose invite statistics you want to check (defaults to you)',
                        'type' => 6, // USER type
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'topinvites',
                'description' => 'Invite leaderboard with paid and pending credits — members who left do not count',
                'options' => [
                    [
                        'name' => 'limit',
                        'description' => 'Number of top members to show (default: 10, max: 25)',
                        'type' => 4, // INTEGER type
                        'required' => false,
                    ],
                ],
            ],
            [
                'name' => 'setboost',
                'description' => 'Set a member\'s Discord server boost count and award credits (Admin only)',
                'default_member_permissions' => '8',
                'options' => [
                    [
                        'name' => 'user',
                        'description' => 'The member whose boost count to set',
                        'type' => 6,
                        'required' => true,
                    ],
                    [
                        'name' => 'count',
                        'description' => 'Number of server boosts (1 or 2)',
                        'type' => 4,
                        'required' => true,
                        'choices' => [
                            ['name' => '2 Boosts (Tier 2)', 'value' => 2],
                            ['name' => '1 Boost (Tier 1)', 'value' => 1],
                            ['name' => '0 Boosts (Remove)', 'value' => 0],
                        ],
                    ],
                ],
            ],
        ];

        $isGlobal = $this->option('global') || empty($guildId);
        $endpoint = $isGlobal
            ? DiscordVerificationService::DISCORD_API_BASE . "/applications/{$appId}/commands"
            : DiscordVerificationService::DISCORD_API_BASE . "/applications/{$appId}/guilds/{$guildId}/commands";

        $target = $isGlobal ? 'Globally' : "Guild {$guildId}";
        $this->info("Registering slash commands {$target}...");

        $successCount = 0;
        foreach ($commands as $cmd) {
            $this->line("Registering /{$cmd['name']}...");
            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
                ])->post($endpoint, $cmd);

                if ($response->successful()) {
                    $this->info(" ✔ Successfully registered /{$cmd['name']} (ID: " . $response->json('id') . ")");
                    $successCount++;
                } else {
                    $this->error(" ✖ Failed to register /{$cmd['name']}: HTTP {$response->status()} - " . $response->body());
                }
            } catch (Exception $e) {
                $this->error(" ✖ Error registering /{$cmd['name']}: {$e->getMessage()}");
            }
        }

        return $successCount === count($commands) ? self::SUCCESS : self::FAILURE;
    }
}
