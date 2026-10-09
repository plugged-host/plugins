<?php

use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use PluggedHost\Discord\Jobs\SyncDiscordMembershipJob;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Discord\Services\DiscordVerificationService;
use PluggedHost\Noprofit\Http\Controllers\NoprofitServiceController;
use PluggedHost\Noprofit\Support\NoprofitLink;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-core');
    loadPluginForTest('plugged-host-economy');
    loadPluginForTest('plugged-host-discord');
    Bus::fake([SyncDiscordMembershipJob::class]);
});

it('moves paid boost tiers and invite debt with the Discord account', function () {
    [$old] = generateTestAccount();
    [$new] = generateTestAccount();
    $new->forceFill([NoprofitLink::COLUMN => 4242])->save();

    DiscordLink::query()->create(['user_id' => $old->id, 'discord_id' => '700000000000000001', 'boosts_rewarded' => 2, 'invite_debt' => 150]);

    $request = Request::create('/api/noprofit/discord/link', 'POST', [
        'account_id' => 4242,
        'discord' => ['discord_id' => '700000000000000001', 'access_token' => 'token', 'username' => 'someone'],
    ]);

    app(NoprofitServiceController::class)->linkDiscord($request, app(DiscordVerificationService::class));

    $moved = DiscordLink::query()->where('user_id', $new->id)->firstOrFail();
    $left = DiscordLink::query()->where('user_id', $old->id)->firstOrFail();

    expect($moved->discord_id)->toBe('700000000000000001')
        ->and((int) $moved->boosts_rewarded)->toBe(2)
        ->and((int) $moved->invite_debt)->toBe(150)
        ->and((int) $left->boosts_rewarded)->toBe(0)
        ->and((int) $left->invite_debt)->toBe(0);
});
