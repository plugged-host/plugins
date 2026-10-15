<?php

namespace PluggedHost\Ai\Rux\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A standing order rux enforces by itself (RuleService).
 *
 * @property int $id
 * @property string $guild_id
 * @property string $description
 * @property list<string> $keywords
 * @property string $action warn, timeout, delete, kick or ban
 * @property ?int $duration_seconds
 * @property bool $delete_message
 * @property ?string $reason
 * @property ?list<string> $channel_ids
 * @property bool $active
 * @property string $created_by_discord_id
 * @property int $created_by_account_id
 * @property int $hits
 * @property int $actions
 * @property ?Carbon $last_action_at
 */
class RuxRule extends Model
{
    public const ACTIONS = ['warn', 'timeout', 'delete', 'kick', 'ban'];

    protected $table = 'rux_rules';

    protected $fillable = [
        'guild_id',
        'description',
        'exceptions',
        'keywords',
        'action',
        'duration_seconds',
        'delete_message',
        'reason',
        'channel_ids',
        'active',
        'created_by_discord_id',
        'created_by_account_id',
        'hits',
        'actions',
        'last_action_at',
    ];

    protected $casts = [
        'keywords' => 'array',
        'duration_seconds' => 'integer',
        'delete_message' => 'boolean',
        'channel_ids' => 'array',
        'active' => 'boolean',
        'created_by_account_id' => 'integer',
        'hits' => 'integer',
        'actions' => 'integer',
        'last_action_at' => 'datetime',
    ];

    /**
     * Whether a message's text contains one of the keywords: a phrase anywhere, a single word
     * only as a whole word. Case, accents and repeated spaces do not matter.
     */
    public function matches(string $normalizedText): bool
    {
        foreach ($this->keywords as $keyword) {
            $keyword = self::normalize((string) $keyword);

            if ($keyword === '') {
                continue;
            }

            if (str_contains($keyword, ' ') ? str_contains($normalizedText, $keyword) : preg_match('/(?<![\p{L}\p{N}])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}])/u', $normalizedText) === 1) {
                return true;
            }
        }

        return false;
    }

    public function appliesIn(string $channelId, ?string $parentId = null): bool
    {
        $channels = (array) ($this->channel_ids ?? []);

        return $channels === [] || in_array($channelId, $channels, true) || ($parentId !== null && in_array($parentId, $channels, true));
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return trim((string) preg_replace('/\s+/u', ' ', is_string($plain) && $plain !== '' ? $plain : $text));
    }
}
