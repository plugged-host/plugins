<?php

namespace PluggedHost\Minecraft\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a server has been through first-run setup, and what was chosen.
 *
 * @property int $id
 * @property int $server_id
 * @property ?\Illuminate\Support\Carbon $completed_at
 * @property ?\Illuminate\Support\Carbon $dismissed_at
 * @property ?array<string, mixed> $answers
 */
class ServerOnboarding extends Model
{
    /**
     * Marks a row as "setup is mandatory for this server", inside the answers JSON.
     *
     * A column of its own would read better, and was the first design. It is not worth an ALTER on
     * the live panel database: this table already has a nullable JSON column that is empty for
     * exactly the rows this flag applies to (markRequired() is the only thing that creates a row
     * before setup runs), the flag is read once per request and memoised, and everything that later
     * writes answers — ServerOnboardingPage::complete() and ApplyServerOnboardingJob — replaces the
     * whole array, so the flag cannot outlive the completion it describes.
     *
     * Underscored so it is obvious in a support query that this is not something a user answered.
     */
    public const REQUIRED_KEY = '_required';

    protected $table = 'server_onboarding';

    protected $fillable = [
        'server_id',
        'completed_at',
        'dismissed_at',
        'answers',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'answers' => 'array',
        ];
    }

    /**
     * Memo for pendingFor(), keyed by server id.
     *
     * @var array<int, bool>
     */
    protected static array $pending = [];

    /**
     * Memo for requiredFor(), keyed by server id.
     *
     * @var array<int, bool>
     */
    protected static array $required = [];

    /**
     * The row for a server, creating it on first sight.
     *
     * A server that predates this table has no row, and firstOrCreate is what makes "no row" mean
     * "not set up yet" instead of a null check at every call site.
     */
    public static function forServer(Server $server): self
    {
        return static::firstOrCreate(['server_id' => $server->id]);
    }

    /**
     * Whether the wizard should still be offered for a server.
     *
     * Deliberately *not* forServer(): this is what canAccess() asks, and canAccess() runs every
     * time the server panel builds its navigation. firstOrCreate there would mean a write on
     * every page view of every server. A missing row counts as pending, which is the same answer
     * the row would have given.
     *
     * Memoised per request because Filament asks each page whether it is visible more than once
     * per render (navigation, then the page itself).
     */
    public static function pendingFor(Server $server): bool
    {
        if (array_key_exists($server->id, static::$pending)) {
            return static::$pending[$server->id];
        }

        return static::$pending[$server->id] = !static::query()
            ->where('server_id', $server->id)
            ->where(fn ($query) => $query
                ->whereNotNull('completed_at')
                ->orWhereNotNull('dismissed_at'))
            ->exists();
    }

    /**
     * Mark a server as one that must go through setup before it can be used.
     *
     * Only ConfigureServerPage calls this, and that is the whole design: the wizard now owns the
     * software question, so a server created there is genuinely unconfigured until it has been
     * answered. A server that predates this flag, or one an admin created, does not carry it and
     * keeps the wizard as an offer — forcing those would ambush owners of servers that have been
     * running fine for months.
     *
     * firstOrNew rather than updateOrCreate: the flag is merged into whatever answers are already
     * there instead of replacing them, and a row that has already been completed or dismissed is
     * left alone. Nothing else creates a row this early — the server was created seconds ago — but
     * a retried submit must not resurrect a gate someone has already been through.
     */
    public static function markRequired(Server $server): void
    {
        $record = static::firstOrNew(['server_id' => $server->id]);

        if ($record->exists && !$record->isPending()) {
            return;
        }

        $record->answers = [
            ...($record->answers ?? []),
            static::REQUIRED_KEY => true,
        ];

        $record->save();

        static::forget($server);
    }

    /**
     * Whether setup is *mandatory* for a server and still unanswered.
     *
     * Distinct from pendingFor(), which is "is there anything left to offer" and counts a missing
     * row as pending. This is the narrower question RequireServerOnboarding asks before it takes a
     * page away from someone, so a missing row means no.
     *
     * The flag is read in PHP rather than with a JSON where clause: it is one row on a unique
     * index, the driver-specific JSON operators buy nothing at that size, and a plain select is one
     * less thing to be wrong about across MySQL, MariaDB and SQLite.
     */
    public static function requiredFor(Server $server): bool
    {
        if (array_key_exists($server->id, static::$required)) {
            return static::$required[$server->id];
        }

        $record = static::query()->where('server_id', $server->id)->first();

        return static::$required[$server->id] = $record !== null
            && $record->isPending()
            && ($record->answers[static::REQUIRED_KEY] ?? false) === true;
    }

    /**
     * Drop the memos, so a page that just finished setup stops offering itself in the same request
     * that redirects away from it.
     */
    public static function forget(Server $server): void
    {
        unset(static::$pending[$server->id], static::$required[$server->id]);
    }

    /**
     * Whether the wizard still has something to say about this server.
     *
     * Completed and dismissed are both terminal: one user walked through setup, the other said no.
     * Neither should be asked again.
     */
    public function isPending(): bool
    {
        return $this->completed_at === null && $this->dismissed_at === null;
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
