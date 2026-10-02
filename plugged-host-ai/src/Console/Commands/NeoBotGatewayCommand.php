<?php

namespace PluggedHost\Ai\Console\Commands;

use PluggedHost\Ai\Discord\BotSupervisor;
use PluggedHost\Ai\Discord\GatewayDaemon;
use PluggedHost\Ai\NeoBot\NeoBotConfig;
use PluggedHost\Ai\NeoBot\NeoBotInbox;
use PluggedHost\Ai\NeoBot\NeoBotSupervisor;

/**
 * neo's Discord gateway daemon (the connection itself is GatewayDaemon's). A message for neo is
 * stored and handed to a detached worker (plugged-host:neo-bot-handle), because an answer takes
 * seconds and the socket must keep heartbeating. Supervised by plugged-host:neo-bot-supervise.
 */
class NeoBotGatewayCommand extends GatewayDaemon
{
    protected $signature = 'plugged-host:neo-bot-gateway';

    protected $description = 'Run neo\'s Discord gateway daemon (supervised by plugged-host:neo-bot-supervise)';

    /** Remembers that Discord refused MESSAGE_CONTENT, so a restart does not loop on 4014. */
    public const NO_MESSAGE_CONTENT = 'plugged-host-ai:neo-bot:message-content-refused';

    private ?NeoBotInbox $inbox = null;

    protected function bot(): string
    {
        return 'neo';
    }

    protected function supervisorInstance(): BotSupervisor
    {
        return app(NeoBotSupervisor::class);
    }

    protected function token(): string
    {
        return NeoBotConfig::token();
    }

    protected function guildId(): string
    {
        return NeoBotConfig::guildId();
    }

    protected function applicationId(): ?string
    {
        return NeoBotConfig::applicationId();
    }

    protected function blockers(): array
    {
        return NeoBotConfig::blockers();
    }

    protected function configuredIntents(): int
    {
        return (int) config('plugged-host-ai.neo_bot.gateway_intents', 37377);
    }

    protected function idleTimeout(): int
    {
        return (int) config('plugged-host-ai.neo_bot.gateway_idle_timeout', 90);
    }

    protected function noMessageContentKey(): string
    {
        return self::NO_MESSAGE_CONTENT;
    }

    protected function messageContentRefused(): string
    {
        return 'Running without it: DMs, @mentions and replies to neo work; turn on "Message Content Intent" under Developer Portal → neo → Bot for "hey neo".';
    }

    protected function fatalReason(int $code): string
    {
        return match ($code) {
            4004 => 'the neo bot token is wrong or was reset (Developer Portal → neo → Bot → Reset Token, then paste it under Admin → Plugins → plugged.host AI → neo on Discord)',
            4013, 4014 => 'the gateway intents are not allowed (NEO_BOT_GATEWAY_INTENTS)',
            default => 'Discord refused the connection',
        };
    }

    protected function onEvent(string $type, array $data): void
    {
        if ($type !== 'MESSAGE_CREATE' || $this->botId === null) {
            return;
        }

        $messageId = ($this->inbox ??= app(NeoBotInbox::class))->accept($data, $this->botId);

        if ($messageId !== null) {
            $this->line('[' . now()->toDateTimeString() . "] message #{$messageId} from " . ($data['author']['id'] ?? '?'));
            $this->supervisor()->spawnCommand('plugged-host:neo-bot-handle', [(string) $messageId]);
        }
    }
}
