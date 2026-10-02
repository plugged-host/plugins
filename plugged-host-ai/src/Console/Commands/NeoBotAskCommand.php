<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Console\Command;
use PluggedHost\Ai\Models\NeoBotMessage;
use PluggedHost\Ai\NeoBot\NeoBotAgent;
use PluggedHost\Ai\NeoBot\NeoBotConfig;
use PluggedHost\Ai\NeoBot\NeoBotSupervisor;
use PluggedHost\Ai\Services\NeoGateway;

/**
 * Ask neo on Discord something from the terminal, as a given Discord account, without Discord:
 * the answer is printed instead of posted. It is metered like a real message (against that
 * Discord account, or the account it is linked to). Works before the bot has a token.
 */
class NeoBotAskCommand extends Command
{
    protected $signature = 'plugged-host:neo-bot-ask
        {discord_id : The Discord user id to ask as}
        {message : What they write}
        {--dm : As a DM (account lookups are offered there) instead of a public channel}
        {--name= : Their Discord display name}
        {--new : Forget what was said before in this terminal conversation}';

    protected $description = 'Ask neo on Discord a question from the terminal (prints the answer)';

    public function handle(NeoBotSupervisor $supervisor, NeoBotAgent $agent, NeoGateway $gateway): int
    {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        $discordId = (string) $this->argument('discord_id');

        if (preg_match('/^\d{17,20}$/', $discordId) !== 1) {
            $this->error('That is not a Discord user id.');

            return self::FAILURE;
        }

        if (!$gateway->configured() || !NeoBotConfig::tablesExist()) {
            $this->error('neo\'s gateway is not configured, or the neo_bot_messages table is missing.');

            return self::FAILURE;
        }

        $direct = (bool) $this->option('dm');
        $conversation = NeoBotMessage::conversationKey($direct ? 'cli-dm' : 'cli', $discordId);

        if ($this->option('new')) {
            NeoBotMessage::query()->where('conversation', $conversation)->delete();
        }

        NeoBotMessage::query()->create([
            'conversation' => $conversation,
            'channel_id' => 'cli',
            'guild_id' => $direct ? null : (NeoBotConfig::guildId() ?: 'cli'),
            'author_id' => $discordId,
            'author_name' => (string) ($this->option('name') ?: 'someone'),
            'role' => NeoBotMessage::USER,
            'content' => (string) $this->argument('message'),
            'status' => NeoBotMessage::PENDING,
        ]);

        $agent->handleConversation($conversation, function (NeoBotMessage $last, string $text): void {
            $this->line($text);
        });

        return self::SUCCESS;
    }
}
