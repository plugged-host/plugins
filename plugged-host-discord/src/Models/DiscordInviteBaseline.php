<?php

namespace PluggedHost\Discord\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One inviter's pre-tracking invite total, captured from Discord's `invite.uses`.
 *
 * This is the only place in the plugin where a count does not come from a tracked join,
 * and it exists because tracked joins can only ever start from the moment the gateway
 * daemon sees GUILD_MEMBER_ADD. Everything before that is visible to Discord as a per-code
 * use count and invisible to us as individual members.
 *
 * The number is display-only. It is never fed to `awardMaturedInvites()`, never reduced
 * when someone leaves (there is no member to notice leaving), and never refreshed except
 * by an explicit re-capture. Treat it as a footnote on the leaderboard, not as a balance.
 *
 * @property int $id
 * @property string $inviter_discord_id
 * @property ?string $inviter_username
 * @property int $legacy_uses
 * @property int $api_uses_at_capture
 * @property int $tracked_at_capture
 * @property int $invite_codes
 * @property ?Carbon $captured_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DiscordInviteBaseline extends Model
{
    protected $table = 'discord_invite_baselines';

    protected $fillable = [
        'inviter_discord_id',
        'inviter_username',
        'legacy_uses',
        'api_uses_at_capture',
        'tracked_at_capture',
        'invite_codes',
        'captured_at',
    ];

    protected $casts = [
        'legacy_uses' => 'integer',
        'api_uses_at_capture' => 'integer',
        'tracked_at_capture' => 'integer',
        'invite_codes' => 'integer',
        'captured_at' => 'datetime',
    ];

    /**
     * The panel-side link for this inviter, when they have one at all.
     */
    public function link(): ?DiscordLink
    {
        return DiscordLink::where('discord_id', $this->inviter_discord_id)->first();
    }
}
