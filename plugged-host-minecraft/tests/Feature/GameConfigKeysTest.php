<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Minecraft\Filament\Server\Pages\GameConfigPage;
use PluggedHost\Minecraft\Services\GameConfigFile;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-minecraft'));

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
function describeConfig(string $path, string $raw): array
{
    return (new ReflectionMethod(GameConfigPage::class, 'describe'))
        ->invoke(new GameConfigPage(), app(GameConfigFile::class), $path, $raw);
}

it('leaves keys that are not plain out of the yaml form', function () {
    $raw = "settings:\n  allow-end: true\n  \"x&#39;+alert(document.domain)+&#39;\": true\n  'a\"b': true\n";

    [$file, $values] = describeConfig('bukkit.yml', $raw);

    expect(array_keys($file['fields']))->toBe(['settings.allow-end'])
        ->and(array_keys($values))->toBe(['settings.allow-end']);
});

it('leaves keys that are not plain out of the server.properties form', function () {
    $raw = "motd=A Minecraft Server\nlevel-name=world\nx&#39;y=1\n";

    [$file] = describeConfig('server.properties', $raw);

    expect(array_keys($file['fields']))->toBe(['motd', 'level-name']);
});
