<?php

namespace PluggedHost\Discord\Services;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Contracts\DiscordServersStatus;
use PluggedHost\Discord\Models\DiscordInviteBaseline;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Support\DiscordEnvironment;
use PluggedHost\Economy\Models\CreditTransaction;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Services\CreditRecoveryService;

class DiscordVerificationService
{
    public const DISCORD_API_BASE = 'https://discord.com/api/v10';

    /**
     * Last known answer to "can this bot see guild members at all?".
     *
     * Written by the hourly sync's REST probe and by the gateway daemon's IDENTIFY
     * result, read by the plugin settings screen. Cached rather than stored because it
     * describes the current state of a switch in the Discord Developer Portal, not
     * anything this panel owns — a wiped cache should read as "unknown", not as "fine".
     */
    public const MEMBERS_INTENT_CACHE_KEY = 'plugged-host-discord:guild-members-intent';

    /**
     * Get the configured Discord Guild ID.
     */
    public function getGuildId(): string
    {
        return trim((string) config('plugged-host-discord.guild_id', ''));
    }

    /**
     * Get the optional Discord Bot Token.
     */
    public function getBotToken(): string
    {
        return trim((string) config('plugged-host-discord.bot_token', ''));
    }

    /**
     * Get the Discord OAuth Client ID.
     */
    public function getClientId(): string
    {
        return trim((string) (config('services.discord.client_id') ?? env('OAUTH_DISCORD_CLIENT_ID', '')));
    }

    /**
     * Get the Discord OAuth Client Secret.
     */
    public function getClientSecret(): string
    {
        return trim((string) (config('services.discord.client_secret') ?? env('OAUTH_DISCORD_CLIENT_SECRET', '')));
    }

    /**
     * Get the configured panel role name to assign/remove.
     */
    public function getRoleName(): string
    {
        return (string) config('plugged-host-discord.role_name', 'Discord Linked');
    }

    /**
     * Get the configured Discord server invite URL.
     */
    public function getInviteUrl(): string
    {
        return (string) config('plugged-host-discord.invite_url', 'https://discord.gg/plugged-host-1502647500295635085');
    }

    /**
     * Get credits awarded per Discord invite.
     */
    public function getCreditsPerInvite(): int
    {
        return (int) config('plugged-host-discord.credits_per_invite', 50);
    }

    /**
     * Get credits awarded for 1 Discord server boost (~10 invites equivalent).
     */
    public function getCreditsPerBoost1(): int
    {
        return (int) config('plugged-host-discord.credits_per_boost_1', 500);
    }

    /**
     * Get total credits awarded for 2 Discord server boosts (~25 invites equivalent).
     */
    public function getCreditsPerBoost2(): int
    {
        return (int) config('plugged-host-discord.credits_per_boost_2', 1250);
    }

    /**
     * Get the optional Discord Role ID designating 2 server boosts.
     */
    public function getBoost2RoleId(): string
    {
        $configured = trim((string) config('plugged-host-discord.boost_2_role_id', ''));
        if (!empty($configured)) {
            return $configured;
        }

        $guildId = $this->getGuildId();
        if (empty($guildId)) {
            return '';
        }

        return (string) Cache::remember('discord_boost_2_role_id_' . $guildId, 86400, function () use ($guildId) {
            $botToken = $this->getBotToken();
            if (empty($botToken)) {
                return '';
            }

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                    'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
                ])->timeout(5)->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/roles");

                if ($response->successful()) {
                    foreach ((array) $response->json() as $role) {
                        $name = strtolower(trim((string) ($role['name'] ?? '')));
                        if (str_contains($name, '2x boost') || str_contains($name, '2 boost') || str_contains($name, 'tier 2 boost') || str_contains($name, 'booster 2')) {
                            return (string) $role['id'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignore failure
            }

            return '';
        });
    }

    /**
     * Get the Discord role ID for the official Server Booster role (premium_subscriber tag or name).
     */
    public function getBoosterRoleId(): ?string
    {
        $guildId = $this->getGuildId();
        if (empty($guildId)) {
            return null;
        }

        return Cache::remember('discord_booster_role_id_' . $guildId, 86400, function () use ($guildId) {
            $botToken = $this->getBotToken();
            if (empty($botToken)) {
                return null;
            }

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                    'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
                ])->timeout(5)->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/roles");

                if ($response->successful()) {
                    foreach ((array) $response->json() as $role) {
                        if (isset($role['tags']) && array_key_exists('premium_subscriber', (array) $role['tags'])) {
                            return (string) $role['id'];
                        }
                        if (strtolower(trim((string) ($role['name'] ?? ''))) === 'server booster') {
                            return (string) $role['id'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to fetch guild booster role: {$e->getMessage()}");
            }

            return null;
        });
    }

    /**
     * Determine if an array of role IDs includes the server's booster role.
     */
    public function hasBoosterRole(array $roles): bool
    {
        $boosterRoleId = $this->getBoosterRoleId();
        if (!empty($boosterRoleId) && in_array((string) $boosterRoleId, array_map('strval', $roles), true)) {
            return true;
        }

        return false;
    }

    /**
     * Get the total server boosts for the guild from Discord API.
     */
    public function getGuildTotalBoostCount(): int
    {
        $guildId = $this->getGuildId();
        if (empty($guildId)) {
            return 0;
        }

        return (int) Cache::remember('discord_guild_total_boosts_' . $guildId, 60, function () use ($guildId) {
            $botToken = $this->getBotToken();
            if (empty($botToken)) {
                return 0;
            }

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                    'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
                ])->timeout(5)->get(self::DISCORD_API_BASE . "/guilds/{$guildId}?with_counts=true");

                if ($response->successful()) {
                    return (int) ($response->json('premium_subscription_count') ?? 0);
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to fetch guild total boost count: {$e->getMessage()}");
            }

            return 0;
        });
    }

