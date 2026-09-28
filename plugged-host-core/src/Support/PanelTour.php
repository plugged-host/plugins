<?php

namespace PluggedHost\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One place that knows about users.panel_tour.
 *
 * The column is added by 009_add_panel_tour_to_users and holds the welcome tour's state as
 * JSON: which tour version was seen, when it was acknowledged, finished or skipped, and
 * which step the person stopped on. Three callers share it -- RequirePanelTour reads it,
 * WelcomeTourPage writes it, and the user-menu entry restarts it.
 *
 * The one thing that makes this a class rather than a couple of inline attribute reads:
 *
 *   An absent column reads as "never acknowledged", which means the gate *shuts* --
 *   and since the write would also fail, every request from every user would redirect to a
 *   page whose only button cannot record anything. So mustAcknowledge() consults the
 *   Schema::hasColumn() memo on the read path and answers false when the column is missing.
 *
 * Belt and braces on top of that: acknowledge() also sets a session flag which the gate
 * honours, so even a write that fails for some reason we have not thought of (a full disk,
 * a lock timeout) cannot trap the person who clicked the button.
 *
 * The column is JSON but User is upstream, so it carries no $casts entry and Eloquent hands
 * back the raw string. Decoding and encoding therefore happen here, and state() always
 * returns the full shape with defaults filled in so callers never have to null-check keys.
 */
final class PanelTour
{
    public const COLUMN = 'panel_tour';

    /**
     * Set by acknowledge(). The gate treats it as equivalent to a recorded acknowledgement,
     * so a failed write degrades to "the tour is done for this session" instead of a loop.
     */
    private const SESSION_KEY = 'plugged-host.panel-tour.acknowledged';

    /**
     * Memo for the Schema::hasColumn() probe. Per-process, not per-request: the answer
     * cannot change without a deploy, and this is consulted on every authenticated request.
     */
    private static ?bool $available = null;

    public static function enabled(): bool
    {
        return (bool) config('plugged-host-core.tour.enabled', true);
    }

    /**
     * Which tour this is. Bumping it in config re-prompts everyone -- deliberately, the same
     * way a legal document's version does for a changed document -- because a rewritten tour that
     * only new accounts ever see is a tour nobody reads.
     */
    public static function version(): string
    {
        return (string) config('plugged-host-core.tour.version', '1');
    }

    /**
     * The stored state for the *current* tour version, with every key present.
     *
     * State written by an older version is reported as empty rather than merged: "you saw
     * tour 1" is not an answer to "have you seen tour 2".
     *
     * @return array{version: string, acknowledged_at: ?string, completed_at: ?string, skipped_at: ?string, step: int}
     */
    public static function state(?User $user): array
    {
        $empty = [
            'version' => self::version(),
            'acknowledged_at' => null,
            'completed_at' => null,
            'skipped_at' => null,
            'step' => 1,
        ];

        if ($user === null) {
            return $empty;
        }

        $raw = $user->getAttribute(self::COLUMN);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!is_array($raw) || ($raw['version'] ?? null) !== self::version()) {
            return $empty;
        }

        return [
            'version' => self::version(),
            'acknowledged_at' => is_string($raw['acknowledged_at'] ?? null) ? $raw['acknowledged_at'] : null,
            'completed_at' => is_string($raw['completed_at'] ?? null) ? $raw['completed_at'] : null,
            'skipped_at' => is_string($raw['skipped_at'] ?? null) ? $raw['skipped_at'] : null,
            'step' => max(1, (int) ($raw['step'] ?? 1)),
        ];
    }

    /**
     * Should this request be sent to the tour?
     *
     * Zero queries. Zero exceptions. False whenever we could not honour a yes -- see the
     * class docblock for why that ordering is load-bearing rather than defensive.
     */
    public static function mustAcknowledge(?User $user): bool
    {
        if (!$user instanceof User || !self::enabled()) {
            return false;
        }

        if (!self::available()) {
            return false;
        }

        if (session()->get(self::SESSION_KEY) === self::version()) {
            return false;
        }

        return self::state($user)['acknowledged_at'] === null;
    }

    /**
     * Has this account either finished the tour or skipped the rest of it?
     *
     * Distinct from "acknowledged": someone who read step 1 and closed the tab is past the
     * gate but has not finished, and reopening the page should resume rather than bounce.
     */
    public static function isFinished(?User $user): bool
    {
        $state = self::state($user);

        return $state['completed_at'] !== null || $state['skipped_at'] !== null;
    }

    /**
     * Step 1 has been read. This is the write the gate depends on, so it also sets the
     * session flag -- see the class docblock.
     */
    public static function acknowledge(User $user, int $step = 2): void
    {
        session()->put(self::SESSION_KEY, self::version());

        self::merge($user, [
            'acknowledged_at' => now()->toDateTimeString(),
            'step' => $step,
        ]);
    }

    /**
     * Remember where someone got to, so closing the tab and coming back resumes.
     */
    public static function advance(User $user, int $step): void
    {
        self::merge($user, ['step' => $step]);
    }

    public static function skip(User $user): void
    {
        session()->put(self::SESSION_KEY, self::version());

        self::merge($user, [
            'acknowledged_at' => self::state($user)['acknowledged_at'] ?? now()->toDateTimeString(),
            'skipped_at' => now()->toDateTimeString(),
        ]);
    }

    public static function complete(User $user): void
    {
        session()->put(self::SESSION_KEY, self::version());

        self::merge($user, [
            'acknowledged_at' => self::state($user)['acknowledged_at'] ?? now()->toDateTimeString(),
            'completed_at' => now()->toDateTimeString(),
            'step' => 1,
        ]);
    }

    /**
     * Show it again from the top, without re-arming the gate: acknowledged_at stays, so
     * abandoning a replay halfway cannot start redirecting the person again.
     */
    public static function restart(User $user): void
    {
        self::merge($user, [
            'completed_at' => null,
            'skipped_at' => null,
            'step' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function merge(User $user, array $changes): void
    {
        if (!self::available()) {
            return;
        }

        $state = [...self::state($user), ...$changes, 'version' => self::version()];

        try {
            // forceFill + saveQuietly: the column is not in
            // User::$fillable (it cannot be -- User is upstream), and recording tour progress
            // has no business firing the model events the panel's own observers listen to.
            $user->forceFill([self::COLUMN => json_encode($state)])->saveQuietly();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        try {
            return self::$available = Schema::hasColumn('users', self::COLUMN);
        } catch (Throwable) {
            return self::$available = false;
        }
    }
}
