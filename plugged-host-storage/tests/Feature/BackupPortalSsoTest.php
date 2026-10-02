<?php

use App\Models\User;
use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Http\Request;
use PluggedHost\Storage\Http\Controllers\BackupPortalController;

require_once __DIR__ . '/../../../plugged-host-core/tests/Support/load-plugin.php';

uses(IntegrationTestCase::class);

beforeEach(fn () => loadPluginForTest('plugged-host-storage'));

function portalCallback(array $query, ?string $sessionState): void
{
    $request = Request::create('/auth/sso', 'GET', $query);
    $session = app('session')->driver();
    $session->start();
    if ($sessionState !== null) {
        $session->put('plugged-host-storage.backups-sso.state', $sessionState);
    }
    $request->setLaravelSession($session);

    app(BackupPortalController::class)->portalSsoCallback($request);
}

function signedLink(User $user, string $state): array
{
    $ts = time();

    return ['uid' => $user->id, 'ts' => $ts, 'state' => $state, 'sig' => hash_hmac('sha256', "sso:{$user->id}:{$ts}:{$state}", (string) config('app.key'))];
}

it('does not sign a browser in with a link that was started in another browser', function () {
    $attacker = User::factory()->create();
    $link = signedLink($attacker, str_repeat('a', 40));

    portalCallback($link, str_repeat('b', 40));
    expect(auth()->check())->toBeFalse();

    portalCallback($link, null);
    expect(auth()->check())->toBeFalse();
});

it('signs in once with a link answering this browser\'s handshake', function () {
    $user = User::factory()->create();
    $state = str_repeat('c', 40);
    $link = signedLink($user, $state);

    portalCallback($link, $state);
    expect(auth()->id())->toBe($user->id);

    auth()->logout();
    portalCallback($link, $state);
    expect(auth()->check())->toBeFalse();
});

it('does not sign in with the old, state-less link format', function () {
    $user = User::factory()->create();
    $ts = time();

    portalCallback(['uid' => $user->id, 'ts' => $ts, 'sig' => hash_hmac('sha256', "sso:{$user->id}:{$ts}", (string) config('app.key'))], null);

    expect(auth()->check())->toBeFalse();
});
