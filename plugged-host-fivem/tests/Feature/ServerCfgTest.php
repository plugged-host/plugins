<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\FiveM\Services\ServerCfg;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-fivem'));

const CFG = <<<'CFG'
# My server
endpoint_add_tcp "0.0.0.0:30120" # plugged.host: do not modify!
sv_hostname "Old name" # shown in the browser
sets sv_projectName "[ESX] Project"
set mysql_connection_string "mysql://u:p@host:3306/db?charset=utf8mb4"
fileserver_add ".*" http://cdn.example//files
sv_maxclients 48

## Resources
ensure chat
# ensure pma-voice
# start the server with these
ensure [standalone]

add_principal identifier.fivem:1 group.admin #owner
add_ace group.admin command allow # allow all commands
add_ace group.admin command.quit deny
CFG;

it('renders an untouched file byte for byte', function () {
    foreach ([CFG, CFG . "\n", str_replace("\n", "\r\n", CFG) . "\r\n", ''] as $raw) {
        expect((new ServerCfg($raw))->render())->toBe($raw);
    }
});

it('reads settings in both forms and keeps unquoted URLs whole', function () {
    $cfg = new ServerCfg(CFG);

    expect($cfg->get('sv_hostname'))->toBe('Old name')
        ->and($cfg->get('sv_projectName'))->toBe('[ESX] Project')
        ->and($cfg->get('sv_maxclients'))->toBe('48')
        ->and($cfg->get('fileserver_add'))->toBe('.*')
        ->and(array_column(ServerCfg::tokenize('fileserver_add ".*" http://cdn.example//files')[0], 'text'))->toBe(['fileserver_add', '.*', 'http://cdn.example//files'])
        ->and($cfg->endpoints())->toBe(['0.0.0.0:30120']);
});

it('changes one value in place and keeps the comment and quoting', function () {
    $cfg = new ServerCfg(CFG);
    $cfg->set('sv_hostname', 'New name', 'command');
    $cfg->set('sv_maxclients', '64', 'command', false);

    $out = $cfg->render();

    expect($out)->toContain('sv_hostname "New name" # shown in the browser')
        ->and($out)->toContain("sv_maxclients 64\n")
        ->and(substr_count($out, "\n"))->toBe(substr_count(CFG, "\n"));
});

it('adds a missing setting before the first resource and drops an emptied one', function () {
    $cfg = new ServerCfg(CFG);
    $cfg->set('sv_licenseKey', 'abc', 'command');
    $cfg->set('mysql_connection_string', '', 'set');

    $lines = explode("\n", $cfg->render());

    expect(array_search('sv_licenseKey "abc"', $lines, true))->toBeLessThan(array_search('ensure chat', $lines, true))
        ->and($cfg->render())->not->toContain('mysql_connection_string');
});

it('never lets a value start a new command', function () {
    $cfg = new ServerCfg(CFG);
    $cfg->set('sv_hostname', "x\"\nexec evil.cfg", 'command');

    expect($cfg->render())->toContain("sv_hostname \"x' exec evil.cfg\"")
        ->and(substr_count($cfg->render(), "\n"))->toBe(substr_count(CFG, "\n"));
});

it('lists commented ensure lines as disabled resources but not prose', function () {
    expect(array_map(fn ($row) => [$row['name'], $row['enabled']], (new ServerCfg(CFG))->resources()))
        ->toBe([['chat', true], ['pma-voice', false], ['[standalone]', true]]);
});

it('toggles, removes and adds resources by line', function () {
    $cfg = new ServerCfg(CFG);
    [$chat, $voice] = $cfg->resources();

    $cfg->setResources([
        ['key' => $chat['key'], 'name' => 'chat', 'enabled' => false],
        ['key' => $voice['key'], 'name' => 'pma-voice', 'enabled' => true],
        ['key' => null, 'name' => 'oxmysql', 'enabled' => true],
        ['key' => null, 'name' => 'bad name; quit', 'enabled' => true],
    ]);

    $out = $cfg->render();

    expect($out)->toContain("# ensure chat\nensure pma-voice\n")
        ->and($out)->not->toContain('ensure [standalone]')
        ->and($out)->toContain("# start the server with these\n")
        ->and($out)->not->toContain('bad name')
        ->and(strpos($out, 'ensure oxmysql'))->toBeGreaterThan(strpos($out, '# start the server'));
});

it('edits permissions without losing their comments', function () {
    $cfg = new ServerCfg(CFG);
    $aces = $cfg->aces();
    $aces[0]['access'] = 'deny';
    unset($aces[1]);
    $aces[] = ['key' => null, 'principal' => 'group.mod', 'object' => 'command.kick', 'access' => 'allow'];
    // A key that is not an add_ace line is treated as a new row, never as that line.
    $aces[] = ['key' => '0', 'principal' => 'group.x', 'object' => 'y', 'access' => 'allow'];

    $cfg->setAces(array_values($aces));
    $out = $cfg->render();

    expect($out)->toContain('add_ace group.admin command deny # allow all commands')
        ->and($out)->not->toContain('command.quit')
        ->and($out)->toContain("add_ace group.mod command.kick allow\nadd_ace group.x y allow")
        ->and($out)->toStartWith('# My server')
        ->and((new ServerCfg($out))->principals()[0]['child'])->toBe('identifier.fivem:1');
});

it('recognises a RedM server', function () {
    expect((new ServerCfg("set gamename rdr3\n"))->isRedM())->toBeTrue()
        ->and((new ServerCfg(CFG))->isRedM())->toBeFalse();
});
