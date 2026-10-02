<?php

namespace PluggedHost\Ai\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A bot's gateway daemon: one WebSocket to Discord for as long as it lives. rux and neo each
 * run one, with their own token (plugged-host:rux-gateway, plugged-host:neo-bot-gateway).
 *
 * This is the connection itself: heartbeats, zombie and idle detection, and resuming the
 * session after a reconnect (op 7, a dropped socket), so messages sent during the gap are
 * replayed rather than lost. The bot decides what to do with each event in onEvent(), which
 * must stay quick: anything slow goes to a detached worker, because the socket has to keep
 * heartbeating. Supervised by the bot's own supervise command.
 */
abstract class GatewayDaemon extends Command
{
    /** Close codes after which reconnecting cannot help until someone changes the setup. */
    private const FATAL_CLOSE_CODES = [4004, 4010, 4011, 4012, 4013, 4014];

    protected const MESSAGE_CONTENT = 1 << 15;

    /** Close codes after which the session cannot be resumed, only identified again. */
    private const FRESH_SESSION_CLOSE_CODES = [4007, 4009];

    protected ?string $botId = null;

    private bool $running = true;

    private ?string $sessionId = null;

    private ?string $resumeUrl = null;

    private ?int $sequence = null;

    private int $reconnectDelay = 1;

    private BotSupervisor $supervisor;

    /** The bot's name, for the log and Discord's identify properties. */
    abstract protected function bot(): string;

    abstract protected function supervisorInstance(): BotSupervisor;

    abstract protected function token(): string;

    abstract protected function guildId(): string;

    abstract protected function applicationId(): ?string;

    /**
     * Why the bot may not run here, or an empty list.
     *
     * @return list<string>
     */
    abstract protected function blockers(): array;

    abstract protected function configuredIntents(): int;

    /** Cache key remembering that Discord refused the Message Content intent (close 4014). */
    abstract protected function noMessageContentKey(): string;

    /** What still works and how to get the rest back, logged when Discord refuses Message Content. */
    abstract protected function messageContentRefused(): string;

    /** How to fix a close after which the daemon stops (bad token, intents not allowed). */
    abstract protected function fatalReason(int $code): string;

    /**
     * One dispatch event. An exception is reported and the connection carries on.
     *
     * @param  array<string, mixed>  $data
     */
    abstract protected function onEvent(string $type, array $data): void;

    /** Called on every pass of the read loop (about every 200 ms), for timed work. */
    protected function tick(): void {}

    /** Seconds without any frame from Discord before the connection is given up as dead. */
    protected function idleTimeout(): int
    {
        return 90;
    }

