<?php

namespace PluggedHost\Discord\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Models\DiscordLink;

class DiscordBotService
{
    public function __construct(
        protected DiscordVerificationService $verificationService
    ) {}

    /**
     * Build a formatted Discord Embed array for an /invites interaction response.
     */
    public function buildInvitesEmbed(
        string $targetDiscordId,
        ?string $targetUsername = null,
        ?string $targetAvatar = null,
        ?array $memberData = null
    ): array
    {
        $summary = $this->verificationService->getInvitesSummaryForDiscordUser($targetDiscordId, $memberData);
        $minAgeDays = $summary['min_account_age_days'];

        $displayName = $targetUsername ?? ($summary['discord_link']?->discord_username ?? "User <@{$targetDiscordId}>");
        $avatarUrl = $targetAvatar
            ? (str_starts_with($targetAvatar, 'http') ? $targetAvatar : "https://cdn.discordapp.com/avatars/{$targetDiscordId}/{$targetAvatar}.png?size=256")
            : ($summary['discord_link']?->getAvatarUrl() ?? "https://cdn.discordapp.com/embed/avatars/0.png");

        $panelUser = $summary['panel_user'];
        $panelAccountText = $panelUser
            ? "Linked to **{$panelUser->username}** (Credits: **" . number_format($summary['current_panel_balance'] ?? 0) . "**)"
            : "*No panel account linked*";

        $boostCount = (int) ($summary['boost_count'] ?? 0);
        $isBoosting = (bool) ($summary['is_boosting'] ?? false);
        if ($isBoosting && $boostCount < 1) {
            $boostCount = 1;
        }

        $boostText = $isBoosting
            ? "✨ Active Booster ({$boostCount} Boost" . ($boostCount > 1 ? 's' : '') . ")"
            : "No active boost";

        $maturityHours = (int) $summary['maturity_hours'];
        $pendingLabel = $maturityHours > 0 ? "⏳ Pending ({$maturityHours}h hold)" : '⏳ Pending';

        $fields = [
            [
                // "Counted" and not "total": members who left are removed from this number.
                'name' => '📋 Counted Invites',
                'value' => "**{$summary['total_invites']}**",
                'inline' => true,
            ],
            [
                'name' => '💰 Paid Out',
                'value' => "**{$summary['valid_invites']}**",
                'inline' => true,
            ],
            [
                'name' => $pendingLabel,
                'value' => "**{$summary['pending_invites']}** (+" . number_format($summary['credits_pending']) . " credits)",
                'inline' => true,
            ],
            [
                'name' => "⚠️ Ineligible (<{$minAgeDays}d)",
                'value' => "**{$summary['ineligible_invites']}**",
                'inline' => true,
            ],
            [
                'name' => '🚪 Left (uncounted)',
                'value' => "**{$summary['left_invites']}**",
                'inline' => true,
            ],
            [
                'name' => '🪙 Credits from Invites',
                'value' => "**+" . number_format($summary['credits_earned']) . "** credits",
                'inline' => true,
            ],
        ];

        if ($summary['invite_debt'] > 0) {
            $fields[] = [
                'name' => '💳 Invite Debt',
                'value' => "**" . number_format($summary['invite_debt']) . "** credits — taken from future invite rewards",
                'inline' => true,
            ];
        }

        // Only shown when there is a baseline to show. Kept out of every other number in
        // this embed on purpose: it predates join tracking, so it cannot be verified
        // against an account age, held in escrow, or reclaimed, and it pays nothing.
        if (($summary['legacy_invites'] ?? 0) > 0) {
            $fields[] = [
                'name' => '📜 Legacy (pre-tracking)',
                'value' => "**" . number_format($summary['legacy_invites']) . "** — unverified, pays no credits",
                'inline' => true,
            ];
        }

        $fields[] = [
            'name' => '🚀 Server Boost',
            'value' => $boostText,
            'inline' => true,
        ];

        $fields[] = [
            'name' => '🔗 Panel Account',
            'value' => $panelAccountText,
            'inline' => false,
        ];

        $footer = "plugged.host • Minimum account age: {$minAgeDays} days";
        if ($maturityHours > 0) {
            $footer .= " • Rewards are held {$maturityHours}h and reversed if the member leaves";
        }

        $embed = [
            'title' => "📨 Invite & Reward Summary",
            'description' => "Invite statistics and panel rewards for **{$displayName}**",
            'color' => 0x5865F2, // Discord Blurple
            'thumbnail' => [
                'url' => $avatarUrl,
            ],
            'fields' => $fields,
            'footer' => [
                'text' => $footer,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return $embed;
    }

    /**
     * Build a formatted Discord Embed array for a /topinvites interaction response.
     */
    public function buildTopInvitesEmbed(int $limit = 10): array
    {
        $topList = $this->verificationService->getTopInviters($limit);
        $minAgeDays = $this->verificationService->getMinAccountAgeDays();
        $creditsPerInvite = $this->verificationService->getCreditsPerInvite();

        $legacyTotal = array_sum(array_column($topList, 'legacy_invites'));

        $description = '';
        if (empty($topList)) {
            $description = "*No invites have been recorded yet. Share your invite link to get started!*";
        } else {
            $lines = [];
            foreach ($topList as $item) {
                $rank = $item['rank'];
                $medal = match ($rank) {
                    1 => '🥇',
                    2 => '🥈',
                    3 => '🥉',
                    default => "**#{$rank}**",
                };

                $name = $item['discord_username'] ?? "<@{$item['discord_user_id']}>";
                $panelTag = !empty($item['panel_username']) ? " (`{$item['panel_username']}`)" : '';
                $counted = $item['total_invites'];
                $paid = $item['valid_invites'];
                $credits = number_format($item['credits_earned']);

                $extra = [];
                if (($item['pending_invites'] ?? 0) > 0) {
                    $extra[] = "{$item['pending_invites']} pending";
                }
                if (($item['left_invites'] ?? 0) > 0) {
                    $extra[] = "{$item['left_invites']} left";
                }
                $suffix = $extra === [] ? '' : ' — ' . implode(', ', $extra);

                $line = "{$medal} **{$name}**{$panelTag} — **{$counted}** counted (`{$paid}` paid, **+{$credits}** credits){$suffix}";

                // On its own line rather than mixed into the counted figure: these joins
                // were never tracked, so nothing about them can be verified or paid.
                if (($item['legacy_invites'] ?? 0) > 0) {
                    $line .= "\n└ " . number_format($item['legacy_invites']) . ' legacy (pre-tracking, unverified)';
                }

                $lines[] = $line;
            }
            $description = implode("\n\n", $lines);
        }

        $maturityHours = $this->verificationService->getInviteMaturityHours();

        $fields = [
            [
                'name' => '🪙 Reward Rate',
                'value' => "**+{$creditsPerInvite}** credits per verified join",
                'inline' => true,
            ],
            [
                'name' => '🛡️ Anti-Alt Filter',
                'value' => "≥ **{$minAgeDays}** days minimum account age",
                'inline' => true,
            ],
        ];

        if ($maturityHours > 0) {
            $fields[] = [
                'name' => '⏳ Reward Hold',
                'value' => "**{$maturityHours}h** — leaving before that pays nothing, leaving after reverses it",
                'inline' => true,
            ];
        }

        $footer = "plugged.host • Top " . count($topList) . " Inviters • Members who left do not count";
        if ($legacyTotal > 0) {
            $footer = 'plugged.host • Legacy counts predate join tracking and pay nothing';
        }

        return [
            'title' => "🏆 Discord Invite Leaderboard",
            'description' => $description,
            'color' => 0xF59E0B, // Amber / Gold
            'fields' => $fields,
            'footer' => [
                'text' => $footer,
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Respond to a Discord Interaction.
     */
    public function respondToInteraction(string $interactionId, string $interactionToken, array $data): bool
    {
        $botToken = $this->verificationService->getBotToken();

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
                'Content-Type' => 'application/json',
                'User-Agent' => 'PelicanPanel-PluggedHostDiscord/1.2',
            ])->post(DiscordVerificationService::DISCORD_API_BASE . "/interactions/{$interactionId}/{$interactionToken}/callback", [
                'type' => 4, // CHANNEL_MESSAGE_WITH_SOURCE
                'data' => $data,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("Failed to respond to Discord interaction: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Whether the member who ran an interaction has Discord's Administrator permission in the
     * guild. /setboost pays credits, so it is checked here rather than trusting the command's
     * default_member_permissions, which a guild admin can widen under Integrations.
     *
     * `member.permissions` is the member's resolved permission bitfield as a decimal string
     * (owners have every bit). Only bit 3 (ADMINISTRATOR, 0x8) matters, and n mod 16 depends
     * only on the last four decimal digits (10000 is a multiple of 16), so the bit is read from
     * those instead of casting a number that can exceed PHP_INT_MAX. A DM interaction has no
     * member and is never an admin.
     *
     * @param  array<string, mixed>  $interaction
     */
    public static function invokerIsAdministrator(array $interaction): bool
    {
        $permissions = $interaction['member']['permissions'] ?? null;

        if (!is_string($permissions) || !ctype_digit($permissions)) {
            return false;
        }

        return ((int) substr($permissions, -4) & 0x8) === 0x8;
    }

    /**
     * The reply to a /setboost from someone who is not an administrator (only they see it).
     *
     * @return array<string, mixed>
     */
    public function setBoostDeniedResponse(): array
    {
        return [
            'content' => 'Only server administrators can use /setboost.',
            'flags' => 64,
        ];
    }

    /**
     * Handle /setboost command execution. Callers check invokerIsAdministrator() first.
     */
    public function handleSetBoostCommand(string $targetUserId, int $count, ?string $targetUsername = null): array
    {
        $count = max(0, min(2, $count));
        $link = \PluggedHost\Discord\Models\DiscordLink::where('discord_id', $targetUserId)->first();
        $user = $link?->user;

        $isBoosting = $count > 0;
        if ($link) {
            $link->update([
                'is_boosting' => $isBoosting,
                'boost_count' => $count,
                'boosted_since' => $isBoosting ? ($link->boosted_since ?? now()) : null,
            ]);
        }

        if ($count >= 2) {
            $this->verificationService->assignBoost2Role($targetUserId);
        } elseif (!$isBoosting) {
            $this->verificationService->removeBoost2Role($targetUserId);
        }

        $creditsAwarded = 0;
        if ($user) {
            $syncResult = $this->verificationService->syncBoosts($user, $link);
            $creditsAwarded = (int) ($syncResult['credits_earned'] ?? 0);
            // noprofit.space pays boost levels (boosts on any of its servers): ask it now.
            $creditsAwarded += (int) (app(NoprofitDiscordStatus::class)->forUser($user, check: true)['paid'] ?? 0);
        }

        $mention = "<@{$targetUserId}>";
        $username = $targetUsername ?? ($link?->discord_username ?? "User {$targetUserId}");

        $embed = [
            'title' => '🚀 Boost Count Updated',
            'color' => $count > 0 ? 0xF47FFF : 0x6B7280,
            'fields' => [
                [
                    'name' => 'Member',
                    'value' => "{$mention} (`{$username}`)",
                    'inline' => true,
                ],
                [
                    'name' => 'Boosts Set',
                    'value' => "**{$count} Boost" . ($count !== 1 ? 's' : '') . '**',
                    'inline' => true,
                ],
                [
                    'name' => 'Role Status',
                    'value' => $count >= 2 ? '✔ **2x Booster** role assigned' : ($count === 1 ? '1x Booster (Standard)' : 'Boost status removed'),
                    'inline' => true,
                ],
                [
                    'name' => 'Panel Account',
                    'value' => $user
                        ? "Linked to **{$user->username}** (+{$creditsAwarded} credits added)"
                        : '*No panel account linked yet (rewards will credit automatically when linked)*',
                    'inline' => false,
                ],
            ],
            'footer' => [
                'text' => 'plugged.host • Admin Action',
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return [
            'embeds' => [$embed],
        ];
    }
}
