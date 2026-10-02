<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Minecraft\Services\ModDetectionService;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
});

function jarRow(array $meta): array
{
    $jar = ['name' => 'Example.jar', 'disabled' => false, 'size' => 1, 'modified' => now()->toIso8601String()];

    return (new ReflectionMethod(ModDetectionService::class, 'row'))
        ->invoke(app(ModDetectionService::class), $jar, ['meta' => $meta, 'sha1' => 'x'], []);
}

it('drops a jar website that is not a web address', function () {
    expect(jarRow(['name' => 'Example', 'website' => 'javascript:alert(document.domain)'])['url'])->toBeNull();
});

it('keeps a jar website that is a web address', function () {
    expect(jarRow(['name' => 'Example', 'website' => 'https://example.org/plugin'])['url'])->toBe('https://example.org/plugin');
});