    public function handle(): int
    {
        $this->supervisor = $this->supervisorInstance();

        if (($relaunched = $this->supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        if (($blockers = $this->blockers()) !== []) {
            $this->error("{$this->bot()} cannot run here: " . implode(' ', $blockers));

            return self::FAILURE;
        }

        if (!$this->supervisor->acquireLock()) {
            $this->warn("Another {$this->bot()} gateway daemon already holds the lock (pid " . ($this->supervisor->readStatus()['pid'] ?? 'unknown') . ').');

            return self::SUCCESS;
        }

        DB::disableQueryLog();
        $this->botId = $this->applicationId();

        $this->supervisor->writeStatus([
            'pid' => getmypid(),
            'started_at' => time(),
            'heartbeat_at' => time(),
            'ready_at' => 0,
            'guild_id' => $this->guildId(),
            'bot_token_hash' => substr(sha1($this->token()), 0, 8),
        ]);

        $this->info('[' . now()->toDateTimeString() . "] {$this->bot()} gateway daemon starting, pid " . getmypid());

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
                $fatal = $this->connectAndRun();
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
            $this->supervisor->heartbeat(force: true);
            sleep($this->reconnectDelay);
            $this->reconnectDelay = min(60, $this->reconnectDelay * 2);
        }

        $this->info('[' . now()->toDateTimeString() . "] {$this->bot()} gateway daemon stopped.");
        $this->supervisor->clearStatus();
        $this->supervisor->releaseLock();

        return $exit;
    }

    protected function supervisor(): BotSupervisor
    {
        return $this->supervisor;
    }

    /**
     * The intents to identify with: the configured ones, without Message Content while Discord
     * refuses it.
     */
    protected function intents(): int
    {
        $intents = $this->configuredIntents();

        return Cache::get($this->noMessageContentKey()) ? $intents & ~self::MESSAGE_CONTENT : $intents;
    }

    /**
     * One connection, until it drops. True when the close means "stop trying".
     */
    private function connectAndRun(): bool
    {
        $socket = new GatewaySocket();
        $socket->connect($this->sessionId !== null && $this->resumeUrl !== null ? $this->resumeUrl : 'wss://gateway.discord.gg');

        $interval = null;
        $nextBeat = INF;
        $acked = true;
        $lastFrame = microtime(true);
        $idleTimeout = max(45, $this->idleTimeout());

        try {
            while ($this->running) {
                $this->supervisor->heartbeat();

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
                            $this->line("Discord asked {$this->bot()} to reconnect.");
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
                            $this->dispatch((string) ($event['t'] ?? ''), (array) ($event['d'] ?? []));
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

                try {
                    $this->tick();
                } catch (Throwable $e) {
                    report($e);
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
            $socket->sendJson(['op' => 6, 'd' => ['token' => $this->token(), 'session_id' => $this->sessionId, 'seq' => $this->sequence]]);

            return;
        }

        $socket->sendJson(['op' => 2, 'd' => [
            'token' => $this->token(),
            'intents' => $this->intents(),
            'properties' => ['os' => 'linux', 'browser' => $this->bot(), 'device' => $this->bot()],
            'presence' => ['status' => 'online', 'afk' => false, 'since' => null, 'activities' => [['name' => 'plugged.host', 'type' => 3]]],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(string $type, array $data): void
    {
        try {
            switch ($type) {
                case 'READY':
                    $this->sessionId = (string) ($data['session_id'] ?? '') ?: null;
                    $this->resumeUrl = (string) ($data['resume_gateway_url'] ?? '') ?: null;
                    $this->botId = (string) ($data['user']['id'] ?? $this->botId);
                    $this->reconnectDelay = 1;
                    $this->supervisor->heartbeat(['ready_at' => time(), 'bot_username' => $data['user']['username'] ?? $this->bot(), 'bot_id' => $this->botId, 'message_content' => ($this->intents() & self::MESSAGE_CONTENT) !== 0], force: true);
                    $this->supervisor->noteHealthy();
                    $this->info('[' . now()->toDateTimeString() . '] Logged in as ' . ($data['user']['username'] ?? $this->bot()) . " ({$this->botId}).");
                    break;

                case 'RESUMED':
                    $this->reconnectDelay = 1;
                    $this->supervisor->heartbeat(['ready_at' => time()], force: true);
                    $this->line('[' . now()->toDateTimeString() . '] Session resumed.');
                    break;
            }

            $this->onEvent($type, $data);
        } catch (Throwable $e) {
            // One bad event must never take the connection down with it.
            report($e);
            $this->error("[{$type}] " . class_basename($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * The socket closed. True when it is pointless to reconnect.
     */
    private function closed(GatewaySocket $socket): bool
    {
        $code = $socket->closeCode();
        $this->warn('[' . now()->toDateTimeString() . '] Discord closed the gateway' . ($code !== null ? " with {$code} {$socket->closeReason()}" : ''));

        if ($code === 4014 && !Cache::get($this->noMessageContentKey()) && ($this->intents() & self::MESSAGE_CONTENT) !== 0) {
            // Message Content Intent is off in the Developer Portal: keep DMs and @mentions
            // working without it.
            Cache::put($this->noMessageContentKey(), true, now()->addHours(6));
            $this->warn('Discord refused the Message Content intent (4014). ' . $this->messageContentRefused());
            Log::warning("[{$this->bot()}] gateway closed 4014: running without the Message Content intent");
            $this->forgetSession();

            return false;
        }

        if ($code !== null && in_array($code, self::FATAL_CLOSE_CODES, true)) {
            $why = $this->fatalReason($code);

            $this->error("{$this->bot()} stops: {$why}.");
            Log::error("[{$this->bot()}] gateway closed {$code}: {$why}");

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
