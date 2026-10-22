<?php

use App\Tests\Integration\IntegrationTestCase;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Cache;
use PluggedHost\Ai\Console\Commands\RuxGatewayCommand;
use PluggedHost\Ai\Rux\Agent\Ai\RuxAi;
use PluggedHost\Ai\Rux\Agent\RuxAgent;
use PluggedHost\Ai\Rux\Agent\RuxInbox;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Giveaways\GiveawayService;
use PluggedHost\Ai\Rux\Models\RuxCase;
use PluggedHost\Ai\Rux\Models\RuxGiveaway;
use PluggedHost\Ai\Rux\Models\RuxMessage;
use PluggedHost\Ai\Rux\Models\RuxModeratorRole;
use PluggedHost\Ai\Rux\Models\RuxRule;
use PluggedHost\Ai\Rux\Models\RuxWatch;
use PluggedHost\Ai\Rux\Models\RuxWatchItem;
use PluggedHost\Ai\Rux\Moderation\ModerationService;
use PluggedHost\Ai\Rux\Rules\RuleService;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxGuild;
use PluggedHost\Ai\Rux\Support\RuxSettings;
use PluggedHost\Ai\Rux\Support\RuxSupervisor;
use PluggedHost\Ai\Rux\Tools\Giveaways\EndGiveawayTool;
use PluggedHost\Ai\Rux\Tools\Giveaways\ListGiveawaysTool;
use PluggedHost\Ai\Rux\Tools\Moderation\ListCasesTool;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxToolRegistry;
use PluggedHost\Ai\Rux\Watch\SpamGuard;
use PluggedHost\Ai\Rux\Watch\WatchService;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use PluggedHost\Discord\Contracts\DiscordCreditPayouts;

/*
 * rux on several Discord servers: every event is handled on its own server, and rules, watched
 * channels, moderator roles, warnings, cases and giveaways never reach across to another one.
 * Server A is the primary one (RUX_GUILD_ID), B a second one (slot 2), C a server rux is in but
 * does not work on.
 */

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

defined('MS_A') || define('MS_A', '110000000000000001');
defined('MS_B') || define('MS_B', '110000000000000002');
defined('MS_C') || define('MS_C', '110000000000000003');
defined('MS_CH_A') || define('MS_CH_A', '310000000000000001');
defined('MS_CH_B') || define('MS_CH_B', '310000000000000002');
defined('MS_LOG_A') || define('MS_LOG_A', '310000000000000091');
defined('MS_LOG_B') || define('MS_LOG_B', '310000000000000092');
defined('MS_BOT') || define('MS_BOT', '910000000000000009');
defined('MS_ADMIN') || define('MS_ADMIN', '510000000000000001');
defined('MS_MOD') || define('MS_MOD', '510000000000000002');
defined('MS_MEMBER') || define('MS_MEMBER', '510000000000000003');
defined('MS_ROLE_A') || define('MS_ROLE_A', '710000000000000001');

