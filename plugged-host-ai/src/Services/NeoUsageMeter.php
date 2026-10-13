<?php

namespace PluggedHost\Ai\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PluggedHost\Ai\Models\NeoUsageEvent;
use PluggedHost\Ai\Models\NeoUsageWindow;
use PluggedHost\Ai\Support\NeoSubject;
use Throwable;

/**
 * neo's two usage windows when this panel meters neo (a port of noprofit.space's
 * NeoUsageService, on neo_usage_windows / neo_usage_events). Usage is plain tokens, the same
 * for every model, except that a turn a premium slot answered counts premium_multiplier times.
 *
 * Windows are anchored: a window opens at the first charged call when none is active and resets
 * at start + length. The limit is checked before a call and charged after it, so a single call may
 * overshoot. A Discord account nobody linked has windows of its own, at
 * plugged-host-ai.local.limits.discord_unlinked_percent of the limits.
 */
final class NeoUsageMeter
{
    public const KINDS = ['short', 'long'];

    private static bool $ready = false;

    /**
     * Whether the tables exist (migration 016 ran).
     */
    public function ready(): bool
    {
        if (self::$ready) {
            return true;
        }

        try {
            return self::$ready = Schema::hasTable('neo_usage_windows') && Schema::hasTable('neo_usage_events');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{short: array<string, mixed>, long: array<string, mixed>}
     */
    public function snapshot(NeoSubject $subject, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $windows = $this->windows($subject)->get()->keyBy('kind');

        return [
            'short' => $this->describe('short', $windows->get('short'), $now, $subject),
            'long' => $this->describe('long', $windows->get('long'), $now, $subject),
        ];
    }

    /**
     * The window that currently blocks new calls, if any (the one that resets last wins).
     *
     * @param  array{short: array<string, mixed>, long: array<string, mixed>}  $snapshot
     */
    public function blockingWindow(array $snapshot): ?string
    {
        foreach (['long', 'short'] as $kind) {
            if ($snapshot[$kind]['used'] >= $snapshot[$kind]['limit']) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Charge a successful call to both windows and write its ledger row, atomically.
     *
     * @param  array<string, mixed>  $event
     */
    public function charge(NeoSubject $subject, int $tokens, array $event, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($subject, $tokens, $event, $now): void {
            foreach (self::KINDS as $kind) {
                $window = $this->windows($subject)
                    ->where('kind', $kind)
                    ->lockForUpdate()
                    ->first();

                if ($window === null || $window->resets_at->lte($now)) {
                    $window ??= new NeoUsageWindow($subject->user !== null
                        ? ['user_id' => $subject->user->id, 'kind' => $kind]
                        : ['discord_id' => $subject->discordId, 'kind' => $kind]);
                    $window->fill([
                        'started_at' => $now,
                        'resets_at' => $now->addSeconds($this->lengthSeconds($kind)),
                        'tokens_used' => $tokens,
                    ])->save();

                    continue;
                }

                $window->increment('tokens_used', $tokens);
            }

            NeoUsageEvent::query()->create(array_merge($event, [
                'user_id' => $subject->user?->id,
                'discord_id' => $subject->discordId,
                'charged_tokens' => $tokens,
                'status' => 'ok',
            ]));
        });
    }

    /**
     * Record a failed turn (or a settings test, status 'test'). Nothing is charged and no window
     * is opened.
     *
     * @param  array<string, mixed>  $event
     */
    public function recordFailure(?NeoSubject $subject, array $event): void
    {
        NeoUsageEvent::query()->create(array_merge($event, [
            'user_id' => $subject?->user?->id,
            'discord_id' => $subject?->discordId,
            'charged_tokens' => 0,
            'status' => $event['status'] ?? 'failed',
        ]));
    }

    public function limit(string $kind, ?NeoSubject $subject = null): int
    {
        $limit = max(0, (int) config("plugged-host-ai.local.limits.{$kind}.tokens", 0));

        if ($subject === null) {
            return $limit;
        }

        if ($subject->isUnlinkedDiscord()) {
            return intdiv($limit * $this->unlinkedDiscordPercent(), 100);
        }

        return intdiv($limit * $subject->usagePercent, 100);
    }

    /**
     * How many times its tokens a turn a premium slot answered counts (1 to 10).
     */
    public function premiumMultiplier(): int
    {
        return max(1, min(10, (int) config('plugged-host-ai.local.limits.premium_multiplier', 2)));
    }

    public function unlinkedDiscordPercent(): int
    {
        return max(0, min(100, (int) config('plugged-host-ai.local.limits.discord_unlinked_percent', 50)));
    }

    public function lengthSeconds(string $kind): int
    {
        return $kind === 'short'
            ? max(1, (int) config('plugged-host-ai.local.limits.short.hours', 5)) * 3600
            : max(1, (int) config('plugged-host-ai.local.limits.long.days', 7)) * 86400;
    }

    /**
     * @return Builder<NeoUsageWindow>
     */
    private function windows(NeoSubject $subject): Builder
    {
        return $subject->user !== null
            ? NeoUsageWindow::query()->where('user_id', $subject->user->id)
            : NeoUsageWindow::query()->whereNull('user_id')->where('discord_id', $subject->discordId);
    }

    /**
     * @return array{used: int, limit: int, percent: int, active: bool, started_at: ?string, resets_at: ?string, window_seconds: int}
     */
    private function describe(string $kind, ?NeoUsageWindow $window, CarbonImmutable $now, NeoSubject $subject): array
    {
        $limit = $this->limit($kind, $subject);
        $active = $window !== null && $window->resets_at->gt($now);
        $used = $active ? (int) $window->tokens_used : 0;

        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => $limit > 0 ? (int) min(100, floor($used * 100 / $limit)) : 100,
            'active' => $active,
            'started_at' => $active ? $window->started_at->toIso8601ZuluString() : null,
            'resets_at' => $active ? $window->resets_at->toIso8601ZuluString() : null,
            'window_seconds' => $this->lengthSeconds($kind),
        ];
    }
}
