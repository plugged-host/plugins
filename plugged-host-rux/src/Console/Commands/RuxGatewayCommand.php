<?php

namespace PluggedHost\Rux\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PluggedHost\Rux\Agent\RuxInbox;
use PluggedHost\Rux\Discord\DiscordRest;
use PluggedHost\Rux\Discord\GatewaySocket;
use PluggedHost\Rux\Giveaways\GiveawayService;
use PluggedHost\Rux\Models\RuxGiveaway;
use PluggedHost\Rux\Rules\RuleService;
use PluggedHost\Rux\Watch\WatchService;
use PluggedHost\Rux\Support\RuxConfig;
use PluggedHost\Rux\Support\RuxSupervisor;
use Throwable;

/**
 * rux's gateway daemon: one WebSocket to Discord for as long as it lives.
 *
 * It only ever does quick things itself. A message that is an order is stored and handed to a
 * detached worker (plugged-host:rux-handle), because a model turn takes seconds and the socket
 * must keep heartbeating; a giveaway button is answered right here (a few database reads and
 * one lookup on noprofit.space). Every 10 seconds it also starts the
 * draw of any giveaway whose time is up, so winners are not left waiting for the next minute.
 *
 * Sessions are resumed after a reconnect (op 7, a zombie connection, a dropped socket), so
 * orders sent during the gap are replayed rather than lost. Supervised by
 * plugged-host:rux-supervise, like the plugged.host bot.
 */
class RuxGatewayCommand extends Command
{
    protected $signature = 'plugged-host:rux-gateway';

    protected $description = 'Run rux\'s Discord gateway daemon (supervised by plugged-host:rux-supervise)';

    /** Close codes after which reconnecting cannot help until someone changes the setup. */
    private const FATAL_CLOSE_CODES = [4004, 4010, 4011, 4012, 4013, 4014];

    private const MESSAGE_CONTENT = 1 << 15;

    /** Remembers that Discord refused MESSAGE_CONTENT, so a restart does not loop on 4014. */
    public const NO_MESSAGE_CONTENT = 'plugged-host-rux:message-content-refused';

    /** Close codes after which the session cannot be resumed, only identified again. */
    private const FRESH_SESSION_CLOSE_CODES = [4007, 4009];

    private bool $running = true;

    private ?string $sessionId = null;

    private ?string $resumeUrl = null;

    private ?int $sequence = null;

    private ?string $botId = null;

    private int $reconnectDelay = 1;

    private float $lastDueCheck = 0.0;

    private RuleService $rules;

    private WatchService $watch;