beforeEach(function () {
    loadPluginForTest('plugged-host-ai');

    config()->set('plugged-host-ai.rux.guild_id', MS_A);
    config()->set('plugged-host-ai.rux.mod_log_channel_id', MS_LOG_A);
    config()->set('plugged-host-ai.rux.trusted_domains', ['example.host']);
    config()->set('plugged-host-ai.rux.servers', [2 => [
        'id' => MS_B,
        'name' => 'example.cloud',
        'about' => 'a free VPS host',
        'giveaway_channel_id' => '',
        'mod_log_channel_id' => MS_LOG_B,
        'trusted_domains' => ['example.cloud'],
    ]]);
    config()->set('plugged-host-ai.rux.warnings', ['timeout_at' => 3, 'timeout' => '24h', 'ban_at' => 5, 'ban' => '24h']);
    config()->set('plugged-host-ai.rux.spam.enabled', true);

    $portal = Mockery::mock(AccountPortal::class);
    $portal->shouldReceive('name')->andReturn('accounts.example');
    $portal->shouldReceive('external')->andReturnTrue();
    $portal->shouldReceive('discordLinkUrl')->andReturn('https://accounts.example/discord');
    app()->instance(AccountPortal::class, $portal);

    $this->admins = [MS_ADMIN];
    $accounts = Mockery::mock(DiscordAccounts::class);
    $accounts->shouldReceive('configured')->andReturnTrue();
    $accounts->shouldReceive('account')->andReturnUsing(fn (string $id) => in_array($id, $this->admins, true)
        ? ['account_id' => 3, 'name' => 'admin', 'is_admin' => true, 'user_id' => null]
        : ['account_id' => 40, 'name' => 'someone', 'is_admin' => false, 'user_id' => null]);
    app()->instance(DiscordAccounts::class, $accounts);
    app()->instance(DiscordCreditPayouts::class, Mockery::mock(DiscordCreditPayouts::class));

    // Discord: member roles per server, and every post recorded.
    $this->roles = [MS_A => [MS_MOD => [MS_ROLE_A]], MS_B => [MS_MOD => [], MS_MEMBER => []]];
    $this->posted = [];
    $this->discord = Mockery::mock(RuxDiscord::class);
    $this->discord->shouldReceive('member')->andReturnUsing(fn (string $guild, string $id) => isset($this->roles[$guild][$id])
        ? ['user' => ['id' => $id, 'username' => "u{$id}"], 'roles' => $this->roles[$guild][$id]]
        : null);
    $this->discord->shouldReceive('createMessage')->andReturnUsing(function (string $channel, array $payload) {
        $this->posted[] = ['channel' => $channel, 'payload' => $payload];

        return ['id' => (string) random_int(10 ** 17, 10 ** 18 - 1)];
    });
    $this->discord->shouldReceive('typing');
    $this->discord->shouldReceive('sendDm')->andReturnTrue();
    $this->discord->shouldReceive('json')->andReturnUsing(fn (string $method, string $path) => ['id' => 'x', 'name' => "Discord name of {$path}", 'owner_id' => '1']);
    $this->discord->shouldReceive('roles')->andReturn([]);
    app()->instance(RuxDiscord::class, $this->discord);

    Cache::flush();
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function msMessage(?string $guild, string $channel, string $author, string $content, array $extra = []): array
{
    return $extra + array_filter([
        'id' => (string) random_int(10 ** 17, 10 ** 18 - 1),
        'type' => 0,
        'channel_id' => $channel,
        'guild_id' => $guild,
        'author' => ['id' => $author, 'username' => "u{$author}"],
        'member' => $guild !== null ? ['roles' => []] : null,
        'content' => $content,
        'mentions' => [],
    ], static fn ($value): bool => $value !== null);
}

function msContext(string $guild, string $level = RuxAccess::ADMIN, string $actor = MS_ADMIN): RuxContext
{
    return new RuxContext($guild, $actor, 'someone', 3, $guild === MS_A ? MS_CH_A : MS_CH_B, false, null, level: $level);
}

/**
 * The AI answers these turns in order; what it was sent each time (the system prompt first, then
 * the other messages' text) is kept in $prompts.
 *
 * @param  list<array<string, mixed>>  $turns
 * @param  list<string>  $prompts
 */
function msAi(array $turns, array &$prompts = []): void
{
    $ai = Mockery::mock(RuxAi::class);
    $ai->shouldReceive('configured')->andReturnTrue();
    $ai->shouldReceive('providers')->andReturn([]);
    $ai->shouldReceive('turn')->andReturnUsing(function (array $messages) use (&$turns, &$prompts) {
        $prompts[] = implode("\n", array_map(static fn (array $m): string => is_string($m['content'] ?? null) ? $m['content'] : '', $messages));

        return array_shift($turns) ?? ['message' => ['content' => 'done']];
    });
    app()->instance(RuxAi::class, $ai);
}

/**
 * @return array<string, mixed>
 */
function msToolCall(string $name, array $arguments = []): array
{
    return ['message' => ['content' => null, 'tool_calls' => [['id' => 'c' . random_int(1, 99999), 'function' => ['name' => $name, 'arguments' => json_encode((object) $arguments)]]]]];
}

function msRule(string $guild, string $keyword): RuxRule
{
    return RuxRule::query()->create([
        'guild_id' => $guild, 'description' => "asks about {$keyword}", 'keywords' => [$keyword], 'action' => 'warn',
        'delete_message' => false, 'active' => true, 'created_by_discord_id' => MS_ADMIN, 'created_by_account_id' => 3,
    ]);
}

it('lists the servers from the settings, the primary one first, each once', function () {
    config()->set('plugged-host-ai.rux.servers', [
        2 => ['id' => MS_B, 'name' => 'example.cloud', 'trusted_domains' => ['example.cloud']],
        3 => ['id' => MS_A],
        4 => ['id' => 'not-a-server'],
        5 => ['id' => MS_C, 'about' => "a forum\nwith two lines"],
    ]);

    $guilds = RuxConfig::guilds();

    expect(array_map(fn (RuxGuild $g) => $g->id, $guilds))->toBe([MS_A, MS_B, MS_C])
        ->and($guilds[0]->primary)->toBeTrue()
        ->and($guilds[0]->hostName())->toBe(config('app.name'))
        ->and($guilds[0]->modLogChannelId)->toBe(MS_LOG_A)
        ->and($guilds[0]->locations)->toBeTrue()
        ->and($guilds[1]->hostName())->toBe('example.cloud')
        ->and($guilds[1]->trustedDomains)->toBe(['example.host', 'example.cloud'])
        ->and($guilds[1]->locations)->toBeFalse()
        ->and($guilds[2]->about())->toBe('a forum with two lines')
        ->and(RuxConfig::serves(MS_C))->toBeTrue()
        ->and(RuxConfig::serves(null))->toBeFalse()
        ->and(RuxConfig::guildFor(null)?->id)->toBe(MS_A);
});

it('serves nothing without a primary server', function () {
    config()->set('plugged-host-ai.rux.guild_id', '');

    expect(RuxConfig::guilds())->toBe([])->and(RuxConfig::serves(MS_B))->toBeFalse();
});

it('takes orders on every server it works on, and only there', function () {
    $inbox = app(RuxInbox::class);
    $mention = ['mentions' => [['id' => MS_BOT]]];

    $a = $inbox->accept(msMessage(MS_A, MS_CH_A, MS_MEMBER, '<@' . MS_BOT . '> hi', $mention), MS_BOT);
    $b = $inbox->accept(msMessage(MS_B, MS_CH_B, MS_ADMIN, '<@' . MS_BOT . '> hi', $mention), MS_BOT);
    $c = $inbox->accept(msMessage(MS_C, '310000000000000003', MS_ADMIN, '<@' . MS_BOT . '> hi', $mention), MS_BOT);

    expect(RuxMessage::query()->find($a)?->guild_id)->toBe(MS_A)
        ->and(RuxMessage::query()->find($b)?->guild_id)->toBe(MS_B)
        ->and($c)->toBeNull();
});

it('carries out an order from server B on server B', function () {
    msRule(MS_A, 'locations');
    msRule(MS_B, 'regions');
    $prompts = [];
    msAi([msToolCall('list_rules'), ['message' => ['content' => 'done']]], $prompts);

    $order = RuxMessage::query()->find(app(RuxInbox::class)->accept(msMessage(MS_B, MS_CH_B, MS_ADMIN, 'rux, our rules?'), MS_BOT));
    app(RuxAgent::class)->handle($order);

    $listed = (string) RuxMessage::query()->where('turn_id', $order->id)->where('tool_name', 'list_rules')->value('content');

    expect($order->fresh()->meta['server'])->toBe(MS_B)
        ->and($listed)->toContain('asks about regions')->not->toContain('asks about locations')
        ->and($prompts[0])->toContain('example.cloud Discord server')->toContain('example.cloud is a free VPS host')
        ->and($prompts[0])->toContain('everything you do is on that server only');
});

it('gives a moderator role of server A nothing on server B', function () {
    RuxModeratorRole::query()->create(['guild_id' => MS_A, 'role_id' => MS_ROLE_A, 'role_name' => 'Helpers', 'granted_by_discord_id' => MS_ADMIN, 'granted_by_account_id' => 3]);
    // Even with the same role id in their roles on B, A's moderator role does not count there.
    $this->roles[MS_B][MS_MOD] = [MS_ROLE_A];
    msAi([]);

    $access = app(RuxAccess::class);
    expect($access->isModerator(MS_A, MS_MOD))->toBeTrue()
        ->and($access->isModerator(MS_B, MS_MOD))->toBeFalse();

    $inB = RuxMessage::query()->find(app(RuxInbox::class)->accept(msMessage(MS_B, MS_CH_B, MS_MOD, 'rux, ban someone'), MS_BOT));
    app(RuxAgent::class)->handle($inB);

    expect($inB->fresh()->status)->toBe(RuxMessage::IGNORED)
        ->and(Cache::get(RuxAgent::deniedKey(MS_MOD, MS_B)))->toBeTrue()
        // Still heard on A, where the role is theirs.
        ->and(Cache::get(RuxAgent::deniedKey(MS_MOD, MS_A)))->toBeNull();

    $inA = RuxMessage::query()->find(app(RuxInbox::class)->accept(msMessage(MS_A, MS_CH_A, MS_MOD, 'rux, list the rules'), MS_BOT));
    app(RuxAgent::class)->handle($inA);

    expect($inA->fresh()->status)->toBe(RuxMessage::DONE)
        ->and($inA->fresh()->meta['level'])->toBe(RuxAccess::MODERATOR);
});

it('puts a moderator DM on the server they moderate', function () {
    RuxModeratorRole::query()->create(['guild_id' => MS_B, 'role_id' => '710000000000000002', 'role_name' => 'Cloud mods', 'granted_by_discord_id' => MS_ADMIN, 'granted_by_account_id' => 3]);
    $this->roles[MS_B][MS_MOD] = ['710000000000000002'];
    $this->roles[MS_A][MS_MOD] = [];
    msAi([]);

    $dm = RuxMessage::query()->find(app(RuxInbox::class)->accept(msMessage(null, '320000000000000001', MS_MOD, 'list the rules'), MS_BOT));
    app(RuxAgent::class)->handle($dm);

    expect($dm->fresh()->meta['server'])->toBe(MS_B)->and($dm->fresh()->meta['level'])->toBe(RuxAccess::MODERATOR);
});

it('moves an admin DM to another server with switch_server', function () {
    msRule(MS_A, 'locations');
    msRule(MS_B, 'regions');
    $prompts = [];
    msAi([msToolCall('switch_server', ['server' => 'example.cloud']), msToolCall('list_rules'), ['message' => ['content' => 'done']]], $prompts);

    $dm = RuxMessage::query()->find(app(RuxInbox::class)->accept(msMessage(null, '320000000000000001', MS_ADMIN, 'on the cloud server, what are the rules'), MS_BOT));
    app(RuxAgent::class)->handle($dm);

    $listed = (string) RuxMessage::query()->where('turn_id', $dm->id)->where('tool_name', 'list_rules')->value('content');

    expect($prompts[0])->toContain('This DM acts on the ' . config('app.name'))
        ->and($prompts[1])->toContain('This DM acts on the example.cloud')
        ->and($listed)->toContain('asks about regions')->not->toContain('asks about locations')
        ->and(Cache::get(RuxAgent::dmServerKey(MS_ADMIN)))->toBe(MS_B);
});

it('does not let a moderator switch a DM to a server they do not moderate', function () {
    RuxModeratorRole::query()->create(['guild_id' => MS_A, 'role_id' => MS_ROLE_A, 'role_name' => 'Helpers', 'granted_by_discord_id' => MS_ADMIN, 'granted_by_account_id' => 3]);
    msAi([msToolCall('switch_server', ['server' => MS_B]), ['message' => ['content' => 'done']]]);

    $dm = RuxMessage::query()->find(app(RuxInbox::class)->accept(msMessage(null, '320000000000000001', MS_MOD, 'switch to the other server'), MS_BOT));
    app(RuxAgent::class)->handle($dm);

    $switch = RuxMessage::query()->where('turn_id', $dm->id)->where('tool_name', 'switch_server')->first();

    expect($switch->status)->toBe(RuxMessage::FAILED)
        ->and($dm->fresh()->meta['server'])->toBe(MS_A)
        ->and(Cache::get(RuxAgent::dmServerKey(MS_MOD)))->toBeNull();
});

it('only offers switch_server in DMs', function () {
    $tools = app(RuxToolRegistry::class);
    $dm = new RuxContext(MS_A, MS_ADMIN, 'admin', 3, '320000000000000001', true, null);

    expect(array_keys($tools->available(RuxAccess::ADMIN, msContext(MS_A))))->not->toContain('switch_server')
        ->and(array_keys($tools->available(RuxAccess::ADMIN, $dm)))->toContain('switch_server');

    config()->set('plugged-host-ai.rux.servers', []);

    expect(array_keys($tools->available(RuxAccess::ADMIN, $dm)))->not->toContain('switch_server');
});

it('only fires a server\'s rules on that server', function () {
    $ruleA = msRule(MS_A, 'locations');
    msAi([]);
    $rules = app(RuleService::class);

    expect($rules->screen(msMessage(MS_B, MS_CH_B, MS_MEMBER, 'any new locations soon?'), MS_BOT))->toBeNull()
        ->and($rules->screen(msMessage(MS_A, MS_CH_A, MS_MEMBER, 'any new locations soon?'), MS_BOT))->not->toBeNull();

    // A hit from B that names A's rule (an old or forged payload) is not acted on.
    Cache::put('plugged-host-ai:rux:hit:forged', [
        'guild_id' => MS_B, 'rules' => [$ruleA->id], 'message_id' => '610000000000000001', 'channel_id' => MS_CH_B,
        'author_id' => MS_MEMBER, 'author_name' => 'member', 'roles' => [], 'content' => 'new locations?', 'replied_to' => null,
    ], 60);
    $ai = Mockery::mock(RuxAi::class);
    $ai->shouldNotReceive('turn');
    app()->instance(RuxAi::class, $ai);
    app()->forgetInstance(RuleService::class);

    app(RuleService::class)->check('forged');

    expect(RuxCase::query()->count())->toBe(0);
});

it('checks watched channels per server, with each server\'s rules and facts', function () {
    RuxWatch::query()->create(['guild_id' => MS_A, 'channel' => MS_CH_A]);
    RuxWatch::query()->create(['guild_id' => MS_B, 'channel' => MS_CH_B]);
    msRule(MS_A, 'locations');
    msRule(MS_B, 'regions');
    $this->discord->shouldReceive('channels')->andReturn([]);
    $prompts = [];
    msAi([['message' => ['content' => '{"breaks": []}']], ['message' => ['content' => '{"breaks": []}']]], $prompts);

    $watch = app(WatchService::class);

    expect($watch->watchedChannelIds(MS_A))->toBe([MS_CH_A])
        ->and($watch->watchedChannelIds(MS_B))->toBe([MS_CH_B])
        ->and($watch->enqueue(msMessage(MS_A, MS_CH_A, MS_MEMBER, 'hello from A'), MS_BOT))->toBeTrue()
        ->and($watch->enqueue(msMessage(MS_B, MS_CH_B, MS_MEMBER, 'hello from B'), MS_BOT))->toBeTrue()
        // B's channel id is not watched on A.
        ->and($watch->enqueue(msMessage(MS_A, MS_CH_B, MS_MEMBER, 'odd'), MS_BOT))->toBeFalse()
        ->and(RuxWatchItem::query()->pluck('guild_id')->all())->toBe([MS_A, MS_B]);

    $watch->drain(5);

    expect($prompts)->toHaveCount(2)
        ->and($prompts[0])->toContain('asks about locations')->toContain('hello from A')->not->toContain('asks about regions')->not->toContain('hello from B')
        ->and($prompts[1])->toContain('example.cloud Discord server (a free VPS host)')->toContain('asks about regions')
        ->not->toContain('asks about locations')->toContain('example.cloud and its subdomains');
});

it('treats a watched channel and a queued message without a server as the primary server\'s', function () {
    RuxWatch::query()->create(['channel' => MS_CH_A]);
    $this->discord->shouldReceive('channels')->andReturn([]);

    expect(app(WatchService::class)->watchedChannelIds(MS_A))->toBe([MS_CH_A])
        ->and(app(WatchService::class)->watchedChannelIds(MS_B))->toBe([]);
});

it('counts warnings and their steps per server', function () {
    $this->discord->shouldNotReceive('modifyMember');
    $moderation = app(ModerationService::class);
    $target = ['id' => MS_MEMBER, 'name' => 'member'];

    // Two warnings on A, then one on B: B's ladder starts at one.
    $moderation->warn(msContext(MS_A), $target, 'one');
    $moderation->warn(msContext(MS_A), $target, 'two');
    $result = $moderation->warn(msContext(MS_B), $target, 'first on B');

    expect($result['warnings'])->toBe(1)
        ->and($result['escalation'])->toBeNull()
        ->and($moderation->warningCount(MS_A, MS_MEMBER))->toBe(2)
        ->and($moderation->clearWarnings(msContext(MS_B), $target, null, 'clean'))->toBe(1)
        ->and($moderation->warningCount(MS_A, MS_MEMBER))->toBe(2);
});

it('posts each server\'s cases to its own mod log', function () {
    $moderation = app(ModerationService::class);
    $moderation->note(msContext(MS_A), 'rule_add', 'on A');
    $moderation->note(msContext(MS_B), 'rule_add', 'on B');

    expect(array_column($this->posted, 'channel'))->toBe([MS_LOG_A, MS_LOG_B]);
});

it('lists and finds only the server\'s own cases and giveaways', function () {
    RuxCase::query()->create(['guild_id' => MS_A, 'action' => 'warn', 'target_id' => MS_MEMBER, 'moderator_discord_id' => MS_ADMIN, 'reason' => 'case on A', 'automatic' => false]);
    $giveaway = RuxGiveaway::query()->create([
        'guild_id' => MS_A, 'channel_id' => MS_CH_A, 'prize' => '100 credits', 'credits' => 100, 'winners_count' => 1,
        'ends_at' => now()->addDay(), 'status' => RuxGiveaway::ACTIVE, 'created_by_discord_id' => MS_ADMIN, 'created_by_account_id' => 3,
    ]);

    $casesOnB = app(ListCasesTool::class)->execute(msContext(MS_B), []);
    $giveawaysOnB = app(ListGiveawaysTool::class)->execute(msContext(MS_B), []);
    $giveawaysOnA = app(ListGiveawaysTool::class)->execute(msContext(MS_A), []);

    expect($casesOnB->content)->not->toContain('case on A')
        ->and(json_decode($giveawaysOnB->content, true)['running'])->toBe([])
        ->and(json_decode($giveawaysOnA->content, true)['running'])->toHaveCount(1)
        ->and(fn () => app(EndGiveawayTool::class)->execute(msContext(MS_B), ['giveaway_id' => $giveaway->id]))
        ->toThrow(InvalidArgumentException::class, 'no giveaway #' . $giveaway->id . ' on this server');
});

it('does not let a button on another server enter a giveaway', function () {
    $giveaway = RuxGiveaway::query()->create([
        'guild_id' => MS_A, 'channel_id' => MS_CH_A, 'prize' => '100 credits', 'credits' => 100, 'winners_count' => 1,
        'ends_at' => now()->addDay(), 'status' => RuxGiveaway::ACTIVE, 'created_by_discord_id' => MS_ADMIN, 'created_by_account_id' => 3,
    ]);
    $answer = null;

    app(GiveawayService::class)->handleInteraction([
        'type' => 3, 'guild_id' => MS_B, 'data' => ['custom_id' => "rux:gw:enter:{$giveaway->id}"],
        'member' => ['user' => ['id' => MS_MEMBER, 'username' => 'member']],
    ], function (array $response) use (&$answer): void {
        $answer = $response;
    });

    expect($answer['data']['content'])->toBe(RuxConfig::say('entry.closed'))
        ->and($giveaway->entries()->count())->toBe(0);
});

it('counts spam per server', function () {
    $spam = app(SpamGuard::class);

    // The same text twice on A and twice on B is not "three copies".
    foreach ([MS_A, MS_A, MS_B, MS_B] as $guild) {
        expect($spam->screen(msMessage($guild, $guild === MS_A ? MS_CH_A : MS_CH_B, MS_MEMBER, 'join my server now'), MS_BOT))->toBeFalse();
    }

    expect($spam->screen(msMessage(MS_B, MS_CH_B, MS_MEMBER, 'join my server now'), MS_BOT))->toBeTrue();

    $supervisor = Mockery::mock(RuxSupervisor::class);
    $key = null;
    $supervisor->shouldReceive('spawnCommand')->once()->andReturnUsing(function (string $command, array $arguments) use (&$key) {
        $key = $arguments[0];

        return true;
    });
    $spam->flush($supervisor);

    expect(Cache::get("plugged-host-ai:rux:spam:{$key}")['guild_id'])->toBe(MS_B);
});

it('keeps every server in the gateway status, so a changed list restarts the daemon', function () {
    $command = app(RuxGatewayCommand::class);

    expect((new ReflectionMethod($command, 'guildId'))->invoke($command))->toBe(MS_A . ',' . MS_B);
});

it('edits the servers on the settings page', function () {
    msAi([]);
    $payouts = Mockery::mock(DiscordCreditPayouts::class);
    $payouts->shouldReceive('configured')->andReturnTrue();
    app()->instance(DiscordCreditPayouts::class, $payouts);

    $data = RuxSettings::data();

    expect($data['server_2'])->toMatchArray(['id' => MS_B, 'name' => 'example.cloud', 'about' => 'a free VPS host', 'mod_log_channel_id' => MS_LOG_B, 'trusted_domains' => 'example.cloud'])
        ->and($data['server_3']['id'])->toBe('');

    // Slot 2 in use, 3 and 4 offered, the rest hidden.
    $servers = collect(RuxSettings::form())->first(fn ($component) => $component instanceof Section && $component->getHeading() === 'More Discord servers');
    $slots = (fn (): array => $this->childComponents['default'])->call($servers);
    expect(array_map(fn (Section $section) => $section->getHeading(), $slots))->toBe(['Server 2', 'Server 3', 'Server 4']);

    $data['server_3'] = ['id' => MS_C, 'name' => "noprofit\nexample", 'about' => '', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => ' Example.org , forms.example.org'];
    $data['server_4'] = ['id' => '', 'name' => '', 'about' => '', 'giveaway_channel_id' => '', 'mod_log_channel_id' => '', 'trusted_domains' => ''];
    $env = RuxSettings::env($data);

    expect($env)->toMatchArray([
        'RUX_GUILD_ID' => MS_A,
        'RUX_GUILD_2_ID' => MS_B,
        'RUX_GUILD_2_NAME' => 'example.cloud',
        'RUX_GUILD_2_MOD_LOG_CHANNEL_ID' => MS_LOG_B,
        'RUX_GUILD_3_ID' => MS_C,
        'RUX_GUILD_3_NAME' => 'noprofit example',
        'RUX_GUILD_3_TRUSTED_DOMAINS' => 'example.org,forms.example.org',
    ])
        // An empty slot that was never used writes nothing.
        ->and(array_key_exists('RUX_GUILD_4_ID', $env))->toBeFalse();

    // Clearing a used slot's id empties it (rux stops there).
    $data['server_2']['id'] = '';
    expect(RuxSettings::env($data)['RUX_GUILD_2_ID'])->toBe('');
});
