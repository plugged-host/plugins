<?php

namespace PluggedHost\Discord\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property ?string $discord_id
 * @property ?string $discord_username
 * @property ?string $discord_avatar
 * @property ?string $access_token
 * @property ?string $refresh_token
 * @property ?Carbon $token_expires_at
 * @property bool $is_in_guild
 * @property int $invite_count
 * @property int $invites_rewarded
 * @property int $invite_debt
 * @property int $invites_left
 * @property int $boost_count
 * @property int $boosts_rewarded
 * @property bool $is_boosting
 * @property ?Carbon $boosted_since
 * @property ?array $guild_roles
 * @property ?Carbon $last_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class DiscordLink extends Model
{
    protected $table = 'discord_links';

    protected $fillable = [
        'user_id',
        'discord_id',
        'discord_username',
        'discord_avatar',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_in_guild',
        'invite_count',
        'invites_rewarded',
        'invite_debt',
        'invites_left',
        'boost_count',
        'boosts_rewarded',
        'is_boosting',
        'boosted_since',
        'guild_roles',
        'last_verified_at',
    ];

    protected $casts = [
        'is_in_guild' => 'boolean',
        'invite_count' => 'integer',
        'invites_rewarded' => 'integer',
        'invite_debt' => 'integer',
        'invites_left' => 'integer',
        'boost_count' => 'integer',
        'boosts_rewarded' => 'integer',
        'is_boosting' => 'boolean',
        'boosted_since' => 'datetime',
        'guild_roles' => 'array',
        'token_expires_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLinked(): bool
    {
        return !empty($this->discord_id);
    }

    public function isVerified(): bool
    {
        return $this->isLinked() && $this->is_in_guild;
    }

    public function getAvatarUrl(): string
    {
        if ($this->discord_avatar && str_starts_with($this->discord_avatar, 'http')) {
            return $this->discord_avatar;
        }

        if ($this->discord_id && $this->discord_avatar) {
            $format = str_starts_with($this->discord_avatar, 'a_') ? 'gif' : 'png';

            return "https://cdn.discordapp.com/avatars/{$this->discord_id}/{$this->discord_avatar}.{$format}?size=128";
        }

        // Default Discord embed avatar based on discord_id modulo or fallback
        $defaultIndex = $this->discord_id ? (int) (bcmod($this->discord_id, '5') ?: 0) : 0;

        return "https://cdn.discordapp.com/embed/avatars/{$defaultIndex}.png";
    }
}