    public function handle(RuxSupervisor $supervisor, RuxInbox $inbox, GiveawayService $giveaways, RuleService $rules, WatchService $watch): int
    {
        $this->rules = $rules;
        $this->watch = $watch;

        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (($blockers = RuxConfig::blockers()) !== []) {
            $this->error('rux cannot run here: ' . implode(' ', $blockers));

            return self::FAILURE;
        }

        if (!$supervisor->acquireLock()) {
            $this->warn('Another rux gateway daemon already holds the lock (pid ' . ($supervisor->readStatus()['pid'] ?? 'unknown') . ').');

            return self::SUCCESS;
        }

        DB::disableQueryLog();
        $this->botId = RuxConfig::applicationId();

        $supervisor->writeStatus([
            'pid' => getmypid(),
            'started_at' => time(),
            'heartbeat_at' => time(),
            'ready_at' => 0,
            'guild_id' => RuxConfig::guildId(),
            'bot_token_hash' => substr(sha1(RuxConfig::token()), 0, 8),
        ]);

        $this->info('[' . now()->toDateTimeString() . '] rux gateway daemon starting, pid ' . getmypid());

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void {
                $this->running = false;
            });
            pcntl_signal(SIGINT, function (): void {
                $this->running = false;
            });
        }

        $exit = self::SUCCESS;

        while ($this->running) {
            try {
                $fatal = $this->connectAndRun($supervisor, $inbox, $giveaways);
            } catch (Throwable $e) {
                $this->error('[' . now()->toDateTimeString() . '] gateway connection failed: ' . $e->getMessage());
                $fatal = false;
            }

            if ($fatal) {
                $exit = self::FAILURE;

                break;
            }

            if (!$this->running) {
                break;
            }

            $this->line("Reconnecting in {$this->reconnectDelay}s" . ($this->sessionId !== null ? ' (resuming)' : '') . '...');
            $supervisor->heartbeat(force: true);
            sleep($this->reconnectDelay);
            $this->reconnectDelay = min(60, $this->reconnectDelay * 2);
        }

        $this->info('[' . now()->toDateTimeString() . '] rux gateway daemon stopped.');
        $supervisor->clearStatus();
        $supervisor->releaseLock();

        return $exit;
    }

    /**
     * One connection, until it drops. True when the close means "stop trying".
     */
    private function connectAndRun(RuxSupervisor $supervisor, RuxInbox $inbox, GiveawayService $giveaways): bool
    {
        $socket = new GatewaySocket();
        $socket->connect($this->sessionId !== null && $this->resumeUrl !== null ? $this->resumeUrl : 'wss://gateway.discord.gg');

        $interval = null;
        $nextBeat = INF;
        $acked = true;
        $lastFrame = microtime(true);
        $idleTimeout = max(45, (int) config('plugged-host-rux.gateway_idle_timeout', 90));

        try {
            while ($this->running) {
                $supervisor->heartbeat();
                $now = microtime(true);

                foreach ($socket->read(200) as $raw) {
                    $lastFrame = microtime(true);
                    $event = json_decode($raw, true);

                    if (!is_array($event)) {
                        continue;
                    }

                    if (isset($event['s']) && is_int($event['s'])) {
                        $this->sequence = $event['s'];
                    }

                    switch ((int) ($event['op'] ?? -1)) {
                        case 10: // HELLO
                            $interval = max(1000, (int) ($event['d']['heartbeat_interval'] ?? 41250)) / 1000;
                            $nextBeat = microtime(true) + $interval * (mt_rand(10, 100) / 100);
                            $acked = true;
                            $this->sendHello($socket);
                            break;

                        case 11: // HEARTBEAT ACK
                            $acked = true;
                            break;

                        case 1: // the gateway asks for a heartbeat now
                            $socket->sendJson(['op' => 1, 'd' => $this->sequence]);
                            break;

                        case 7: // RECONNECT: resume on a new connection
                            $this->line('Discord asked rux to reconnect.');
                            $socket->close(4000);

                            return false;

                        case 9: // INVALID SESSION
                            if (($event['d'] ?? false) !== true) {
                                $this->forgetSession();
                            }
                            $this->line('Discord invalidated the session' . ($this->sessionId === null ? '; identifying again.' : '; resuming.'));
                            sleep(random_int(1, 5));
                            $socket->close(4000);

                            return false;

                        case 0:
                            $this->dispatch((string) ($event['t'] ?? ''), (array) ($event['d'] ?? []), $supervisor, $inbox, $giveaways);
                            break;
                    }
                }

                if (!$socket->isOpen()) {
                    return $this->closed($socket);
                }

                if ($interval !== null && microtime(true) >= $nextBeat) {
                    if (!$acked) {
                        // The last heartbeat was never acknowledged: a zombie connection.
                        $this->warn('No heartbeat ACK from Discord; reconnecting.');
                        $socket->close(4000);

                        return false;
                    }

                    $socket->sendJson(['op' => 1, 'd' => $this->sequence]);
                    $acked = false;
                    $nextBeat = microtime(true) + $interval;
                }

                if (microtime(true) - $lastFrame > $idleTimeout) {
                    $this->warn("No gateway traffic for {$idleTimeout}s; reconnecting.");
                    $socket->close(4000);

                    return false;
                }

                if ($now - $this->lastDueCheck >= 10) {
                    $this->lastDueCheck = $now;
                    $this->startDueDraws($supervisor);
                }
            }
        } finally {
            if ($socket->isOpen()) {
                $socket->close($this->running ? 4000 : 1000);
            }
        }

        return false;
    }

    private function sendHello(GatewaySocket $socket): void
    {
        if ($this->sessionId !== null) {
            $socket->sendJson(['op' => 6, 'd' => ['token' => RuxConfig::token(), 'session_id' => $this->sessionId, 'seq' => $this->sequence]]);

            return;
        }

        $socket->sendJson(['op' => 2, 'd' => [
            'token' => RuxConfig::token(),
            'intents' => $this->intents(),
            'properties' => ['os' => 'linux', 'browser' => 'rux', 'device' => 'rux'],
            'presence' => ['status' => 'online', 'afk' => false, 'since' => null, 'activities' => [['name' => 'plugged.host', 'type' => 3]]],
        ]]);
    }

    private function intents(): int
    {
        $intents = (int) config('plugged-host-rux.gateway_intents', 37377);

        return Cache::get(self::NO_MESSAGE_CONTENT) ? $intents & ~self::MESSAGE_CONTENT : $intents;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(string $type, array $data, RuxSupervisor $supervisor, RuxInbox $inbox, GiveawayService $giveaways): void
    {
        try {
            switch ($type) {
                case 'READY':
                    $this->sessionId = (string) ($data['session_id'] ?? '') ?: null;
                    $this->resumeUrl = (string) ($data['resume_gateway_url'] ?? '') ?: null;
                    $this->botId = (string) ($data['user']['id'] ?? $this->botId);
                    $this->reconnectDelay = 1;
                    $supervisor->heartbeat(['ready_at' => time(), 'bot_username' => $data['user']['username'] ?? 'rux', 'bot_id' => $this->botId, 'message_content' => ($this->intents() & self::MESSAGE_CONTENT) !== 0], force: true);
                    $supervisor->noteHealthy();
                    $this->info('[' . now()->toDateTimeString() . '] Logged in as ' . ($data['user']['username'] ?? 'rux') . " ({$this->botId}).");
                    break;

                case 'RESUMED':
                    $this->reconnectDelay = 1;
                    $supervisor->heartbeat(['ready_at' => time()], force: true);
                    $this->line('[' . now()->toDateTimeString() . '] Session resumed.');
                    break;

                case 'MESSAGE_CREATE':
                    if ($this->botId === null) {
                        break;
                    }

                    $orderId = $inbox->accept($data, $this->botId);
                    if ($orderId !== null) {
                        $this->line('[' . now()->toDateTimeString() . "] order #{$orderId} from " . ($data['author']['id'] ?? '?'));
                        $supervisor->spawnCommand('plugged-host:rux-handle', [(string) $orderId]);

                        // No break: an order is still a member message. Addressing rux used to
                        // skip the checks below; staff are exempted further down (the watch
                        // drain and the rule check both skip them), so admins' orders are not.
                    }

                    // Every member message in a watched channel is checked; elsewhere only the
                    // ones that hit a standing rule's keywords.
                    if ($this->watch->enqueue($data, $this->botId)) {
                        if (Cache::add('plugged-host-rux:watch-spawned', true, 10)) {
                            $supervisor->spawnCommand('plugged-host:rux-watch');
                        }

                        break;
                    }

                    if (($hit = $this->rules->screen($data, $this->botId)) !== null) {
                        $supervisor->spawnCommand('plugged-host:rux-rule-check', [$hit]);
                    }
                    break;

                case 'INTERACTION_CREATE':
                    if (isset($data['guild_id']) && (string) $data['guild_id'] !== RuxConfig::guildId()) {
                        break;
                    }

                    $this->interaction($data, $giveaways);
                    break;
            }
        } catch (Throwable $e) {
            // One bad event must never take the connection down with it.
            report($e);
            $this->error("[{$type}] " . class_basename($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $interaction
     */
    private function interaction(array $interaction, GiveawayService $giveaways): void
    {
        $id = (string) ($interaction['id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');

        if ($id === '' || $token === '') {
            return;
        }

        $rest = DiscordRest::fromConfig();

        $giveaways->handleInteraction($interaction, fn (array $response) => $rest->interactionCallback($id, $token, $response));
    }

    /**
     * Start the draw of giveaways whose time is up, without waiting for the scheduler's minute.
     */
    private function startDueDraws(RuxSupervisor $supervisor): void
    {
        try {
            $due = RuxGiveaway::query()->where('status', RuxGiveaway::ACTIVE)->where('ends_at', '<=', now())->exists();
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($due && Cache::add('plugged-host-rux:draw-started', true, 30)) {
            $supervisor->spawnCommand('plugged-host:rux-tick');
        }
    }

    /**
     * The socket closed. True when it is pointless to reconnect.
     */
    private function closed(GatewaySocket $socket): bool
    {
        $code = $socket->closeCode();
        $this->warn('[' . now()->toDateTimeString() . '] Discord closed the gateway' . ($code !== null ? " with {$code} {$socket->closeReason()}" : ''));

        if ($code === 4014 && !Cache::get(self::NO_MESSAGE_CONTENT) && ($this->intents() & self::MESSAGE_CONTENT) !== 0) {
            // Message Content Intent is off in the Developer Portal: keep DMs and @mentions
            // working without it; standing rules, watched channels and "hey rux" need it.
            Cache::put(self::NO_MESSAGE_CONTENT, true, now()->addHours(6));
            $this->warn('Discord refused the Message Content intent (4014). Running without it: turn on "Message Content Intent" under Developer Portal → rux → Bot for rules, watched channels and "hey rux".');
            Log::warning('[rux] gateway closed 4014: running without the Message Content intent');
            $this->forgetSession();

            return false;
        }

        if ($code !== null && in_array($code, self::FATAL_CLOSE_CODES, true)) {
            $why = match ($code) {
                4004 => 'the rux bot token is wrong or was reset (Developer Portal → rux → Bot → Reset Token, then paste it under Admin → Plugins → rux)',
                4013, 4014 => 'the gateway intents are not allowed (RUX_GATEWAY_INTENTS)',
                default => 'Discord refused the connection',
            };

            $this->error("rux stops: {$why}.");
            Log::error("[rux] gateway closed {$code}: {$why}");

            return true;
        }

        if ($code !== null && in_array($code, self::FRESH_SESSION_CLOSE_CODES, true)) {
            $this->forgetSession();
        }

        return false;
    }

    private function forgetSession(): void
    {
        $this->sessionId = null;
        $this->resumeUrl = null;
        $this->sequence = null;
    }
}
