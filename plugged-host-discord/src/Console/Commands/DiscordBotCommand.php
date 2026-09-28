<?php

namespace PluggedHost\Discord\Console\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Services\DiscordBotService;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Discord\Support\DiscordBotSupervisor;

class DiscordBotCommand extends Command
{
    /**
     * Remembers a 4014 refusal so a restart starts degraded instead of hot-looping
     * into the same close code. Cleared on the first READY that keeps the intent.
     */
    protected const PRIVILEGED_INTENTS_FLAG = 'plugged-host-discord:privileged-intents-unavailable';

    protected const INTENT_GUILD_MEMBERS = 2;

    protected $signature = 'plugged-host:discord-bot';

    protected $description = 'Run the plugged.host Discord Bot gateway daemon to handle slash commands (/invites) and track joins and leaves';

    protected array $cachedInvites = [];
    protected ?int $lastSequence = null;
    protected ?string $sessionId = null;
    protected bool $running = true;
    protected int $intents = 1;
    protected bool $privilegedFallback = false;

    protected ?DiscordBotSupervisor $supervisor = null;

    /** Last moment any frame arrived, for spotting a socket that died without EOF. */
    protected float $lastFrameAt = 0.0;

    /** Grows on each failed connection attempt, back to 1 on every READY. */
    protected int $reconnectDelay = 1;

