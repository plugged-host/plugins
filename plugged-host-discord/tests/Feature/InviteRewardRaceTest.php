<?php

use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PluggedHost\Discord\Models\DiscordInvitedMember;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Economy\Models\UserCredits;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-economy');
    loadPluginForTest('plugged-host-discord');
    config()->set('plugged-host-discord.credits_per_invite', 50);

    // UserCredits memoises whether its table exists; it may have been asked before the
    // economy migrations ran in this process.
    (function () {
        static::$tableExists = null;
        static::$userMemo = [];
    })->bindTo(null, UserCredits::class)();
});

function maturedInvites(string $inviter, int $count): void
{
    foreach (range(1, $count) as $i) {
        DiscordInvitedMember::query()->create([
            'inviter_discord_id' => $inviter,
            'joined_discord_id' => (string) (900000000000000000 + $i),
            'joined_at' => now()->subDays(10),
            'is_eligible' => true,
            'is_rewarded' => false,
            'has_left' => false,
            'matured_at' => now()->subDay(),
        ]);
    }
}

it('pays each matured invite once', function () {
    [$user] = generateTestAccount();
    DiscordLink::query()->create(['user_id' => $user->id, 'discord_id' => '800000000000000001']);
    maturedInvites('800000000000000001', 2);
    $before = (int) UserCredits::forUser($user)->credits;

    app(DiscordVerificationService::class)->awardMaturedInvites('800000000000000001');
    app(DiscordVerificationService::class)->awardMaturedInvites('800000000000000001');

    expect((int) UserCredits::forUser($user)->fresh()->credits)->toBe($before + 100);
});

it('does not pay invites a parallel check paid between reading and paying them', function () {
    [$user] = generateTestAccount();
    DiscordLink::query()->create(['user_id' => $user->id, 'discord_id' => '800000000000000002']);
    maturedInvites('800000000000000002', 2);
    $before = (int) UserCredits::forUser($user)->credits;

    // Right after the service reads the matured rows, another request pays them (the race).
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced) {
        if (!$raced && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'discord_invited_members')) {
            $raced = true;
            DiscordInvitedMember::query()->update(['is_rewarded' => true, 'rewarded_at' => now()]);
        }
    });

    app(DiscordVerificationService::class)->awardMaturedInvites('800000000000000002');

    expect($raced)->toBeTrue()
        ->and((int) UserCredits::forUser($user)->fresh()->credits)->toBe($before);
});
