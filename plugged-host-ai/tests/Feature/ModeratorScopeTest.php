<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Models\RuxCase;
use PluggedHost\Ai\Rux\Moderation\ModerationService;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\Moderation\BanMemberTool;
use PluggedHost\Ai\Rux\Tools\Moderation\ChannelSettingsTool;
use PluggedHost\Ai\Rux\Tools\Moderation\PurgeMessagesTool;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Discord\Contracts\DiscordAccounts;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

const RUX_GUILD = '100000000000000001';
const RUX_MOD = '100000000000000002';
const RUX_CHANNEL = '300000000000000001';
const RUX_STAFF = '400000000000000001';
const RUX_MEMBER = '500000000000000001';

beforeEach(function () {
    loadPluginForTest('plugged-host-ai');
    config()->set('plugged-host-ai.rux.guild_id', RUX_GUILD);
    config()->set('plugged-host-ai.rux.mod_log_channel_id', '');

    $this->discord = Mockery::mock(RuxDiscord::class);
    $this->discord->shouldReceive('channels')->andReturn([['id' => RUX_CHANNEL, 'type' => 0, 'name' => 'rules']]);
    app()->instance(RuxDiscord::class, $this->discord);

    $access = Mockery::mock(RuxAccess::class);
    $access->shouldReceive('isStaff')->andReturnUsing(fn (string $guild, string $id) => $guild === RUX_GUILD && $id === RUX_STAFF);
    // The moderator can see every channel of the server.
    $access->shouldReceive('canView')->andReturnUsing(fn (string $guild) => $guild === RUX_GUILD);
    app()->instance(RuxAccess::class, $access);

    $this->noprofitAdmins = [];
    $accounts = Mockery::mock(DiscordAccounts::class);
    $accounts->shouldReceive('account')->andReturnUsing(fn (string $id) => ['is_admin' => in_array($id, $this->noprofitAdmins, true)]);
    app()->instance(DiscordAccounts::class, $accounts);
});

function moderatorContext(): RuxContext
{
    return new RuxContext(RUX_GUILD, RUX_MOD, 'mod', null, RUX_CHANNEL, false, null, level: RuxAccess::MODERATOR);
}

it('does not let a moderator ban a noprofit.space admin who is not in the server', function () {
    $this->noprofitAdmins = [RUX_MEMBER];
    $this->discord->shouldReceive('member')->andReturnNull();
    $this->discord->shouldReceive('user')->andReturn(['id' => RUX_MEMBER, 'username' => 'admin']);

    $moderation = Mockery::mock(ModerationService::class);
    $moderation->shouldNotReceive('ban');
    app()->instance(ModerationService::class, $moderation);

    expect(fn () => app(BanMemberTool::class)->execute(moderatorContext(), ['member' => RUX_MEMBER]))
        ->toThrow(InvalidArgumentException::class);
});

it('does not let a moderator unlock a channel rux did not lock', function () {
    $moderation = Mockery::mock(ModerationService::class);
    $moderation->shouldNotReceive('lock');
    app()->instance(ModerationService::class, $moderation);

    expect(fn () => app(ChannelSettingsTool::class)->execute(moderatorContext(), ['channel' => RUX_CHANNEL, 'locked' => false]))
        ->toThrow(InvalidArgumentException::class);
});

it('lets a moderator unlock a channel rux locked', function () {
    RuxCase::query()->create(['guild_id' => RUX_GUILD, 'action' => 'lock', 'channel_id' => RUX_CHANNEL, 'moderator_discord_id' => RUX_MOD, 'automatic' => false]);

    $moderation = Mockery::mock(ModerationService::class);
    $moderation->shouldReceive('lock')->once()->andReturn(new RuxCase());
    app()->instance(ModerationService::class, $moderation);

    app(ChannelSettingsTool::class)->execute(moderatorContext(), ['channel' => RUX_CHANNEL, 'locked' => false]);
});

it('keeps staff messages when a moderator cleans up a whole channel', function () {
    $now = now()->toIso8601String();
    $this->discord->shouldReceive('messages')->andReturn([
        ['id' => '600000000000000003', 'timestamp' => $now, 'author' => ['id' => RUX_STAFF]],
        ['id' => '600000000000000002', 'timestamp' => $now, 'author' => ['id' => RUX_MEMBER]],
    ]);
    $this->discord->shouldReceive('deleteMessages')->once()->with(RUX_CHANNEL, ['600000000000000002'], Mockery::any());

    app(PurgeMessagesTool::class)->execute(moderatorContext(), ['channel' => RUX_CHANNEL, 'count' => 10]);
});
