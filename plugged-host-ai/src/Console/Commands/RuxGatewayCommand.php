<?php

namespace PluggedHost\Ai\Console\Commands;

use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Discord\BotSupervisor;
use PluggedHost\Ai\Discord\GatewayDaemon;
use PluggedHost\Ai\Rux\Agent\RuxInbox;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Giveaways\GiveawayService;
use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Ai\Rux\Watch\SpamGuard;
use PluggedHost\Ai\Rux\Watch\WatchService;
use Throwable;

/**
 * rux's gateway daemon (the connection itself is GatewayDaemon's).
 *
 * It only ever does quick things itself. A message that is an order is stored and handed to a
 * detached worker (plugged-host:rux-handle), because a model turn takes seconds and the socket
 * must keep heartbeating; a giveaway button is answered right here (a few database reads and
 * one lookup on noprofit.space). Every 10 seconds it also starts the
 * draw of any giveaway whose time is up, so winners are not left waiting for the next minute.
 * Supervised by plugged-host:rux-supervise, like the plugged.host bot.
 *
 * One connection serves every server rux works on (RuxConfig::guilds()); events from servers it
 * is in but does not work on are dropped, and everything it hands on carries its server.
 */
class RuxGatewayCommand extends GatewayDaemon
{
    protected $signature = 'plugged-host:rux-gateway';

    protected $description = 'Run rux\'s Discord gateway daemon (supervised by plugged-host:rux-supervise)';

    /** Remembers that Discord refused MESSAGE_CONTENT, so a restart does not loop on 4014. */
    public const NO_MESSAGE_CONTENT = 'plugged-host-ai:rux:message-content-refused';

    private float $lastDueCheck = 0.0;

    private ?RuxInbox $inbox = null;

    private ?WatchService $watch = null;

    private ?RuleService $rules = null;

    private ?SpamGuard $spam = null;

    private ?GiveawayService $giveaways = null;

    protected function bot(): string
    {
        return 'rux';
    }

    protected function supervisorInstance(): BotSupervisor
    {
        return app(RuxSupervisor::class);
    }

    protected function token(): string
    {
        return RuxConfig::token();
    }

    /**
     * The servers it works on, as the status file keeps them: the supervisor replaces the
     * daemon when this list changes.
     */
    protected function guildId(): string
    {
        return implode(',', RuxConfig::guildIds());
    }

    protected function applicationId(): ?string
    {
        return RuxConfig::applicationId();
    }

    protected function blockers(): array
    {
        return RuxConfig::blockers();
    }

    protected function configuredIntents(): int
    {
        return (int) RuxConfig::get('gateway_intents', 37377);
    }

    protected function idleTimeout(): int
    {
        return (int) RuxConfig::get('gateway_idle_timeout', 90);
    }

    protected function noMessageContentKey(): string
    {
        return self::NO_MESSAGE_CONTENT;
    }

    protected function messageContentRefused(): string
    {
        return 'Running without it: turn on "Message Content Intent" under Developer Portal → rux → Bot for rules, watched channels and "hey rux".';
    }

    protected function fatalReason(int $code): string
    {
        return match ($code) {
            4004 => 'the rux bot token is wrong or was reset (Developer Portal → rux → Bot → Reset Token, then paste it under Admin → Plugins → plugged.host AI → rux)',
            4013, 4014 => 'the gateway intents are not allowed (RUX_GATEWAY_INTENTS)',
            default => 'Discord refused the connection',
        };
    }

    protected function onEvent(string $type, array $data): void
    {
        switch ($type) {
            case 'MESSAGE_CREATE':
                if ($this->botId === null) {
                    break;
                }

                $orderId = ($this->inbox ??= app(RuxInbox::class))->accept($data, $this->botId);
                if ($orderId !== null) {
                    $this->line('[' . now()->toDateTimeString() . "] order #{$orderId} from " . ($data['author']['id'] ?? '?'));
                    $this->supervisor()->spawnCommand('plugged-host:rux-handle', [(string) $orderId]);

                    // No break: an order is still a member message. Addressing rux used to
                    // skip the checks below; staff are exempted further down (the watch
                    // drain and the rule check both skip them), so admins' orders are not.
                }

                // Spam is caught without AI, everywhere, and is not checked again below.
                if (($this->spam ??= app(SpamGuard::class))->screen($data, $this->botId)) {
                    $this->spam->flush($this->supervisor());

                    break;
                }

                // Every member message in a watched channel is checked; elsewhere only the
                // ones that hit a standing rule's keywords.
                if (($this->watch ??= app(WatchService::class))->enqueue($data, $this->botId)) {
                    if (Cache::add('plugged-host-ai:rux:watch-spawned', true, 10)) {
                        $this->supervisor()->spawnCommand('plugged-host:rux-watch');
                    }

                    break;
                }

                if (($hit = ($this->rules ??= app(RuleService::class))->screen($data, $this->botId)) !== null) {
                    $this->supervisor()->spawnCommand('plugged-host:rux-rule-check', [$hit]);
                }
                break;

            case 'INTERACTION_CREATE':
                if (isset($data['guild_id']) && !RuxConfig::serves((string) $data['guild_id'])) {
                    break;
                }

                $this->interaction($data);
                break;
        }
    }

    /**
     * Start the draw of giveaways whose time is up, without waiting for the scheduler's minute.
     */
    protected function tick(): void
    {
        $this->spam?->flush($this->supervisor());

        $now = microtime(true);

        if ($now - $this->lastDueCheck < 10) {
            return;
        }

        $this->lastDueCheck = $now;

        try {
            $due = RuxGiveaway::query()->where('status', RuxGiveaway::ACTIVE)->where('ends_at', '<=', now())->exists();
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($due && Cache::add('plugged-host-ai:rux:draw-started', true, 30)) {
            $this->supervisor()->spawnCommand('plugged-host:rux-tick');
        }
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function interaction(array $interaction): void
    {
        $id = (string) ($interaction['id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');

        if ($id === '' || $token === '') {
            return;
        }

        $rest = RuxDiscord::fromConfig();

        ($this->giveaways ??= app(GiveawayService::class))->handleInteraction($interaction, fn (array $response) => $rest->interactionCallback($id, $token, $response));
    }
}
