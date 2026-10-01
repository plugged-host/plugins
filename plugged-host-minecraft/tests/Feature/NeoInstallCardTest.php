<?php

use App\Tests\Integration\IntegrationTestCase;
use PluggedHost\Minecraft\Neo\Tools\InstallContentTool;
use PluggedHost\Minecraft\Neo\Tools\InstallModpackTool;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(function () {
    loadPluginForTest('plugged-host-neo');
    loadPluginForTest('plugged-host-servers');
    loadPluginForTest('plugged-host-minecraft');
});

it('labels the approval card with the project that gets installed, not a name the model chose', function (string $kind) {
    // Plain keys, not class strings: Pest looks at dataset values before the plugin is loaded.
    $tool = $kind === 'content' ? InstallContentTool::class : InstallModpackTool::class;
    $summary = app($tool)->summarize(['project' => 'abcd1234', 'version' => 'v9', 'source' => 'modrinth', 'name' => 'EssentialsX (official)']);

    expect($summary)->toContain('abcd1234')->not->toContain('EssentialsX');
})->with(['content', 'modpack']);
