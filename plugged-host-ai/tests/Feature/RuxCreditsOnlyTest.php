<?php

use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Facades\File;
use PluggedHost\Ai\Rux\Agent\RuxPrompt;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Giveaways\GiveawayService;
use PluggedHost\Ai\Rux\Models\RuxCase;
use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\Credits\GiveCreditsTool;
use PluggedHost\Ai\Rux\Tools\Giveaways\CreateGiveawayTool;
use PluggedHost\Ai\Rux\Tools\Giveaways\EditGiveawayTool;
use PluggedHost\Ai\Rux\Tools\Moderation\ManageRoleTool;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxToolRegistry;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;

/*
 * Credits are the only thing rux gives anyone, on every server: give_credits (the main admin
 * only) and credit giveaways. No tool hands out anything else, a giveaway cannot be titled as
 * another prize, and roles that come with rewards (booster roles) are not given.
 */

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

defined('CO_A') || define('CO_A', '120000000000000001');
defined('CO_B') || define('CO_B', '120000000000000002');
defined('CO_CH_B') || define('CO_CH_B', '320000000000000002');
defined('CO_MAIN') || define('CO_MAIN', '520000000000000001');
defined('CO_OTHER_ADMIN') || define('CO_OTHER_ADMIN', '520000000000000002');
defined('CO_MEMBER') || define('CO_MEMBER', '520000000000000003');

beforeEach(function () {
    loadPluginForTest('plugged-host-ai');

    config()->set('plugged-host-ai.rux.guild_id', CO_A);
    config()->set('plugged-host-ai.rux.mod_log_channel_id', '');
    config()->set('plugged-host-ai.rux.servers', [2 => ['id' => CO_B, 'name' => 'example.cloud']]);
    config()->set('plugged-host-ai.rux.credits.granters', [CO_MAIN]);
    config()->set('plugged-host-ai.rux.credits.max_per_grant', 10000);
    config()->set('plugged-host-discord.boost_2_role_id', '');

    $portal = Mockery::mock(AccountPortal::class);
    $portal->shouldReceive('name')->andReturn('accounts.example');
    $portal->shouldReceive('external')->andReturnTrue();
    $portal->shouldReceive('discordLinkUrl')->andReturn('https://accounts.example/discord');
    app()->instance(AccountPortal::class, $portal);

    $accounts = Mockery::mock(DiscordAccounts::class);
    $accounts->shouldReceive('account')->andReturn(null);
    app()->instance(DiscordAccounts::class, $accounts);

    $this->payouts = Mockery::mock(DiscordCreditPayouts::class);
    app()->instance(DiscordCreditPayouts::class, $this->payouts);

    $this->discord = Mockery::mock(RuxDiscord::class);
    // A member of server B only (a switched.cloud-style member): not on A.
    $this->discord->shouldReceive('member')->andReturnUsing(fn (string $guild, string $id) => $guild === CO_B && $id === CO_MEMBER
        ? ['user' => ['id' => CO_MEMBER, 'username' => 'member'], 'roles' => []]
        : null);
    $this->discord->shouldReceive('searchMembers')->andReturn([]);
    $this->discord->shouldReceive('roles')->andReturn([
        ['id' => '720000000000000001', 'name' => 'Regulars', 'managed' => false, 'permissions' => '0'],
        ['id' => '720000000000000002', 'name' => '2x Booster', 'managed' => false, 'permissions' => '0'],
        ['id' => '720000000000000003', 'name' => 'Server Booster', 'managed' => true, 'tags' => ['premium_subscriber' => null], 'permissions' => '0'],
    ]);
    $this->discord->shouldReceive('sendDm')->andReturnTrue();
    $this->discord->shouldReceive('createMessage')->andReturn(['id' => '620000000000000001']);
    $this->discord->shouldReceive('editMessage')->andReturn([]);
    $this->discord->shouldReceive('json')->andReturn(['name' => 'Server', 'owner_id' => '1']);
    app()->instance(RuxDiscord::class, $this->discord);
});

function coContext(string $guild, string $actor, ?int $account = 3): RuxContext
{
    return new RuxContext($guild, $actor, 'admin', $account, CO_CH_B, false, '620000000000000009', order: new RuxMessage());
}

it('has no tool that hands out anything but credits', function () {
    // A new tool must be checked against the credits-only rule before it is added here.
    expect(array_keys(app(RuxToolRegistry::class)->all()))->toEqualCanonicalizing([
        'create_giveaway', 'list_giveaways', 'edit_giveaway', 'end_giveaway', 'cancel_giveaway', 'reroll_giveaway',
        'give_credits',
        'lookup_member', 'warn_member', 'timeout_member', 'kick_member', 'ban_member', 'unban_user', 'list_cases', 'list_bans',
        'set_nickname', 'read_messages', 'delete_messages', 'purge_messages', 'channel_settings', 'manage_role', 'role_info',
        'send_message', 'edit_message', 'send_dm', 'server_overview', 'clear_warnings',
        'add_rule', 'list_rules', 'edit_rule', 'remove_rule', 'watch_channel', 'set_moderator_role',
        'switch_server',
    ]);

    // Nothing in rux's tools reaches for tokens, plans or resources.
    $sources = collect(File::allFiles(dirname(__DIR__, 2) . '/src/Rux'))->map(fn ($file) => $file->getContents())->implode("\n");

    expect($sources)->not->toContain('TokenService')
        ->not->toContain('ComputePlans')
        ->not->toContain('UserTokens')
        ->not->toContain('plus_since')
        ->not->toContain('addTokens');
});