    /**
     * Get the Discord user IDs of all current boosters in the guild.
     *
     * @return list<string>
     */
    public function getGuildBoosterUserIds(): array
    {
        $guildId = $this->getGuildId();
        if (empty($guildId)) {
            return [];
        }

        return Cache::remember('discord_guild_boosters_' . $guildId, 120, function () use ($guildId) {
            $botToken = $this->getBotToken();
            if (empty($botToken)) {
                return [];
            }

            try {
                $boosterRoleId = $this->getBoosterRoleId();
                $boosterIds = [];
                $after = '0';

                while (true) {
                    $response = Http::withHeaders([
                        'Authorization' => "Bot {$botToken}",
                        'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
                    ])->timeout(10)->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/members", [
                        'limit' => 1000,
                        'after' => $after,
                    ]);

                    if (!$response->successful()) {
                        break;
                    }

                    $members = (array) $response->json();
                    if (empty($members)) {
                        break;
                    }

                    foreach ($members as $m) {
                        $isBooster = !empty($m['premium_since'])
                            || ($boosterRoleId && in_array((string) $boosterRoleId, (array) ($m['roles'] ?? [])));

                        if ($isBooster && isset($m['user']['id'])) {
                            $boosterIds[] = (string) $m['user']['id'];
                        }

                        $after = (string) ($m['user']['id'] ?? '');
                    }

                    if (count($members) < 1000) {
                        break;
                    }
                }

                return array_values(array_unique($boosterIds));
            } catch (\Throwable $e) {
                Log::warning("Failed to fetch guild boosters: {$e->getMessage()}");
                return [];
            }
        });
    }

    /**
     * Resolve the boost count (1 or 2+) for a boosting member.
     */
    public function resolveMemberBoostCount(string $discordUserId, array $roles = [], ?DiscordLink $link = null): int
    {
        // 1. Explicit 2x boost role configured or auto-detected by name
        $boost2RoleId = $this->getBoost2RoleId();
        if (!empty($boost2RoleId) && in_array((string) $boost2RoleId, array_map('strval', $roles), true)) {
            return 2;
        }

        // 2. Previously recorded boost count on the link record (do not downgrade an active booster)
        if ($link && $link->boost_count >= 2) {
            return (int) $link->boost_count;
        }

        // 3. Mathematical verification from Guild total boost count vs booster count:
        // Discord API does not expose per-user boost count. However, Discord exposes
        // the guild's aggregate `premium_subscription_count`.
        // If total boosts >= (total unique boosters * 2), every booster in the server has boosted >= 2 times.
        $totalBoosts = $this->getGuildTotalBoostCount();
        $boosters = $this->getGuildBoosterUserIds();

        // Make sure the current user is counted among boosters if they are boosting
        if (!in_array($discordUserId, $boosters, true)) {
            $boosters[] = $discordUserId;
        }

        $boosterCount = count($boosters);

        if ($boosterCount > 0 && $totalBoosts >= ($boosterCount * 2)) {
            return 2;
        }

        if ($boosterCount > 0 && $totalBoosts > $boosterCount) {
            if (($totalBoosts / $boosterCount) >= 1.5) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * Ensure the 2x Booster role exists in the Discord guild.
     */
    public function ensureBoost2RoleExists(): ?string
    {
        $existingId = $this->getBoost2RoleId();
        if (!empty($existingId)) {
            return $existingId;
        }

        $guildId = $this->getGuildId();
        $botToken = $this->getBotToken();
        if (empty($guildId) || empty($botToken) || !DiscordEnvironment::allowsGuildAccess()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
            ])->post(self::DISCORD_API_BASE . "/guilds/{$guildId}/roles", [
                'name' => '2x Booster',
                'color' => 16023551, // Discord Nitro Pink
                'hoist' => false,
                'mentionable' => false,
            ]);

            if ($response->successful()) {
                $roleId = (string) $response->json('id');
                Cache::put('discord_boost_2_role_id_' . $guildId, $roleId, 86400);

                return $roleId;
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to auto-create 2x Booster role: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Assign the 2x Booster role to a member in Discord.
     */
    public function assignBoost2Role(string $discordUserId): bool
    {
        $roleId = $this->ensureBoost2RoleExists();
        $guildId = $this->getGuildId();
        $botToken = $this->getBotToken();

        if (empty($roleId) || empty($guildId) || empty($botToken) || !DiscordEnvironment::allowsGuildAccess()) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
            ])->put(self::DISCORD_API_BASE . "/guilds/{$guildId}/members/{$discordUserId}/roles/{$roleId}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Failed to assign 2x Booster role to {$discordUserId}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Remove the 2x Booster role from a member in Discord.
     */
    public function removeBoost2Role(string $discordUserId): bool
    {
        $roleId = $this->getBoost2RoleId();
        $guildId = $this->getGuildId();
        $botToken = $this->getBotToken();

        if (empty($roleId) || empty($guildId) || empty($botToken) || !DiscordEnvironment::allowsGuildAccess()) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
            ])->delete(self::DISCORD_API_BASE . "/guilds/{$guildId}/members/{$discordUserId}/roles/{$roleId}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Failed to remove 2x Booster role from {$discordUserId}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get the required minimum Discord account age in days for invite rewards (default: 30 days).
     */
    public function getMinAccountAgeDays(): int
    {
        return (int) config('plugged-host-discord.min_account_age_days', 30);
    }

    /**
     * Calculate Discord account creation date from a Snowflake ID.
     */
    public function getAccountCreatedAtFromSnowflake(string $snowflake): Carbon
    {
        return DiscordInvitedMember::getCreatedAtFromSnowflake($snowflake);
    }

    /**
     * Check if guild ID is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->getGuildId());
    }

    /**
     * Get the Discord User ID linked to a given User.
     */
    public function getLinkedDiscordId(User $user): ?string
    {
        $oauth = $user->oauth ?? [];
        if (is_array($oauth) && !empty($oauth['discord'])) {
            return (string) $oauth['discord'];
        }

        $link = DiscordLink::where('user_id', $user->id)->first();
        if ($link && !empty($link->discord_id)) {
            return (string) $link->discord_id;
        }

        return null;
    }

    /**
     * Save OAuth credentials and guild information from an OAuth callback.
     */
    public function saveOAuthCredentials(
        User $user,
        string $discordId,
        string $accessToken,
        ?string $refreshToken = null,
        ?int $expiresIn = null,
        ?array $rawUser = null
    ): DiscordLink {
        $data = [
            'discord_id' => $discordId,
            'access_token' => $accessToken,
            'last_verified_at' => now(),
        ];

        if ($refreshToken) {
            $data['refresh_token'] = $refreshToken;
        }

        if ($expiresIn) {
            $data['token_expires_at'] = now()->addSeconds($expiresIn);
        }

        if ($rawUser) {
            $username = $rawUser['username'] ?? null;
            $discriminator = $rawUser['discriminator'] ?? '0';
            if ($username) {
                $data['discord_username'] = $discriminator !== '0' && !empty($discriminator)
                    ? "{$username}#{$discriminator}"
                    : $username;
            }

            if (!empty($rawUser['avatar'])) {
                $data['discord_avatar'] = $rawUser['avatar'];
            }
        }

        return DiscordLink::updateOrCreate(['user_id' => $user->id], $data);
    }

    /**
     * Refresh the user's OAuth access token using their refresh token.
     */
    public function refreshUserToken(DiscordLink $link): ?string
    {
        if (empty($link->refresh_token) || empty($this->getClientId()) || empty($this->getClientSecret())) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::DISCORD_API_BASE . '/oauth2/token', [
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $link->refresh_token,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $newAccessToken = $data['access_token'] ?? null;
                $newRefreshToken = $data['refresh_token'] ?? $link->refresh_token;
                $expiresIn = (int) ($data['expires_in'] ?? 604800);

                if ($newAccessToken) {
                    $link->update([
                        'access_token' => $newAccessToken,
                        'refresh_token' => $newRefreshToken,
                        'token_expires_at' => now()->addSeconds($expiresIn),
                    ]);

                    return $newAccessToken;
                }
            }
        } catch (Exception $e) {
            Log::warning("Failed to refresh Discord user OAuth token: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Check guild membership using user's OAuth access token and the `guilds` scope.
     *
     * @return array{is_in_guild: bool, status_code: int, error: ?string}
     */
    public function checkGuildMembershipUsingUserToken(string $accessToken): array
    {
        $guildId = $this->getGuildId();

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.0',
            ])
            ->timeout(10)
            ->get(self::DISCORD_API_BASE . '/users/@me/guilds');

            $statusCode = $response->status();

            if ($statusCode === 200) {
                $guilds = $response->json();
                $isInGuild = collect($guilds)->contains(function ($guild) use ($guildId) {
                    return is_array($guild) && (string) ($guild['id'] ?? '') === $guildId;
                });

                return [
                    'is_in_guild' => $isInGuild,
                    'status_code' => 200,
                    'error' => null,
                ];
            }

            return [
                'is_in_guild' => false,
                'status_code' => $statusCode,
                'error' => "Discord API returned HTTP {$statusCode}",
            ];
        } catch (Exception $e) {
            return [
                'is_in_guild' => false,
                'status_code' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Query Discord API using Bot token if configured.
     *
     * Callers must branch on `status_code`, not on the truthiness of `is_in_guild` or
     * the emptiness of `error`: 200 means present, 404 means genuinely not a member,
     * and every other code (0 on a timeout, 401 on a revoked token, 429, 5xx) means
     * "unknown, ask again later". Note that the authoritative 404 verdict still carries
     * a human-readable `error` string, so `!empty($membership['error'])` is *not* the
     * same test as "the check failed".
     *
     * @return array{is_in_guild: bool, status_code: int, error: ?string, member_data: ?array}
     */
    public function checkGuildMembershipUsingBot(string $discordUserId): array
    {
        $botToken = $this->getBotToken();
        $guildId = $this->getGuildId();

        if (empty($botToken)) {
            return [
                'is_in_guild' => false,
                'status_code' => 0,
                'error' => 'Bot token not configured',
                'member_data' => null,
            ];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.0',
            ])
            ->timeout(10)
            ->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/members/{$discordUserId}");

            $statusCode = $response->status();

            if ($statusCode === 200) {
                return [
                    'is_in_guild' => true,
                    'status_code' => 200,
                    'error' => null,
                    'member_data' => (array) $response->json(),
                ];
            }

            if ($statusCode === 404) {
                return [
                    'is_in_guild' => false,
                    'status_code' => 404,
                    'error' => 'Not in guild',
                    'member_data' => null,
                ];
            }

            return [
                'is_in_guild' => false,
                'status_code' => $statusCode,
                'error' => "Discord API returned HTTP {$statusCode}",
                'member_data' => null,
            ];
        } catch (Exception $e) {
            return [
                'is_in_guild' => false,
                'status_code' => 0,
                'error' => $e->getMessage(),
                'member_data' => null,
            ];
        }
    }

    /**
     * Fetch all guild invites using the Discord Bot Token.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function fetchGuildInvites(): ?array
    {
        $botToken = $this->getBotToken();
        $guildId = $this->getGuildId();

        if (empty($botToken) || empty($guildId)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.0',
            ])
            ->timeout(10)
            ->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/invites");

            if ($response->successful()) {
                return (array) $response->json();
            }

            Log::warning("Discord fetchGuildInvites returned status {$response->status()}: " . $response->body());
        } catch (Exception $e) {
            Log::warning("Discord fetchGuildInvites failed: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Ask Discord whether the privileged GUILD_MEMBERS intent is actually enabled.
     *
     * `GET /guilds/{id}/members` is gated on the same Developer Portal switch as the
     * GUILD_MEMBER_ADD gateway event, so a single unprivileged-looking REST call answers
     * the question the gateway can only answer by being refused at IDENTIFY time. With
     * the switch off Discord returns 403 / code 50001 ("Missing Access") even for a bot
     * holding Administrator — which is exactly why the failure is so easy to misread as a
     * permissions problem.
     *
     * This matters more than it looks: with the intent off, no join is ever attributed to
     * an invite code, `discord_invited_members` stays empty, and the leaderboard reports
     * "no invites recorded" indefinitely without anything erroring.
     *
     * @return array{available: bool, status: int, code: ?int, message: string, checked_at: string, source: string}
     */
    public function probeGuildMembersIntent(): array
    {
        $botToken = $this->getBotToken();
        $guildId = $this->getGuildId();

        if (empty($botToken) || empty($guildId)) {
            return $this->rememberGuildMembersIntentState(false, [
                'status' => 0,
                'code' => null,
                'message' => 'Bot token or guild ID is not configured.',
                'source' => 'rest',
            ]);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.0',
            ])
            ->timeout(10)
            ->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/members", ['limit' => 1]);

            if ($response->successful()) {
                return $this->rememberGuildMembersIntentState(true, [
                    'status' => $response->status(),
                    'code' => null,
                    'message' => 'Server Members Intent is enabled — joins can be attributed to invite codes.',
                    'source' => 'rest',
                ]);
            }

            $discordCode = (int) ($response->json('code') ?? 0);

            // 50001 on this endpoint with a valid token means the portal switch, not the
            // role permissions. Anything else is a genuine API fault worth reporting as is.
            $message = ($response->status() === 403 && $discordCode === 50001)
                ? 'Server Members Intent is DISABLED in the Discord Developer Portal. Joins cannot be attributed to an invite code, so no new invites will be counted.'
                : "Discord returned HTTP {$response->status()}: " . trim((string) $response->json('message')) . " (code {$discordCode})";

            return $this->rememberGuildMembersIntentState(false, [
                'status' => $response->status(),
                'code' => $discordCode ?: null,
                'message' => $message,
                'source' => 'rest',
            ]);
        } catch (Exception $e) {
            // A transient network failure is not evidence the intent is off, so it is
            // reported without overwriting a known-good state.
            return [
                'available' => false,
                'status' => 0,
                'code' => null,
                'message' => "Could not reach Discord: {$e->getMessage()}",
                'checked_at' => now()->toIso8601String(),
                'source' => 'rest',
            ];
        }
    }

    /**
     * Record the intent verdict for the settings screen, and log a transition.
     *
     * @param  array{status?: int, code?: ?int, message?: string, source?: string}  $context
     * @return array{available: bool, status: int, code: ?int, message: string, checked_at: string, source: string}
     */
    public function rememberGuildMembersIntentState(bool $available, array $context = []): array
    {
        $state = [
            'available' => $available,
            'status' => (int) ($context['status'] ?? 0),
            'code' => $context['code'] ?? null,
            'message' => (string) ($context['message'] ?? ''),
            'checked_at' => now()->toIso8601String(),
            'source' => (string) ($context['source'] ?? 'rest'),
        ];

        $previous = Cache::get(self::MEMBERS_INTENT_CACHE_KEY);

        // Long TTL on purpose: the settings screen must be able to show a stale verdict
        // with its timestamp rather than fall back to "unknown" between hourly runs.
        Cache::put(self::MEMBERS_INTENT_CACHE_KEY, $state, now()->addDays(7));

        if (!is_array($previous) || ($previous['available'] ?? null) !== $available) {
            $available
                ? Log::info('[Discord] Server Members Intent is available — invite tracking is live.', $state)
                : Log::error('[Discord] Server Members Intent unavailable — invites will not be counted.', $state);
        }

        return $state;
    }

    /**
     * The last recorded intent verdict, or null if nothing has checked yet.
     *
     * @return array{available: bool, status: int, code: ?int, message: string, checked_at: string, source: string}|null
     */
    public function getGuildMembersIntentState(): ?array
    {
        $state = Cache::get(self::MEMBERS_INTENT_CACHE_KEY);

        return is_array($state) ? $state : null;
    }

    /**
     * Fetch guild member data (roles, premium_since, etc.) using Discord Bot Token.
     *
     * @return array<string, mixed>|null
     */
    public function fetchMemberData(string $discordUserId): ?array
    {
        $botToken = $this->getBotToken();
        $guildId = $this->getGuildId();

        if (empty($botToken) || empty($guildId)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.0',
            ])
            ->timeout(10)
            ->get(self::DISCORD_API_BASE . "/guilds/{$guildId}/members/{$discordUserId}");

            if ($response->successful()) {
                return (array) $response->json();
            }
        } catch (Exception $e) {
            Log::warning("Discord fetchMemberData failed for {$discordUserId}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * How long a join is held in escrow before it pays out.
     */
    public function getInviteMaturityHours(): int
    {
        return max(0, (int) config('plugged-host-discord.invite_maturity_hours', 6));
    }

    public function inviteClawbackEnabled(): bool
    {
        return (bool) config('plugged-host-discord.invite_clawback', true);
    }

    /**
     * Record a member join in escrow.
     *
     * Nothing is awarded here. The join is stored with a `matured_at` deadline and
     * `awardMaturedInvites()` pays it out later, but only if the member is still in
     * the guild by then — which is what makes the join/leave farming loop worthless
     * rather than merely reversible.
     *
     * A member who left and came back reuses their row: `has_left` clears so they
     * count again, `rejoin_count` records the cycle, and escrow restarts from now.
     * They are not re-rewarded if the original reward was never revoked.
     */
    public function recordMemberJoin(string $inviterDiscordId, string $joinedDiscordId, ?string $inviteCode = null): DiscordInvitedMember
    {
        $minAgeDays = $this->getMinAccountAgeDays();
        $accountCreatedAt = DiscordInvitedMember::getCreatedAtFromSnowflake($joinedDiscordId);
        $ageDays = $accountCreatedAt->diffInDays(now());

        // Nobody is credited for their own join. Discord attributes a rejoin through a
        // member's own invite link back to that member, so this is reachable without any
        // deliberate abuse — and it must not pay in either case. Recorded as ineligible
        // rather than dropped, so the join still shows up instead of vanishing.
        $isEligible = $ageDays >= $minAgeDays && $inviterDiscordId !== $joinedDiscordId;

        $existing = DiscordInvitedMember::where('joined_discord_id', $joinedDiscordId)->first();

        $attributes = [
            'inviter_discord_id' => $inviterDiscordId,
            'invite_code' => $inviteCode,
            'account_created_at' => $accountCreatedAt,
            'joined_at' => now(),
            'is_eligible' => $isEligible,
            'matured_at' => now()->addHours($this->getInviteMaturityHours()),
            'has_left' => false,
            'left_at' => null,
            'last_seen_at' => now(),
        ];

        if ($existing) {
            // Re-crediting a rejoin is only correct if the first reward was clawed
            // back; otherwise the row stays rewarded and simply counts again.
            if ($existing->has_left) {
                $attributes['rejoin_count'] = $existing->rejoin_count + 1;
            }

            if ($existing->revoked_at !== null) {
                $attributes['is_rewarded'] = false;
                $attributes['rewarded_at'] = null;
                $attributes['revoked_at'] = null;
                $attributes['revoked_credits'] = 0;
            }

            $existing->update($attributes);
            $record = $existing->refresh();
        } else {
            $record = DiscordInvitedMember::create(['joined_discord_id' => $joinedDiscordId] + $attributes);
        }

        $this->refreshInviteCounters($inviterDiscordId);

        // Zero escrow means "award immediately" — honour it here so the setting does
        // not silently depend on the maturity command's schedule.
        if ($this->getInviteMaturityHours() === 0) {
            $this->awardMaturedInvites($inviterDiscordId);
            $record->refresh();
        }

        return $record;
    }

    /**
     * Record that a tracked member left the guild.
     *
     * The invite stops counting either way. If it had already paid out, the credits
     * are recovered through the cascade in CreditRecoveryService — balance, then
     * purchased resources out of unused headroom, then whatever is left becomes
     * `invite_debt` and is taken out of the inviter's next rewards.
     *
     * Nothing here can fail on an insufficient balance, and nothing can shrink a
     * limit below what the inviter's servers already allocate.
     *
     * @return array{tracked: bool, was_rewarded: bool, recovered: int, shortfall: int, reclaimed: array<string, int>}
     */
    public function recordMemberLeave(string $joinedDiscordId): array
    {
        $result = ['tracked' => false, 'was_rewarded' => false, 'recovered' => 0, 'shortfall' => 0, 'reclaimed' => []];

        $record = DiscordInvitedMember::where('joined_discord_id', $joinedDiscordId)->first();
        if (!$record) {
            return $result;
        }

        $result['tracked'] = true;
        $alreadyMarked = $record->has_left;

        $record->update([
            'has_left' => true,
            'left_at' => now(),
            'leave_count' => $alreadyMarked ? $record->leave_count : $record->leave_count + 1,
        ]);

        // A row can be re-seen as gone by the reconciler on every pass; only the first
        // observation of a given departure may claw anything back.
        if ($record->is_rewarded && $record->revoked_at === null && !$alreadyMarked) {
            $result['was_rewarded'] = true;
            $recovery = $this->clawbackInviteReward($record);
            $result['recovered'] = $recovery['recovered'];
            $result['shortfall'] = $recovery['shortfall'];
            $result['reclaimed'] = $recovery['reclaimed'];
        }

        $this->refreshInviteCounters($record->inviter_discord_id);

        return $result;
    }

    /**
     * Reverse one paid-out invite reward.
     *
     * @return array{recovered: int, shortfall: int, reclaimed: array<string, int>}
     */
    protected function clawbackInviteReward(DiscordInvitedMember $record): array
    {
        $empty = ['recovered' => 0, 'shortfall' => 0, 'reclaimed' => []];

        $amount = $this->getCreditsPerInvite();
        $link = DiscordLink::where('discord_id', $record->inviter_discord_id)->first();
        $user = $link?->user;

        if (!$this->inviteClawbackEnabled() || $amount <= 0 || !$user || !Plugins::available(CreditRecoveryService::class)) {
            $record->update(['revoked_at' => now(), 'revoked_credits' => 0]);

            return $empty;
        }

        $recovery = app(CreditRecoveryService::class)->recover(
            $user,
            $amount,
            'discord_invite_clawback',
            trans('plugged-host-economy::strings.credits.tx_invite_clawback'),
            [
                'joined_discord_id' => $record->joined_discord_id,
                'inviter_discord_id' => $record->inviter_discord_id,
                'invite_code' => $record->invite_code,
                'credits_per_invite' => $amount,
            ]
        );

        $record->update([
            'revoked_at' => now(),
            'revoked_credits' => $recovery['recovered'],
        ]);

        if ($link && $recovery['shortfall'] > 0) {
            $link->increment('invite_debt', $recovery['shortfall']);
        }

        if ($recovery['shortfall'] > 0 || array_sum($recovery['reclaimed']) > 0) {
            Log::info('[Discord] Invite reward clawed back', [
                'user_id' => $user->id,
                'joined_discord_id' => $record->joined_discord_id,
                'requested' => $recovery['requested'],
                'from_credits' => $recovery['from_credits'],
                'from_resources' => $recovery['from_resources'],
                'shortfall' => $recovery['shortfall'],
                'reclaimed' => array_filter($recovery['reclaimed']),
            ]);
        }

        return [
            'recovered' => $recovery['recovered'],
            'shortfall' => $recovery['shortfall'],
            'reclaimed' => array_filter($recovery['reclaimed']),
        ];
    }

    /**
     * Pay out escrowed joins that have matured and whose members are still present.
     *
     * Outstanding `invite_debt` — a previous clawback that could not be covered — is
     * settled out of the gross award before any credits are added, which is how a
     * user who spent everything before their invitee left eventually squares up.
     *
     * @param  ?string  $inviterDiscordId  limit to one inviter, or null for every inviter
     * @return array{awarded_rows: int, gross_credits: int, debt_applied: int, net_credits: int, inviters: int}
     */
    public function awardMaturedInvites(?string $inviterDiscordId = null): array
    {
        $summary = ['awarded_rows' => 0, 'gross_credits' => 0, 'debt_applied' => 0, 'net_credits' => 0, 'inviters' => 0];

        $creditsPerInvite = $this->getCreditsPerInvite();

        $query = DiscordInvitedMember::query()->matured();
        if ($inviterDiscordId !== null) {
            $query->where('inviter_discord_id', $inviterDiscordId);
        }

        $grouped = $query->get()->groupBy('inviter_discord_id');

        foreach ($grouped as $discordId => $records) {
            $link = DiscordLink::where('discord_id', (string) $discordId)->first();
            $user = $link?->user;

            // No panel account behind the Discord id: leave the rows pending rather
            // than marking them rewarded, so the credits still arrive if they link later.
            if (!$link || !$user) {
                continue;
            }

            // Claim the rows before paying them: a parallel check (the profile button, the
            // gateway, the hourly sync) that read the same matured rows only pays the rows it
            // claimed itself, so no invite is paid twice. The claim rolls back if paying fails.
            $paid = DB::transaction(function () use ($link, $user, $records, $creditsPerInvite): ?array {
                $claimed = $records->filter(fn (DiscordInvitedMember $record): bool => DiscordInvitedMember::query()
                    ->whereKey($record->getKey())
                    ->where('is_rewarded', false)
                    ->update(['is_rewarded' => true, 'rewarded_at' => now(), 'updated_at' => now()]) === 1)->values();

                if ($claimed->isEmpty()) {
                    return null;
                }

                $link->refresh();
                $gross = $claimed->count() * $creditsPerInvite;
                $debtApplied = min((int) $link->invite_debt, $gross);
                $net = $gross - $debtApplied;

                $this->payMaturedInvites($link, $user, $claimed, $gross, $debtApplied, $net, $creditsPerInvite);

                return ['rows' => $claimed->count(), 'gross' => $gross, 'debt' => $debtApplied, 'net' => $net];
            });

            if ($paid === null) {
                continue;
            }

            $summary['awarded_rows'] += $paid['rows'];
            $summary['gross_credits'] += $paid['gross'];
            $summary['debt_applied'] += $paid['debt'];
            $summary['net_credits'] += $paid['net'];
            $summary['inviters']++;
        }

        return $summary;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DiscordInvitedMember>  $records
     */
    protected function payMaturedInvites(
        DiscordLink $link,
        User $user,
        $records,
        int $gross,
        int $debtApplied,
        int $net,
        int $creditsPerInvite
    ): void {
        if ($net > 0 && $creditsPerInvite > 0 && Plugins::available(UserCredits::class)) {
            UserCredits::forUser($user)?->addCredits(
                $net,
                'discord_invite',
                trans('plugged-host-discord::strings.tx_invite_reward', ['count' => $records->count()]),
                [
                    'invites' => $records->count(),
                    'credits_per_invite' => $creditsPerInvite,
                    'gross_credits' => $gross,
                    'debt_applied' => $debtApplied,
                    'joined_discord_ids' => $records->pluck('joined_discord_id')->all(),
                ]
            );
        }

        if ($debtApplied > 0) {
            $debtRemaining = max(0, (int) $link->invite_debt - $debtApplied);
            $link->decrement('invite_debt', $debtApplied);

            // Amount 0: the balance did not move, the debt did. Without this the user
            // sees invites mature and no credits arrive, with no explanation anywhere.
            if (Plugins::available(CreditTransaction::class)) {
                CreditTransaction::create([
                    'user_id' => $user->id,
                    'amount' => 0,
                    'balance_after' => (int) (UserCredits::forUser($user)?->credits ?? 0),
                    'type' => 'discord_invite_debt',
                    'description' => trans('plugged-host-discord::strings.tx_invite_debt_settled', ['credits' => $debtApplied]),
                    'metadata' => [
                        'gross_credits' => $gross,
                        'debt_applied' => $debtApplied,
                        'net_credits' => $net,
                        'debt_remaining' => $debtRemaining,
                    ],
                    'created_at' => now(),
                ]);
            }
        }

        DiscordInvitedMember::whereIn('id', $records->pluck('id'))->update([
            'is_rewarded' => true,
            'rewarded_at' => now(),
            'updated_at' => now(),
        ]);

        $this->refreshInviteCounters((string) $link->discord_id);
    }

    /**
     * Recompute an inviter's denormalised counters from the tracked rows.
     *
     * These columns used to be advanced with `max()` against Discord's `invite.uses`,
     * which is monotonic — so a leave could never bring the total down no matter what
     * the tracked data said. They are now purely derived: tracked rows are the only
     * source of truth, and a row that left is simply not counted.
     *
     * @return array{total: int, rewarded: int, left: int, pending: int}
     */
    public function refreshInviteCounters(string $inviterDiscordId): array
    {
        $counts = [
            'total' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->counted()->count(),
            'rewarded' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->counted()->where('is_rewarded', true)->count(),
            'left' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->where('has_left', true)->count(),
            'pending' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->counted()->where('is_rewarded', false)->count(),
        ];

        DiscordLink::where('discord_id', $inviterDiscordId)->update([
            'invite_count' => $counts['total'],
            'invites_rewarded' => $counts['rewarded'],
            'invites_left' => $counts['left'],
        ]);

        return $counts;
    }

    /**
     * REST-check every tracked member not already marked as left, and claw back the
     * ones who are gone.
     *
     * This is the safety net for the gateway: `GET /guilds/{id}/members/{user_id}` works
     * with a plain bot token, unlike the member *list* endpoint, so leave detection keeps
     * working even when the privileged GUILD_MEMBERS intent is off in the Discord
     * Developer Portal and no GUILD_MEMBER_REMOVE event is ever delivered.
     *
     * @param  ?Closure(DiscordInvitedMember, string): void  $progressCallback
     * @return array{checked: int, left: int, present: int, errors: int, recovered: int, shortfall: int}
     */
    public function reconcileTrackedMembers(int $limit = 200, ?Closure $progressCallback = null): array
    {
        $summary = ['checked' => 0, 'left' => 0, 'present' => 0, 'errors' => 0, 'recovered' => 0, 'shortfall' => 0];

        if (empty($this->getBotToken()) || empty($this->getGuildId())) {
            return $summary;
        }

        $records = DiscordInvitedMember::where('has_left', false)
            // Least recently checked first, so a large guild is swept evenly across runs.
            ->orderByRaw('last_seen_at IS NULL DESC')
            ->orderBy('last_seen_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($records as $record) {
            $summary['checked']++;

            $membership = $this->checkGuildMembershipUsingBot($record->joined_discord_id);
            $statusCode = (int) ($membership['status_code'] ?? 0);

            if ($statusCode === 200 && !empty($membership['is_in_guild'])) {
                $record->update(['last_seen_at' => now()]);
                $summary['present']++;
                if ($progressCallback) {
                    $progressCallback($record, 'present');
                }

                continue;
            }

            // Only an affirmative 404 uncounts an invite. Every other outcome — a
            // timeout, a revoked token, a rate limit, a 5xx, or a response shape this
            // code does not recognise — leaves the row exactly as it is, and leaving
            // last_seen_at untouched keeps it at the front of the queue for the next
            // pass. This branch is deliberately the narrow one: reading the verdict
            // wrong used to mean *every* tracked member was recorded as having left,
            // on the hour, which zeroed every leaderboard while the legacy baselines
            // in discord_invite_baselines stayed visible.
            if ($statusCode !== 404) {
                $summary['errors']++;
                if ($progressCallback) {
                    $progressCallback($record, 'error');
                }

                continue;
            }

            $leave = $this->recordMemberLeave($record->joined_discord_id);
            $summary['left']++;
            $summary['recovered'] += $leave['recovered'];
            $summary['shortfall'] += $leave['shortfall'];
            if ($progressCallback) {
                $progressCallback($record, 'left');
            }
        }

        return $summary;
    }

    /**
     * The mirror of the reconcile pass: re-check rows already marked as gone and put
     * back the ones Discord still reports as members.
     *
     * A wrong departure is worse than a missed one — it silently deletes an inviter's
     * credit, and nothing in the normal flow ever revisits `has_left = 1`, so the row
     * stays wrong until someone leaves and rejoins. This exists so that class of
     * corruption is repairable from a command instead of hand-written SQL.
     *
     * A restored row is a correction, not a rejoin: `leave_count` is decremented back,
     * `rejoin_count` is left alone, and `matured_at` is preserved so escrow the member
     * genuinely served is not restarted. Only a departure whose reward was actually
     * clawed back resets the reward state, which lets `awardMaturedInvites()` pay it
     * again and settle any `invite_debt` the clawback created.
     *
     * @param  ?Closure(DiscordInvitedMember, string): void  $progressCallback
     * @return array{checked: int, restored: int, confirmed: int, errors: int, repayable: int}
     */
    public function restoreFalseDepartures(int $limit = 200, ?Closure $progressCallback = null): array
    {
        $summary = ['checked' => 0, 'restored' => 0, 'confirmed' => 0, 'errors' => 0, 'repayable' => 0];

        if (empty($this->getBotToken()) || empty($this->getGuildId())) {
            return $summary;
        }

        $records = DiscordInvitedMember::where('has_left', true)
            ->orderBy('left_at')
            ->limit(max(1, $limit))
            ->get();

        $inviters = [];

        foreach ($records as $record) {
            $summary['checked']++;

            $membership = $this->checkGuildMembershipUsingBot($record->joined_discord_id);
            $statusCode = (int) ($membership['status_code'] ?? 0);

            if ($statusCode === 404) {
                $summary['confirmed']++;
                if ($progressCallback) {
                    $progressCallback($record, 'confirmed');
                }

                continue;
            }

            if ($statusCode !== 200 || empty($membership['is_in_guild'])) {
                $summary['errors']++;
                if ($progressCallback) {
                    $progressCallback($record, 'error');
                }

                continue;
            }

            $attributes = [
                'has_left' => false,
                'left_at' => null,
                'leave_count' => max(0, $record->leave_count - 1),
                'last_seen_at' => now(),
            ];

            if ($record->revoked_at !== null) {
                $attributes['is_rewarded'] = false;
                $attributes['rewarded_at'] = null;
                $attributes['revoked_at'] = null;
                $attributes['revoked_credits'] = 0;
            }

            $record->update($attributes);

            $inviters[$record->inviter_discord_id] = true;
            $summary['restored']++;

            if (!$record->fresh()?->is_rewarded && $record->is_eligible) {
                $summary['repayable']++;
            }

            if ($progressCallback) {
                $progressCallback($record, 'restored');
            }
        }

        foreach (array_keys($inviters) as $inviterDiscordId) {
            $this->refreshInviteCounters((string) $inviterDiscordId);
        }

        return $summary;
    }

    /**
     * Get a comprehensive summary of invites and rewards for any Discord user.
     *
     * Counts come only from tracked joins. Discord's `invite.uses` is deliberately not
     * folded in any more: it never decrements, so mixing it in with `max()` meant a
     * member leaving could never lower the total — the whole reason the counter was
     * stuck. `api_uses` is still reported, but as raw context, not as a count.
     *
     * `legacy_invites` is the frozen pre-tracking baseline and is reported separately for
     * the same reason: it is a number to show, not a number to pay.
     *
     * @return array{
     *     discord_user_id: string,
     *     total_invites: int,
     *     valid_invites: int,
     *     pending_invites: int,
     *     ineligible_invites: int,
     *     left_invites: int,
     *     legacy_invites: int,
     *     combined_invites: int,
     *     api_uses: int,
     *     min_account_age_days: int,
     *     maturity_hours: int,
     *     credits_per_invite: int,
     *     credits_earned: int,
     *     credits_pending: int,
     *     invite_debt: int,
     *     is_boosting: bool,
     *     boost_count: int,
     *     panel_user: ?User,
     *     discord_link: ?DiscordLink,
     *     current_panel_balance: ?int
     * }
     */
    public function getInvitesSummaryForDiscordUser(string $discordUserId, ?array $memberData = null): array
    {
        $link = DiscordLink::where('discord_id', $discordUserId)->first();
        $user = $link?->user;

        // Fetch member data if not passed
        $memberData ??= $this->fetchMemberData($discordUserId);

        $isBoosting = false;
        $boostCount = 0;

        if ($user && $link) {
            if ($memberData !== null) {
                $boostSync = $this->syncBoosts($user, $link, $memberData);
                $isBoosting = (bool) ($boostSync['is_boosting'] ?? false);
                $boostCount = (int) ($boostSync['boost_count'] ?? 0);
                $link->refresh();
            } else {
                $isBoosting = (bool) ($link->is_boosting ?? false);
                $boostCount = (int) ($link->boost_count ?? 0);
            }
        } elseif ($memberData !== null) {
            $roles = (array) ($memberData['roles'] ?? []);
            $hasBoosterRole = $this->hasBoosterRole($roles);
            $isBoosting = !empty($memberData['premium_since']) || $hasBoosterRole;
            if ($isBoosting) {
                $boostCount = $this->resolveMemberBoostCount($discordUserId, $roles, $link);
            }
        }

        if ($isBoosting && $boostCount < 1) {
            $boostCount = 1;
        }

        $counts = $this->inviteCountsFor($discordUserId);

        $apiUses = 0;
        $guildInvites = $this->fetchGuildInvites();
        if ($guildInvites) {
            foreach ($guildInvites as $inv) {
                if (is_array($inv) && isset($inv['inviter']['id']) && (string) $inv['inviter']['id'] === $discordUserId) {
                    $apiUses += (int) ($inv['uses'] ?? 0);
                }
            }
        }

        $creditsPerInvite = $this->getCreditsPerInvite();
        $legacyInvites = $this->legacyInviteCountFor($discordUserId);

        return [
            'discord_user_id' => $discordUserId,
            'total_invites' => $counts['total'],
            'valid_invites' => $counts['rewarded'],
            'pending_invites' => $counts['pending'],
            'ineligible_invites' => $counts['ineligible'],
            'left_invites' => $counts['left'],
            'legacy_invites' => $legacyInvites,
            'combined_invites' => $counts['total'] + $legacyInvites,
            'api_uses' => $apiUses,
            'min_account_age_days' => $this->getMinAccountAgeDays(),
            'maturity_hours' => $this->getInviteMaturityHours(),
            'credits_per_invite' => $creditsPerInvite,
            'credits_earned' => $counts['rewarded'] * $creditsPerInvite,
            'credits_pending' => $counts['pending'] * $creditsPerInvite,
            'invite_debt' => (int) ($link?->invite_debt ?? 0),
            'is_boosting' => $isBoosting,
            'boost_count' => $boostCount,
            'panel_user' => $user,
            'discord_link' => $link,
            'current_panel_balance' => $user && Plugins::available(UserCredits::class)
                ? (UserCredits::forUser($user)?->credits ?? 0)
                : null,
        ];
    }

    /**
     * Tracked-join counts for one inviter.
     *
     * `total` counts everyone still present and old enough — pending and rewarded
     * alike, because a join in escrow is a real join that simply has not paid yet.
     *
     * @return array{total: int, rewarded: int, pending: int, ineligible: int, left: int}
     */
    public function inviteCountsFor(string $inviterDiscordId): array
    {
        return [
            'total' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->counted()->count(),
            'rewarded' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->counted()->where('is_rewarded', true)->count(),
            'pending' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->counted()->where('is_rewarded', false)->count(),
            'ineligible' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->where('has_left', false)->where('is_eligible', false)->count(),
            'left' => DiscordInvitedMember::where('inviter_discord_id', $inviterDiscordId)->where('has_left', true)->count(),
        ];
    }

    /**
     * Freeze each inviter's pre-tracking invite total from Discord's `invite.uses`.
     *
     * Tracked joins only exist from the moment the gateway daemon witnessed them, so a
     * guild that ran without the privileged GUILD_MEMBERS intent has real invites that
     * this plugin has no rows for. Discord exposes no way to map an existing member back
     * to the code they joined on, so the gap can only ever be recovered as a per-inviter
     * total — no invitee identity, no account age to check, nothing to escrow, nothing to
     * claw back. Hence a baseline that is shown and never paid.
     *
     * `legacy_uses` subtracts the tracked rows that already exist for the inviter, so
     * re-running this after tracking has started cannot fold a counted join into the
     * legacy figure and show it twice. Existing rows are left alone unless `$force` is
     * set, because the whole point of a baseline is that it stops moving.
     *
     * @return array{captured: int, skipped: int, inviters: int, legacy_total: int, api_total: int, codes: int, existing: int, dry_run: bool, error: ?string, rows: array<int, array{discord_id: string, username: ?string, api_uses: int, tracked: int, legacy: int, action: string}>}
     */
    public function captureLegacyInviteBaseline(bool $force = false, bool $dryRun = false): array
    {
        $summary = [
            'captured' => 0,
            'skipped' => 0,
            'inviters' => 0,
            'legacy_total' => 0,
            'api_total' => 0,
            'codes' => 0,
            'existing' => DiscordInviteBaseline::count(),
            'dry_run' => $dryRun,
            'error' => null,
            'rows' => [],
        ];

        $invites = $this->fetchGuildInvites();

        if ($invites === null) {
            $summary['error'] = 'Could not read guild invites from Discord. The bot needs the Manage Server permission, and the token and guild ID must be configured.';

            return $summary;
        }

        // Fold the code list down to one entry per inviter. Codes with no inviter are
        // vanity or widget invites and belong to nobody, so they are dropped rather than
        // attributed to an arbitrary account.
        $perInviter = [];
        foreach ($invites as $invite) {
            if (!is_array($invite)) {
                continue;
            }

            $inviterId = $invite['inviter']['id'] ?? null;
            if (empty($inviterId)) {
                continue;
            }

            $inviterId = (string) $inviterId;
            $perInviter[$inviterId] ??= ['uses' => 0, 'codes' => 0, 'username' => null];
            $perInviter[$inviterId]['uses'] += (int) ($invite['uses'] ?? 0);
            $perInviter[$inviterId]['codes']++;
            $perInviter[$inviterId]['username'] ??= $invite['inviter']['username'] ?? null;
        }

        $summary['inviters'] = count($perInviter);

        foreach ($perInviter as $inviterId => $data) {
            $summary['api_total'] += $data['uses'];
            $summary['codes'] += $data['codes'];

            $existing = DiscordInviteBaseline::where('inviter_discord_id', $inviterId)->first();

            if ($existing && !$force) {
                $summary['skipped']++;
                $summary['legacy_total'] += (int) $existing->legacy_uses;
                $summary['rows'][] = [
                    'discord_id' => $inviterId,
                    'username' => $existing->inviter_username ?? $data['username'],
                    'api_uses' => $data['uses'],
                    'tracked' => (int) $existing->tracked_at_capture,
                    'legacy' => (int) $existing->legacy_uses,
                    'action' => 'kept',
                ];

                continue;
            }

            // Every tracked row, including members who have since left: each one stands
            // for a use of an invite code that this plugin has already accounted for.
            $tracked = DiscordInvitedMember::where('inviter_discord_id', $inviterId)->count();
            $legacy = max(0, $data['uses'] - $tracked);

            $summary['legacy_total'] += $legacy;
            $summary['captured']++;
            $summary['rows'][] = [
                'discord_id' => $inviterId,
                'username' => $data['username'],
                'api_uses' => $data['uses'],
                'tracked' => $tracked,
                'legacy' => $legacy,
                'action' => $existing ? 'recaptured' : 'captured',
            ];

            if ($dryRun) {
                continue;
            }

            DiscordInviteBaseline::updateOrCreate(
                ['inviter_discord_id' => $inviterId],
                [
                    'inviter_username' => $data['username'],
                    'legacy_uses' => $legacy,
                    'api_uses_at_capture' => $data['uses'],
                    'tracked_at_capture' => $tracked,
                    'invite_codes' => $data['codes'],
                    'captured_at' => now(),
                ]
            );
        }

        usort($summary['rows'], fn (array $a, array $b) => $b['legacy'] <=> $a['legacy']);

        return $summary;
    }

    /**
     * Captured baselines, keyed by inviter Discord id.
     *
     * @return \Illuminate\Support\Collection<string, DiscordInviteBaseline>
     */
    public function legacyInviteBaselines()
    {
        return DiscordInviteBaseline::query()->get()->keyBy(fn (DiscordInviteBaseline $row) => (string) $row->inviter_discord_id);
    }

    /**
     * One inviter's display-only pre-tracking total. Zero when no baseline was captured.
     */
    public function legacyInviteCountFor(string $inviterDiscordId): int
    {
        return (int) (DiscordInviteBaseline::where('inviter_discord_id', $inviterDiscordId)->value('legacy_uses') ?? 0);
    }

    /**
     * Leaderboard of inviters, derived purely from tracked joins.
     *
     * Neither `DiscordLink.invite_count` nor Discord's `invite.uses` is consulted any
     * more: both are monotonic, so seeding the board from them kept crediting people
     * for members who had already left. Only rows still present in the guild count.
     *
     * The one exception is `legacy_invites`, a frozen pre-tracking figure from
     * `discord_invite_baselines`. It is reported alongside the counted totals and never
     * folded into them or into `credits_earned` — see `captureLegacyInviteBaseline()`.
     * Ranking uses counted + legacy so a board that is entirely historical still sorts
     * sensibly, with counted invites breaking ties in favour of verified joins.
     *
     * @return array<int, array{
     *     rank: int,
     *     discord_user_id: string,
     *     discord_username: ?string,
     *     panel_username: ?string,
     *     total_invites: int,
     *     valid_invites: int,
     *     ineligible_invites: int,
     *     pending_invites: int,
     *     left_invites: int,
     *     legacy_invites: int,
     *     combined_invites: int,
     *     credits_earned: int
     * }>
     */
    public function getTopInviters(int $limit = 10): array
    {
        $creditsPerInvite = $this->getCreditsPerInvite();

        $rows = DiscordInvitedMember::query()
            ->select('inviter_discord_id')
            ->selectRaw('SUM(CASE WHEN has_left = 0 AND is_eligible = 1 THEN 1 ELSE 0 END) as valid_total')
            ->selectRaw('SUM(CASE WHEN has_left = 0 AND is_eligible = 1 AND is_rewarded = 1 THEN 1 ELSE 0 END) as rewarded_total')
            ->selectRaw('SUM(CASE WHEN has_left = 0 AND is_eligible = 1 AND is_rewarded = 0 THEN 1 ELSE 0 END) as pending_total')
            ->selectRaw('SUM(CASE WHEN has_left = 0 AND is_eligible = 0 THEN 1 ELSE 0 END) as ineligible_total')
            ->selectRaw('SUM(CASE WHEN has_left = 1 THEN 1 ELSE 0 END) as left_total')
            ->groupBy('inviter_discord_id')
            ->havingRaw('SUM(CASE WHEN has_left = 0 THEN 1 ELSE 0 END) > 0')
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $entries[(string) $row->inviter_discord_id] = [
                'counted' => (int) $row->valid_total,
                'rewarded' => (int) $row->rewarded_total,
                'pending' => (int) $row->pending_total,
                'ineligible' => (int) $row->ineligible_total,
                'left' => (int) $row->left_total,
                'legacy' => 0,
                'baseline_username' => null,
            ];
        }

        foreach ($this->legacyInviteBaselines() as $discordId => $baseline) {
            $legacy = max(0, (int) $baseline->legacy_uses);

            // A baseline of zero adds nobody to the board: they never invited anyone
            // before tracking started, and their tracked rows already speak for them.
            if ($legacy === 0 && !isset($entries[$discordId])) {
                continue;
            }

            $entries[$discordId] ??= [
                'counted' => 0,
                'rewarded' => 0,
                'pending' => 0,
                'ineligible' => 0,
                'left' => 0,
                'legacy' => 0,
                'baseline_username' => null,
            ];
            $entries[$discordId]['legacy'] = $legacy;
            $entries[$discordId]['baseline_username'] = $baseline->inviter_username;
        }

        uasort($entries, fn (array $a, array $b) => [
            $b['counted'] + $b['legacy'], $b['counted'], $b['rewarded'], $b['ineligible'],
        ] <=> [
            $a['counted'] + $a['legacy'], $a['counted'], $a['rewarded'], $a['ineligible'],
        ]);

        $entries = array_slice($entries, 0, max(1, $limit), true);

        $links = DiscordLink::query()
            ->whereIn('discord_id', array_keys($entries))
            ->with('user')
            ->get()
            ->keyBy('discord_id');

        $result = [];
        $rank = 1;

        foreach ($entries as $discordId => $entry) {
            $link = $links->get($discordId);

            $result[] = [
                'rank' => $rank++,
                'discord_user_id' => (string) $discordId,
                // The baseline name is the fallback, not the preference: it is a snapshot
                // from capture time, while the link is refreshed on every verification.
                // It is still the only name available for an inviter with no panel account.
                'discord_username' => $link?->discord_username ?? $entry['baseline_username'],
                'panel_username' => $link?->user?->username,
                // Same meaning as everywhere else: present in the guild and old enough,
                // escrowed joins included. `valid_invites` is the subset actually paid.
                'total_invites' => $entry['counted'],
                'valid_invites' => $entry['rewarded'],
                'ineligible_invites' => $entry['ineligible'],
                'pending_invites' => $entry['pending'],
                'left_invites' => $entry['left'],
                'legacy_invites' => $entry['legacy'],
                'combined_invites' => $entry['counted'] + $entry['legacy'],
                'credits_earned' => $entry['rewarded'] * $creditsPerInvite,
            ];
        }

        return $result;
    }

    /**
     * Refresh a user's invite counters, paying out anything that has cleared escrow.
     *
     * This used to be the award path, and it awarded against
     * `max($trackedTotal, $apiUses, $link->invite_count)` — three monotonic sources, so
     * a departure could never lower the total, and `max($previousRewarded, $valid)` made
     * a clawback impossible to express. Awarding now lives solely in
     * `awardMaturedInvites()`; this method only derives the counters from tracked rows
     * and reports what the payout run did. `$guildInvites` is still accepted for
     * call-site compatibility and deliberately ignored — `invite.uses` never decrements,
     * so it cannot be part of a count that has to be able to go down.
     *
     * @return array{total_invites: int, valid_invites: int, ineligible_invites: int, pending_invites: int, left_invites: int, new_invites: int, credits_earned: int, debt_applied: int, success: bool, message: string}
     */
    public function syncInvites(User $user, ?DiscordLink $link = null, ?array $guildInvites = null): array
    {
        $discordUserId = $this->getLinkedDiscordId($user);
        if (!$discordUserId) {
            return [
                'total_invites' => 0,
                'valid_invites' => 0,
                'ineligible_invites' => 0,
                'pending_invites' => 0,
                'left_invites' => 0,
                'new_invites' => 0,
                'credits_earned' => 0,
                'debt_applied' => 0,
                'success' => false,
                'message' => 'No Discord account linked.',
            ];
        }

        // Pay out what has matured for this inviter first, then read the counters back,
        // so a join that cleared escrow a second ago shows up in the same response.
        $award = $this->awardMaturedInvites($discordUserId);
        $this->refreshInviteCounters($discordUserId);
        $counts = $this->inviteCountsFor($discordUserId);

        $total = $counts['total'];
        $valid = $counts['rewarded'];
        $ineligible = $counts['ineligible'];

        $message = "Synchronized {$total} counted invite(s) ({$valid} paid, {$counts['pending']} pending, "
            . "{$ineligible} ineligible, {$counts['left']} left, +{$award['net_credits']} credits).";

        if ($award['debt_applied'] > 0) {
            $message .= " {$award['debt_applied']} credits settled outstanding invite debt.";
        }

        return [
            'total_invites' => $total,
            'valid_invites' => $valid,
            'ineligible_invites' => $ineligible,
            'pending_invites' => $counts['pending'],
            'left_invites' => $counts['left'],
            'new_invites' => $award['awarded_rows'],
            'credits_earned' => $award['net_credits'],
            'debt_applied' => $award['debt_applied'],
            'success' => true,
            'message' => $message,
        ];
    }

    /**
     * Synchronize and reward Discord server boost status for a user.
     *
     * @return array{is_boosting: bool, boost_count: int, credits_earned: int, success: bool, message: string}
     */
    public function syncBoosts(User $user, ?DiscordLink $link = null, ?array $memberData = null): array
    {
        $discordUserId = $this->getLinkedDiscordId($user);
        if (!$discordUserId) {
            return [
                'is_boosting' => false,
                'boost_count' => 0,
                'credits_earned' => 0,
                'success' => false,
                'message' => 'No Discord account linked.',
            ];
        }

        $link ??= DiscordLink::where('user_id', $user->id)->first();
        $memberData ??= $this->fetchMemberData($discordUserId);

        if ($memberData === null) {
            return [
                'is_boosting' => $link?->is_boosting ?? false,
                'boost_count' => $link?->boost_count ?? 0,
                'credits_earned' => 0,
                'success' => false,
                'message' => 'Discord bot token not configured or member data unavailable.',
            ];
        }

        $roles = (array) ($memberData['roles'] ?? []);
        $hasBoosterRole = $this->hasBoosterRole($roles);
        $isBoosting = !empty($memberData['premium_since']) || $hasBoosterRole;
        $boostedSince = !empty($memberData['premium_since'])
            ? \Carbon\Carbon::parse($memberData['premium_since'])
            : ($isBoosting ? ($link?->boosted_since ?? now()) : null);

        $boostCount = 0;
        if ($isBoosting) {
            $boostCount = $this->resolveMemberBoostCount($discordUserId, $roles, $link);
        }

        $previousRewarded = $link ? (int) $link->boosts_rewarded : 0;
        $creditsToAward = 0;

        // noprofit.space pays the boost rewards, for boosts on any of its Discord servers
        // (DiscordServersStatus); then this only keeps plugged.host's own boost state and role.
        $paysBoosts = !app(DiscordServersStatus::class)->available();

        if ($paysBoosts && $boostCount >= 1 && $previousRewarded < 1) {
            $creditsToAward += $this->getCreditsPerBoost1();
        }

        if ($paysBoosts && $boostCount >= 2 && $previousRewarded < 2) {
            $creditsToAward += max(0, $this->getCreditsPerBoost2() - $this->getCreditsPerBoost1());
        }

        $newRewarded = $paysBoosts ? max($previousRewarded, $boostCount) : $previousRewarded;

        if ($creditsToAward > 0 && Plugins::available(UserCredits::class)) {
            // Claim the tier before paying it: $previousRewarded was read before a Discord round
            // trip, so a parallel sync may have paid it already. Only the request that moves
            // boosts_rewarded from that value pays; the claim rolls back if paying fails.
            DB::transaction(function () use ($link, $user, $previousRewarded, $newRewarded, $creditsToAward, $boostCount, $boostedSince): void {
                $claimed = $link === null || DiscordLink::query()
                    ->whereKey($link->getKey())
                    ->where('boosts_rewarded', $previousRewarded)
                    ->update(['boosts_rewarded' => $newRewarded]) === 1;

                if (!$claimed) {
                    return;
                }

                UserCredits::forUser($user)?->addCredits(
                    $creditsToAward,
                    'discord_boost',
                    trans('plugged-host-discord::strings.tx_boost_reward', ['count' => $boostCount]),
                    [
                        'boost_count' => $boostCount,
                        'boosted_since' => $boostedSince?->toIso8601String(),
                    ]
                );
            });
        }

        if ($link) {
            $link->update([
                'is_boosting' => $isBoosting,
                'boosted_since' => $boostedSince,
                'boost_count' => $boostCount,
                'boosts_rewarded' => $newRewarded,
                'guild_roles' => $roles,
            ]);
        }

        // Sync Discord role to match boost count
        if ($boostCount >= 2) {
            $this->assignBoost2Role($discordUserId);
        } elseif (!$isBoosting) {
            $this->removeBoost2Role($discordUserId);
        }

        return [
            'is_boosting' => $isBoosting,
            'boost_count' => $boostCount,
            'credits_earned' => $creditsToAward,
            'success' => true,
            'message' => $isBoosting ? "Active booster ({$boostCount} boosts, +{$creditsToAward} credits)." : "Not currently boosting.",
        ];
    }

    /**
     * Synchronize all rewards (invites and boosts) for a user.
     *
     * @return array{
     *     invites: array{total_invites: int, valid_invites: int, ineligible_invites: int, pending_invites: int, left_invites: int, new_invites: int, credits_earned: int, debt_applied: int, success: bool, message: string},
     *     boosts: array{is_boosting: bool, boost_count: int, credits_earned: int, success: bool, message: string},
     *     total_credits: int
     * }
     */
    public function syncRewards(User $user, ?DiscordLink $link = null, ?array $guildInvites = null, ?array $memberData = null): array
    {
        $link ??= DiscordLink::where('user_id', $user->id)->first();

        $inviteResult = $this->syncInvites($user, $link, $guildInvites);
        $boostResult = $this->syncBoosts($user, $link, $memberData);

        return [
            'invites' => $inviteResult,
            'boosts' => $boostResult,
            'total_credits' => (int) ($inviteResult['credits_earned'] + $boostResult['credits_earned']),
        ];
    }

    /**
     * Check guild membership for a user using either their OAuth token or the Bot token.
     *
     * @return array{is_in_guild: bool, status_code: int, error: ?string}
     */
    public function checkMembership(User $user, ?DiscordLink $link = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'is_in_guild' => false,
                'status_code' => 0,
                'error' => 'Discord Guild ID is not configured.',
            ];
        }

        $link ??= DiscordLink::where('user_id', $user->id)->first();
        $discordUserId = $this->getLinkedDiscordId($user);

        if (!$discordUserId) {
            return [
                'is_in_guild' => false,
                'status_code' => 0,
                'error' => 'User has no linked Discord account.',
            ];
        }

        // Method 1: Try using User OAuth access token with guilds permission
        $accessToken = $link?->access_token;

        // If access token is expired, attempt refresh
        if ($link && ($link->token_expires_at && $link->token_expires_at->isPast()) && $link->refresh_token) {
            $accessToken = $this->refreshUserToken($link) ?: $accessToken;
        }

        if ($accessToken) {
            $result = $this->checkGuildMembershipUsingUserToken($accessToken);
            if ($result['status_code'] === 200) {
                return $result;
            }

            // If token expired (401) and we have refresh token, attempt refresh
            if ($result['status_code'] === 401 && $link?->refresh_token) {
                $refreshed = $this->refreshUserToken($link);
                if ($refreshed) {
                    $result = $this->checkGuildMembershipUsingUserToken($refreshed);
                    if ($result['status_code'] === 200) {
                        return $result;
                    }
                }
            }
        }

        // Method 2: Fallback to Bot Token if provided
        if (!empty($this->getBotToken())) {
            $botResult = $this->checkGuildMembershipUsingBot($discordUserId);
            if ($botResult['status_code'] === 200 || $botResult['status_code'] === 404) {
                return $botResult;
            }
        }

        // If neither token succeeded
        return [
            'is_in_guild' => false,
            'status_code' => 401,
            'error' => 'Discord authorization expired or missing. Please re-link your Discord account.',
        ];
    }

    /**
     * Verify and sync a user's Discord link and panel role.
     *
     * @return array{status: string, message: string, role_assigned: bool, role_removed: bool, link: ?DiscordLink}
     */
    public function syncUser(User $user): array
    {
        $roleName = $this->getRoleName();
        $discordUserId = $this->getLinkedDiscordId($user);
        $unlinkedRemoveRole = (bool) config('plugged-host-discord.unlinked_remove_role', true);
        $link = DiscordLink::where('user_id', $user->id)->first();

        // 1. Case: User has no Discord linked
        if (empty($discordUserId)) {
            $roleRemoved = false;

            if ($unlinkedRemoveRole && $this->userHasRole($user, $roleName)) {
                $user->removeRole($roleName);
                $roleRemoved = true;
            }

            if ($link) {
                $link->update([
                    'discord_id' => null,
                    'discord_username' => null,
                    'discord_avatar' => null,
                    'access_token' => null,
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'is_in_guild' => false,
                    'last_verified_at' => now(),
                ]);
            }

            return [
                'status' => 'not_linked',
                'message' => 'No Discord account is currently linked.',
                'role_assigned' => false,
                'role_removed' => $roleRemoved,
                'link' => $link,
            ];
        }

        // 2. Case: Check Guild Membership
        $check = $this->checkMembership($user, $link);

        if (!$link) {
            $link = DiscordLink::create([
                'user_id' => $user->id,
                'discord_id' => $discordUserId,
                'is_in_guild' => $check['is_in_guild'],
                'last_verified_at' => now(),
            ]);
        } else {
            $link->update([
                'discord_id' => $discordUserId,
                'is_in_guild' => $check['is_in_guild'],
                'last_verified_at' => now(),
            ]);
        }

        // 3. User is confirmed in the Discord Guild
        if ($check['is_in_guild']) {
            $roleAssigned = false;

            // Ensure role exists in panel
            $this->ensureRoleExists($roleName);

            if (!$this->userHasRole($user, $roleName)) {
                $user->assignRole($roleName);
                $roleAssigned = true;
            }

            // Automatically sync invite and boost rewards if bot token is available
            if (!empty($this->getBotToken())) {
                try {
                    $this->syncRewards($user, $link);
                } catch (\Throwable $e) {
                    Log::warning("Failed to sync Discord rewards for user {$user->id}: {$e->getMessage()}");
                }
            }

            return [
                'status' => 'verified',
                'message' => "Successfully verified as a member of the Discord server. Panel role '{$roleName}' is active.",
                'role_assigned' => $roleAssigned,
                'role_removed' => false,
                'link' => $link->refresh(),
            ];
        }

        // 4. User is linked, but not in Discord Guild
        if ($check['status_code'] === 200 && !$check['is_in_guild']) {
            $roleRemoved = false;

            if ($unlinkedRemoveRole && $this->userHasRole($user, $roleName)) {
                $user->removeRole($roleName);
                $roleRemoved = true;
            }

            return [
                'status' => 'not_in_guild',
                'message' => 'Your Discord account is linked, but you are not a member of our Discord server.',
                'role_assigned' => false,
                'role_removed' => $roleRemoved,
                'link' => $link->refresh(),
            ];
        }

        // 5. Auth Expired or Error
        return [
            'status' => 'error',
            'message' => $check['error'] ?? 'An error occurred while verifying Discord membership.',
            'role_assigned' => false,
            'role_removed' => false,
            'link' => $link->refresh(),
        ];
    }

    /**
     * Unlink a user's Discord account.
     */
    public function unlinkUser(User $user): void
    {
        $roleName = $this->getRoleName();
        $unlinkedRemoveRole = (bool) config('plugged-host-discord.unlinked_remove_role', true);

        // Remove from user oauth attribute
        $oauth = $user->oauth ?? [];
        if (is_array($oauth) && isset($oauth['discord'])) {
            unset($oauth['discord']);
            $user->update(['oauth' => $oauth]);
        }

        // Remove panel role if configured
        if ($unlinkedRemoveRole && $this->userHasRole($user, $roleName)) {
            $user->removeRole($roleName);
        }

        // Clear DiscordLink record
        DiscordLink::where('user_id', $user->id)->update([
            'discord_id' => null,
            'discord_username' => null,
            'discord_avatar' => null,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'is_in_guild' => false,
            'is_boosting' => false,
            'boost_count' => 0,
            'guild_roles' => null,
            'last_verified_at' => now(),
        ]);
    }

    /**
     * Synchronize all users that have linked Discord accounts.
     *
     * @param ?Closure(User, array): void $progressCallback
     * @return array{total: int, verified: int, not_in_guild: int, not_linked: int, errors: int}
     */
    public function syncAllUsers(?Closure $progressCallback = null): array
    {
        $stats = [
            'total' => 0,
            'verified' => 0,
            'not_in_guild' => 0,
            'not_linked' => 0,
            'errors' => 0,
        ];

        $users = User::query()
            ->where(function ($query) {
                $query->whereNotNull('oauth->discord')
                    ->orWhereHas('discordLink', function ($q) {
                        $q->whereNotNull('discord_id');
                    });
            })
            ->get();

        $stats['total'] = $users->count();

        // Pre-fetch guild invites once for the entire batch to conserve API rate limits
        $guildInvites = $this->fetchGuildInvites();

        foreach ($users as $user) {
            $result = $this->syncUser($user);

            // Also explicitly sync rewards with the pre-fetched guild invites if verified
            if ($result['status'] === 'verified' && !empty($this->getBotToken())) {
                try {
                    $this->syncRewards($user, $result['link'] ?? null, $guildInvites);
                } catch (\Throwable $e) {
                    Log::warning("Batch reward sync failed for user {$user->id}: {$e->getMessage()}");
                }
            }

            match ($result['status']) {
                'verified' => $stats['verified']++,
                'not_in_guild' => $stats['not_in_guild']++,
                'not_linked' => $stats['not_linked']++,
                default => $stats['errors']++,
            };

            if ($progressCallback) {
                $progressCallback($user, $result);
            }
        }

        return $stats;
    }

    /**
     * Check if a user has a specific panel role.
     */
    public function userHasRole(User $user, string $roleName): bool
    {
        return $user->roles()->where('name', $roleName)->exists();
    }

    /**
     * Ensure the target role exists in the panel database.
     */
    public function ensureRoleExists(string $roleName): Role
    {
        return Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
    }
}