    public function handle(
        DiscordVerificationService $verificationService,
        DiscordBotService $botService,
        DiscordBotSupervisor $supervisor
    ): int {
        if (($relaunched = $supervisor->relaunchAsWebUser($this)) !== null) {
            return $relaunched;
        }

        $this->supervisor = $supervisor;

        $botToken = $verificationService->getBotToken();
        $guildId = $verificationService->getGuildId();

        if (empty($botToken)) {
            $this->error('Discord Bot Token is not configured. Please set DISCORD_BOT_TOKEN in .env or plugin settings.');

            return self::FAILURE;
        }

        // One gateway connection per panel, enforced by the kernel rather than by a
        // PID file: a second daemon would double-count every join and leave and
        // answer every slash command twice.
        if (!$supervisor->acquireLock()) {
            $this->warn('Another Discord gateway daemon already holds the lock (pid '
                . ($supervisor->readStatus()['pid'] ?? 'unknown') . ') — nothing to do.');

            return self::SUCCESS;
        }

        $this->intents = $this->resolveIntents();

        $supervisor->writeStatus([
            'pid' => getmypid(),
            'started_at' => time(),
            'heartbeat_at' => time(),
            'ready_at' => 0,
            'intents' => $this->intents,
            'privileged_fallback' => $this->privilegedFallback,
            'guild_id' => $guildId,
            'bot_token_hash' => substr(sha1($botToken), 0, 8),
        ]);

        $this->info("==================================================");
        $this->info("   plugged.host Discord Bot Gateway Daemon");
        $this->info("==================================================");
        $this->info("Guild ID: " . ($guildId ?: 'All Guilds'));
        $this->info("Gateway intents: {$this->intents}");
        $this->info("PID: " . getmypid());

        if ($this->privilegedFallback) {
            $this->warn('GUILD_MEMBERS was refused previously — joins will not be attributed to invite codes until "Server Members Intent" is enabled in the Discord Developer Portal. Leaves are still caught by plugged-host:discord-invite-reconcile.');
        }

        $this->info("Starting Discord WebSocket Gateway daemon...");

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () { $this->running = false; });
            pcntl_signal(SIGINT, function () { $this->running = false; });
        }

        while ($this->running) {
            try {
                $this->connectAndRun($verificationService, $botService, $botToken, $guildId);
            } catch (Exception $e) {
                $this->error("Gateway connection terminated: {$e->getMessage()}");
            }

            if (!$this->running) {
                break;
            }

            // Reached on a thrown error *and* on a socket that simply closed. The
            // original code only slept in the error path, so an EOF-ing gateway
            // turned into a hot reconnect loop.
            $this->info("Reconnecting in {$this->reconnectDelay}s...");
            $supervisor->heartbeat(force: true);
            sleep($this->reconnectDelay);
            $this->reconnectDelay = min(30, $this->reconnectDelay * 2);
        }

        $this->info("Discord Bot Daemon stopped cleanly.");

        $supervisor->clearStatus();
        $supervisor->releaseLock();

        return self::SUCCESS;
    }

    /**
     * Configured intents, minus GUILD_MEMBERS when Discord is known to refuse it.
     *
     * GUILD_MEMBERS (2) is privileged: without the portal switch, IDENTIFY is closed
     * with 4014 and nothing works at all — so a remembered refusal downgrades the
     * connection rather than letting it hot-loop. Slash commands and invite-cache
     * events keep working; only real-time join attribution is lost, and leaves still
     * surface through the hourly REST reconcile.
     */
    protected function resolveIntents(): int
    {
        $configured = (int) config('plugged-host-discord.gateway_intents', 67);

        if (($configured & self::INTENT_GUILD_MEMBERS) && Cache::get(self::PRIVILEGED_INTENTS_FLAG)) {
            $this->privilegedFallback = true;

            return $configured & ~self::INTENT_GUILD_MEMBERS;
        }

        return $configured;
    }

    protected function connectAndRun(
        DiscordVerificationService $verificationService,
        DiscordBotService $botService,
        string $botToken,
        string $guildId
    ): void {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client('ssl://gateway.discord.gg:443', $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new Exception("Could not connect to Discord Gateway: [{$errno}] {$errstr}");
        }

        stream_set_timeout($socket, 1);

        // Perform WebSocket Upgrade Handshake
        $key = base64_encode(random_bytes(16));
        $headers = "GET /?v=10&encoding=json HTTP/1.1\r\n" .
                   "Host: gateway.discord.gg\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: {$key}\r\n" .
                   "Sec-WebSocket-Version: 13\r\n" .
                   "User-Agent: PelicanPanel-PluggedHostDiscord/1.2\r\n\r\n";

        fwrite($socket, $headers);

        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            $response .= $line;
            if (rtrim($line) === '') {
                break;
            }
        }

        if (!str_contains($response, '101 Switching Protocols')) {
            fclose($socket);
            throw new Exception("WebSocket handshake failed: {$response}");
        }

        $this->info("✔ WebSocket connected to Discord Gateway v10.");

        $heartbeatInterval = 41250;
        $lastHeartbeatTime = microtime(true);
        $this->lastFrameAt = microtime(true);
        $idleTimeout = max(30, (int) config('plugged-host-discord.gateway_idle_timeout', 90));

        while ($this->running && !feof($socket)) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Proof of life for the supervisor, throttled inside heartbeat().
            $this->supervisor?->heartbeat();

            // Check if heartbeat is due
            $now = microtime(true);
            if (($now - $lastHeartbeatTime) * 1000 >= $heartbeatInterval) {
                $this->sendWsFrame($socket, json_encode([
                    'op' => 1,
                    'd' => $this->lastSequence,
                ]));
                $lastHeartbeatTime = $now;
            }

            // Read incoming WebSocket frame
            $frame = $this->readWsFrame($socket);
            if ($frame === null) {
                // Discord ACKs our heartbeat roughly every 41s, so a much longer
                // total silence is a socket that died without ever reporting EOF —
                // the one failure mode that would otherwise hold the lock forever
                // while nothing arrives.
                if (($now - $this->lastFrameAt) > $idleTimeout) {
                    $this->warn("No gateway traffic for {$idleTimeout}s — dropping the connection to reconnect.");
                    break;
                }

                usleep(30000); // 30ms sleep
                continue;
            }

            $this->lastFrameAt = microtime(true);

            $opcode = $frame['opcode'];
            $payload = $frame['payload'];

            // Opcode 8: CLOSE frame
            if ($opcode === 8) {
                $closeCode = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1000;
                $reason = strlen($payload) > 2 ? substr($payload, 2) : '';
                $this->warn("Discord closed connection with code {$closeCode} ({$reason})");

                if ($closeCode === 4014) {
                    $configured = (int) config('plugged-host-discord.gateway_intents', 67);
                    $this->intents = $configured & ~self::INTENT_GUILD_MEMBERS;
                    $this->privilegedFallback = true;
                    Cache::put(self::PRIVILEGED_INTENTS_FLAG, now()->toIso8601String(), now()->addDay());

                    // Same verdict the hourly REST probe writes, from the one source that
                    // knows it first-hand. The settings screen reads whichever is newer.
                    $verificationService->rememberGuildMembersIntentState(false, [
                        'status' => 4014,
                        'code' => 4014,
                        'message' => 'Discord refused the privileged GUILD_MEMBERS intent at IDENTIFY (gateway close 4014). No join can be attributed to an invite code until "Server Members Intent" is enabled in the Discord Developer Portal.',
                        'source' => 'gateway',
                    ]);

                    $this->error('Discord refused the privileged GUILD_MEMBERS intent (close 4014).');
                    $this->warn('Enable "Server Members Intent" under Privileged Gateway Intents in the Discord Developer Portal. Until then joins cannot be attributed to an invite code, and leaves are only noticed by the hourly plugged-host:discord-invite-reconcile pass.');
                    Log::error('[Discord] Gateway closed 4014 — GUILD_MEMBERS intent not enabled in the Developer Portal', [
                        'configured_intents' => $configured,
                        'fallback_intents' => $this->intents,
                    ]);

                    $this->supervisor?->heartbeat([
                        'intents' => $this->intents,
                        'privileged_fallback' => true,
                    ], force: true);
                }

                // 4004 is fatal until someone changes the token, so say so loudly —
                // the supervisor will keep backing off instead of relaunching into
                // the same rejection every minute.
                if ($closeCode === 4004) {
                    $this->error('Discord rejected the bot token (close 4004). Update DISCORD_BOT_TOKEN in .env or under Admin Area → Plugins → plugged.host Discord.');
                    Log::error('[Discord] Gateway closed 4004 — the bot token is invalid or has been revoked');
                }
                break;
            }

            // Opcode 9: PING -> reply with PONG (Opcode 10)
            if ($opcode === 9) {
                $this->sendWsControlFrame($socket, 10, $payload);
                continue;
            }

            // Text/Binary frame
            if ($opcode === 1 || $opcode === 2) {
                $event = json_decode($payload, true);
                if (!is_array($event)) {
                    continue;
                }

                $op = $event['op'] ?? null;
                $t = $event['t'] ?? null;
                $d = $event['d'] ?? null;
                $s = $event['s'] ?? null;

                if ($s !== null) {
                    $this->lastSequence = (int) $s;
                }

                // Opcode 10: HELLO -> Send IDENTIFY
                if ($op === 10) {
                    $heartbeatInterval = $d['heartbeat_interval'] ?? 41250;
                    $this->info("Received HELLO (Heartbeat interval: {$heartbeatInterval}ms). Identifying...");

                    $this->sendWsFrame($socket, json_encode([
                        'op' => 2,
                        'd' => [
                            'token' => $botToken,
                            'intents' => $this->intents,
                            'properties' => [
                                'os' => PHP_OS,
                                'browser' => 'pelican_panel',
                                'device' => 'pelican_panel',
                            ],
                        ],
                    ]));
                    $lastHeartbeatTime = microtime(true);
                }

                // Opcode 1: Heartbeat request from Discord -> send Heartbeat ACK
                if ($op === 1) {
                    $this->sendWsFrame($socket, json_encode([
                        'op' => 1,
                        'd' => $this->lastSequence,
                    ]));
                    $lastHeartbeatTime = microtime(true);
                }

                // Opcode 0: Dispatch Events
                if ($op === 0 && !empty($t)) {
                    $this->handleDispatchEvent($t, $d, $verificationService, $botService, $guildId);
                }
            }
        }

        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    protected function handleDispatchEvent(
        string $type,
        mixed $data,
        DiscordVerificationService $verificationService,
        DiscordBotService $botService,
        string $guildId = ''
    ): void {
        switch ($type) {
            case 'READY':
                $botUsername = $data['user']['username'] ?? 'Bot';
                $this->sessionId = $data['session_id'] ?? null;
                $this->info("✔ Logged in as bot [{$botUsername}]. Listening for /invites commands...");

                // A connection that got this far worked, so the next failure starts
                // its backoff from scratch — and the supervisor reads ready_at to
                // decide the same thing about the spawn it performed.
                $this->reconnectDelay = 1;
                $this->supervisor?->heartbeat([
                    'ready_at' => time(),
                    'bot_username' => $botUsername,
                    'intents' => $this->intents,
                    'privileged_fallback' => $this->privilegedFallback,
                ], force: true);

                // IDENTIFY was accepted with GUILD_MEMBERS still on, so a previously
                // remembered 4014 is stale — stop degrading future starts.
                if (!$this->privilegedFallback && ($this->intents & self::INTENT_GUILD_MEMBERS)) {
                    Cache::forget(self::PRIVILEGED_INTENTS_FLAG);
                    $verificationService->rememberGuildMembersIntentState(true, [
                        'status' => 0,
                        'code' => null,
                        'message' => 'The gateway accepted the privileged GUILD_MEMBERS intent — joins are being attributed to invite codes.',
                        'source' => 'gateway',
                    ]);
                    $this->info("  Privileged GUILD_MEMBERS intent accepted — join and leave events are live.");
                } elseif ($this->privilegedFallback) {
                    // A degraded daemon can run for weeks. Re-asserting the verdict on
                    // every reconnect keeps the settings screen from ageing into silence.
                    $verificationService->rememberGuildMembersIntentState(false, [
                        'status' => 4014,
                        'code' => 4014,
                        'message' => 'Connected without the privileged GUILD_MEMBERS intent after Discord refused it — joins are not being attributed to invite codes.',
                        'source' => 'gateway',
                    ]);
                }

                $this->refreshInviteCache($verificationService);
                break;

            case 'INVITE_CREATE':
            case 'INVITE_DELETE':
                if (!empty($guildId) && isset($data['guild_id']) && (string) $data['guild_id'] !== $guildId) {
                    break;
                }
                $this->refreshInviteCache($verificationService);
                break;

            case 'GUILD_MEMBER_ADD':
                if (!empty($guildId) && isset($data['guild_id']) && (string) $data['guild_id'] !== $guildId) {
                    break;
                }
                $this->handleMemberJoin($data, $verificationService);
                break;

            case 'GUILD_MEMBER_REMOVE':
                if (!empty($guildId) && isset($data['guild_id']) && (string) $data['guild_id'] !== $guildId) {
                    break;
                }
                $this->handleMemberLeave($data, $verificationService);
                break;

            case 'GUILD_UPDATE':
                if (!empty($guildId) && isset($data['id']) && (string) $data['id'] !== $guildId) {
                    break;
                }
                \Illuminate\Support\Facades\Cache::forget('discord_guild_total_boosts_' . $guildId);
                \Illuminate\Support\Facades\Cache::forget('discord_guild_boosters_' . $guildId);
                break;

            case 'GUILD_MEMBER_UPDATE':
                if (!empty($guildId) && isset($data['guild_id']) && (string) $data['guild_id'] !== $guildId) {
                    break;
                }
                $this->handleMemberUpdate($data, $verificationService);
                break;

            case 'INTERACTION_CREATE':
                if (!empty($guildId) && isset($data['guild_id']) && (string) $data['guild_id'] !== $guildId) {
                    break;
                }
                $this->handleInteraction($data, $botService);
                break;
        }
    }

    protected function refreshInviteCache(DiscordVerificationService $verificationService): void
    {
        $invites = $verificationService->fetchGuildInvites();
        if ($invites) {
            $this->cachedInvites = [];
            foreach ($invites as $inv) {
                if (isset($inv['code'])) {
                    $this->cachedInvites[$inv['code']] = [
                        'uses' => (int) ($inv['uses'] ?? 0),
                        'inviter_id' => $inv['inviter']['id'] ?? null,
                    ];
                }
            }
        }
    }

    protected function handleMemberJoin(array $data, DiscordVerificationService $verificationService): void
    {
        $joinedUserId = $data['user']['id'] ?? null;
        $joinedUsername = $data['user']['username'] ?? 'Unknown';

        if (!$joinedUserId) {
            return;
        }

        $currentInvites = $verificationService->fetchGuildInvites();
        $usedCode = null;
        $inviterId = null;
        // The code the live diff attributed this join to, as opposed to one reused from
        // a prior row further down. Only a diff match may advance the cache: a reused
        // code was not observed incrementing now, so nudging it would push the cache
        // ahead of Discord and drop the next real join on it.
        $matchedCode = null;

        if ($currentInvites) {
            foreach ($currentInvites as $inv) {
                $code = $inv['code'] ?? null;
                $uses = (int) ($inv['uses'] ?? 0);
                $oldUses = $this->cachedInvites[$code]['uses'] ?? 0;

                if ($code && $uses > $oldUses) {
                    $usedCode = $code;
                    $matchedCode = $code;
                    $inviterId = $inv['inviter']['id'] ?? null;
                    break;
                }
            }
        }

        $minAgeDays = $verificationService->getMinAccountAgeDays();
        $accountCreatedAt = $verificationService->getAccountCreatedAtFromSnowflake($joinedUserId);
        $ageDays = round($accountCreatedAt->diffInDays(now()), 1);
        $isEligible = $ageDays >= $minAgeDays;

        $this->info(" [JOIN] {$joinedUsername} ({$joinedUserId}) joined the server (Age: {$ageDays}d, Eligible: " . ($isEligible ? "YES" : "NO") . ")");

        if (!$inviterId) {
            // Rejoin whose code could not be pinned down — a vanity URL, or the daemon
            // was offline when the invite was used. Reuse the attribution the earlier
            // join carried, otherwise the row stays marked as left forever and the
            // inviter never gets the member back on their counter.
            $previous = DiscordInvitedMember::where('joined_discord_id', $joinedUserId)
                ->orderByDesc('id')
                ->first();

            if ($previous) {
                $inviterId = $previous->inviter_discord_id;
                $usedCode = $previous->invite_code;
                $this->line("        Invite code unknown — reusing prior attribution to {$inviterId}.");
            }
        }

        if ($inviterId) {
            $this->line("        Invited By: {$inviterId} via " . ($usedCode ?: 'unknown code'));
            $verificationService->recordMemberJoin($inviterId, $joinedUserId, $usedCode);
        }

        // Advance the cache by exactly the one use we just attributed, rather than
        // re-reading the whole invite list from Discord.
        //
        // A second fetch here was the bug: under a burst of joins on the same code it
        // sees uses from GUILD_MEMBER_ADD events we have not processed yet, so the cache
        // leaps ahead and the next join — whose real uses no longer *exceeds* the
        // inflated cache — is silently dropped, and its inviter never gets counted.
        // Discord's invite endpoint is also aggressively rate limited; two fetches per
        // join doubled the 429s, and a throttled fetch returns null, which loses
        // attribution outright. Incrementing by one keeps the cache at or behind the
        // true count (which is safe — a real join always reads as an increase) and
        // self-corrects if REST was momentarily ahead. INVITE_CREATE / INVITE_DELETE /
        // READY still do the full refresh, so new and deleted codes are not missed.
        if ($matchedCode !== null) {
            $this->cachedInvites[$matchedCode]['uses'] = ($this->cachedInvites[$matchedCode]['uses'] ?? 0) + 1;
            $this->cachedInvites[$matchedCode]['inviter_id'] ??= $inviterId;
        }
    }

    /**
     * A member left: the invite stops counting, and a reward already paid is clawed back.
     */
    protected function handleMemberLeave(array $data, DiscordVerificationService $verificationService): void
    {
        $leftUserId = $data['user']['id'] ?? null;
        $leftUsername = $data['user']['username'] ?? 'Unknown';

        if (!$leftUserId) {
            return;
        }

        $result = $verificationService->recordMemberLeave((string) $leftUserId);

        if (!$result['tracked']) {
            $this->line(" [LEAVE] {$leftUsername} ({$leftUserId}) left — not attributed to any invite, nothing to uncount.");

            return;
        }

        $this->info(" [LEAVE] {$leftUsername} ({$leftUserId}) left — invite uncounted.");

        if ($result['was_rewarded']) {
            $reclaimed = collect($result['reclaimed'] ?? [])
                ->map(fn ($amount, $resource) => "{$resource}={$amount}")
                ->implode(', ');

            $this->line("         Clawed back {$result['recovered']} credits"
                . ($reclaimed !== '' ? " (resources reclaimed: {$reclaimed})" : '')
                . ($result['shortfall'] > 0 ? ", {$result['shortfall']} carried as debt" : '')
                . '.');
        }
    }

    /**
     * A member updated their roles or boost status.
     */
    protected function handleMemberUpdate(array $data, DiscordVerificationService $verificationService): void
    {
        $discordUserId = $data['user']['id'] ?? null;
        $username = $data['user']['username'] ?? 'Unknown';

        if (!$discordUserId) {
            return;
        }

        // Invalidate booster cache so subsequent queries get updated roles
        $guildId = $verificationService->getGuildId();
        if ($guildId) {
            \Illuminate\Support\Facades\Cache::forget('discord_guild_boosters_' . $guildId);
        }

        $link = \PluggedHost\Discord\Models\DiscordLink::where('discord_id', $discordUserId)->first();
        if ($link && $link->user) {
            $result = $verificationService->syncBoosts($link->user, $link, $data);
            if ($result['is_boosting']) {
                $credits = $result['credits_earned'] ?? 0;
                $this->info(" [BOOST] {$username} ({$discordUserId}) is an active booster ({$result['boost_count']} boost" . ($result['boost_count'] > 1 ? 's' : '') . ($credits > 0 ? ", +{$credits} credits" : '') . ")");
            }
        }
    }

    protected function handleInteraction(array $data, DiscordBotService $botService): void
    {
        $interactionId = $data['id'] ?? null;
        $token = $data['token'] ?? null;
        $commandName = $data['data']['name'] ?? null;

        if (!$interactionId || !$token || !in_array($commandName, ['invites', 'topinvites', 'setboost'])) {
            return;
        }

        $executorName = $data['member']['user']['username'] ?? ($data['user']['username'] ?? 'User');
        $options = $data['data']['options'] ?? [];

        if ($commandName === 'invites') {
            $targetUserId = null;
            $targetUsername = null;
            $targetAvatar = null;
            $targetMemberData = null;

            foreach ($options as $opt) {
                if (($opt['name'] ?? '') === 'user' && !empty($opt['value'])) {
                    $targetUserId = (string) $opt['value'];
                    if (isset($data['data']['resolved']['users'][$targetUserId])) {
                        $targetUsername = $data['data']['resolved']['users'][$targetUserId]['username'] ?? null;
                        $targetAvatar = $data['data']['resolved']['users'][$targetUserId]['avatar'] ?? null;
                    }
                    if (isset($data['data']['resolved']['members'][$targetUserId])) {
                        $targetMemberData = (array) $data['data']['resolved']['members'][$targetUserId];
                    }
                    break;
                }
            }

            if (!$targetUserId) {
                $targetUserId = $data['member']['user']['id'] ?? ($data['user']['id'] ?? null);
                $targetUsername = $data['member']['user']['username'] ?? ($data['user']['username'] ?? null);
                $targetAvatar = $data['member']['user']['avatar'] ?? ($data['user']['avatar'] ?? null);
                $targetMemberData = isset($data['member']) ? (array) $data['member'] : null;
            }

            $this->info(" [CMD] /invites executed by {$executorName} for target {$targetUserId}");

            if ($targetUserId) {
                $embed = $botService->buildInvitesEmbed($targetUserId, $targetUsername, $targetAvatar, $targetMemberData);
                $success = $botService->respondToInteraction($interactionId, $token, [
                    'embeds' => [$embed],
                ]);

                if ($success) {
                    $this->info("       ✔ Responded to /invites interaction successfully.");
                } else {
                    $this->error("       ✖ Failed to send interaction callback response.");
                }
            }
        } elseif ($commandName === 'topinvites') {
            $limit = 10;
            foreach ($options as $opt) {
                if (($opt['name'] ?? '') === 'limit' && !empty($opt['value'])) {
                    $limit = max(1, min(25, (int) $opt['value']));
                    break;
                }
            }

            $this->info(" [CMD] /topinvites (limit: {$limit}) executed by {$executorName}");

            $embed = $botService->buildTopInvitesEmbed($limit);
            $success = $botService->respondToInteraction($interactionId, $token, [
                'embeds' => [$embed],
            ]);

            if ($success) {
                $this->info("       ✔ Responded to /topinvites interaction successfully.");
            } else {
                $this->error("       ✖ Failed to send interaction callback response.");
            }
        } elseif ($commandName === 'setboost') {
            if (!DiscordBotService::invokerIsAdministrator($data)) {
                $this->warn(" [CMD] /setboost refused for {$executorName}: not an administrator");
                $botService->respondToInteraction($interactionId, $token, $botService->setBoostDeniedResponse());

                return;
            }

            $targetUserId = null;
            $targetUsername = null;
            $count = 2;

            foreach ($options as $opt) {
                if (($opt['name'] ?? '') === 'user' && !empty($opt['value'])) {
                    $targetUserId = (string) $opt['value'];
                    if (isset($data['data']['resolved']['users'][$targetUserId])) {
                        $targetUsername = $data['data']['resolved']['users'][$targetUserId]['username'] ?? null;
                    }
                } elseif (($opt['name'] ?? '') === 'count') {
                    $count = (int) $opt['value'];
                }
            }

            $this->info(" [CMD] /setboost executed by {$executorName} on {$targetUserId} -> {$count} boosts");

            if ($targetUserId) {
                $response = $botService->handleSetBoostCommand($targetUserId, $count, $targetUsername);
                $success = $botService->respondToInteraction($interactionId, $token, $response);

                if ($success) {
                    $this->info("       ✔ Responded to /setboost interaction successfully.");
                } else {
                    $this->error("       ✖ Failed to send interaction callback response.");
                }
            }
        }
    }

    protected function readExact($socket, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length && !feof($socket)) {
            $read = [$socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50000) > 0) {
                $chunk = @fread($socket, $length - strlen($data));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            } else {
                break;
            }
        }

        return strlen($data) === $length ? $data : null;
    }

    protected function readWsFrame($socket): ?array
    {
        $header = $this->readExact($socket, 2);
        if (!$header) {
            return null;
        }

        $firstByte = ord($header[0]);
        $secondByte = ord($header[1]);

        $opcode = $firstByte & 0x0F;
        $isMasked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;

        if ($payloadLen === 126) {
            $ext = $this->readExact($socket, 2);
            if (!$ext) return null;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = $this->readExact($socket, 8);
            if (!$ext) return null;
            $payloadLen = unpack('J', $ext)[1];
        }

        $mask = $isMasked ? $this->readExact($socket, 4) : '';
        $data = $this->readExact($socket, $payloadLen);
        if ($data === null) {
            return null;
        }

        if ($isMasked && strlen($mask) === 4) {
            $unmasked = '';
            for ($i = 0; $i < strlen($data); $i++) {
                $unmasked .= $data[$i] ^ $mask[$i % 4];
            }
            return ['opcode' => $opcode, 'payload' => $unmasked];
        }

        return ['opcode' => $opcode, 'payload' => $data];
    }

    protected function sendWsFrame($socket, string $data): void
    {
        $len = strlen($data);
        $frame = chr(0x81); // Text frame FIN=1, Opcode=1
        $mask = random_bytes(4);

        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $data[$i] ^ $mask[$i % 4];
        }

        @fwrite($socket, $frame);
    }

    protected function sendWsControlFrame($socket, int $opcode, string $payload): void
    {
        $len = strlen($payload);
        $frame = chr(0x80 | ($opcode & 0x0F));
        $mask = random_bytes(4);
        $frame .= chr(0x80 | $len);
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }
        @fwrite($socket, $frame);
    }
}