it('refuses a giveaway titled as a prize other than credits', function (string $title) {
    expect(fn () => GiveawayService::creditsOnlyTitle($title))->toThrow(InvalidArgumentException::class, 'always credits');
})->with(['1 month of Plus', 'PlusPlus for 3 winners', '500 tokens', '1000 Cloud Tokens', '8 GB RAM', '16gb', 'Free Nitro', 'VIP role', 'extra CPU cores']);

it('keeps a giveaway title that names the occasion', function (string $title) {
    expect(GiveawayService::creditsOnlyTitle($title))->toBe($title);
})->with(['Halloween giveaway', '1,000 credits', 'example.cloud launch party', 'Thanks for 1k members', 'Weekly credits drop']);

it('does not create or change a giveaway into another prize', function () {
    expect(fn () => app(CreateGiveawayTool::class)->execute(coContext(CO_B, CO_MAIN), ['credits' => 500, 'ends_at' => '2d', 'channel' => CO_CH_B, 'title' => 'Win 1 month of Plus']))
        ->toThrow(InvalidArgumentException::class, 'always credits')
        ->and(RuxGiveaway::query()->count())->toBe(0);

    $giveaway = RuxGiveaway::query()->create([
        'guild_id' => CO_B, 'channel_id' => CO_CH_B, 'prize' => '500 credits', 'credits' => 500, 'winners_count' => 1,
        'ends_at' => now()->addDay(), 'status' => RuxGiveaway::ACTIVE, 'created_by_discord_id' => CO_MAIN, 'created_by_account_id' => 3,
    ]);

    expect(fn () => app(EditGiveawayTool::class)->execute(coContext(CO_B, CO_MAIN), ['giveaway_id' => $giveaway->id, 'title' => '500 Cloud Tokens']))
        ->toThrow(InvalidArgumentException::class, 'always credits')
        ->and($giveaway->fresh()->prize)->toBe('500 credits');
});

it('does not hand out roles that come with rewards', function (string $role) {
    $this->discord->shouldNotReceive('addRole');

    expect(fn () => app(ManageRoleTool::class)->execute(coContext(CO_B, CO_MAIN), ['member' => CO_MEMBER, 'role' => $role, 'add' => true]))
        ->toThrow(InvalidArgumentException::class);
})->with(['2x Booster', 'Server Booster']);

it('still gives ordinary roles and takes reward roles away', function () {
    $this->discord->shouldReceive('addRole')->once();
    $this->discord->shouldReceive('removeRole')->once();

    app(ManageRoleTool::class)->execute(coContext(CO_B, CO_MAIN), ['member' => CO_MEMBER, 'role' => 'Regulars', 'add' => true]);
    app(ManageRoleTool::class)->execute(coContext(CO_B, CO_MAIN), ['member' => CO_MEMBER, 'role' => '2x Booster', 'add' => false]);

    expect(RuxAccess::isRewardRole(['id' => '1', 'name' => 'Regulars']))->toBeFalse();
});

it('treats the Discord plugin\'s 2x boost role as a reward role whatever its name', function () {
    config()->set('plugged-host-discord.boost_2_role_id', '720000000000000001');

    expect(RuxAccess::isRewardRole(['id' => '720000000000000001', 'name' => 'Regulars']))->toBeTrue();
});

it('gives credits on the main admin\'s order on any server, to a member of that server', function () {
    $this->payouts->shouldReceive('grantCredits')->once()
        ->withArgs(fn (string $key, string $discordId, int $amount, ?string $reason, int $by) => str_starts_with($key, 'rux:grant:') && $discordId === CO_MEMBER && $amount === 250 && $by === 3)
        ->andReturn(['account_id' => 77, 'name' => 'member', 'amount' => 250, 'balance_after' => 250, 'created' => true]);

    $result = app(GiveCreditsTool::class)->execute(coContext(CO_B, CO_MAIN), ['member' => '<@' . CO_MEMBER . '>', 'credits' => 250, 'reason' => 'event winner']);

    expect($result->ok)->toBeTrue()
        ->and(RuxCase::query()->where('action', 'credits')->value('guild_id'))->toBe(CO_B);
});

it('does not give credits for anyone but the main admin', function () {
    $this->payouts->shouldNotReceive('grantCredits');

    expect(fn () => app(GiveCreditsTool::class)->execute(coContext(CO_B, CO_OTHER_ADMIN), ['member' => CO_MEMBER, 'credits' => 250]))
        ->toThrow(InvalidArgumentException::class, 'main admin');
});

it('says clearly when the member has no account to give credits to', function () {
    $this->payouts->shouldReceive('grantCredits')->once()->andThrow(new InvalidArgumentException('<@' . CO_MEMBER . '> has not linked Discord to an account, so there is no account to give credits to.'));

    expect(fn () => app(GiveCreditsTool::class)->execute(coContext(CO_B, CO_MAIN), ['member' => CO_MEMBER, 'credits' => 250]))
        ->toThrow(InvalidArgumentException::class, 'no account to give credits to')
        ->and(RuxCase::query()->count())->toBe(0);
});

it('tells the model credits are all rux gives', function () {
    $prompt = app(RuxPrompt::class)->build(coContext(CO_B, CO_MAIN), 'In a channel.');

    expect($prompt)->toContain('are the only thing rux ever gives anyone, on every server')
        ->toContain('no Plus or other plans');
});
