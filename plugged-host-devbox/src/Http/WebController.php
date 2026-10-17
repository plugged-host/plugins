<?php

namespace PluggedHost\Devbox\Http;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PluggedHost\Devbox\Models\Devbox;
use PluggedHost\Devbox\Support\DevboxAccess;
use PluggedHost\Devbox\Support\DevboxWeb;
use PluggedHost\Servers\Filament\Server\Pages\ServerSettings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * VS Code in the browser, the panel's half of the edge (docs/templates/nginx/devbox-web.conf):
 *
 * 1. "Open in browser" in the panel (signed in, 2FA) makes a one-time ticket for that devbox and
 *    sends the browser to https://<slug>.<web_domain>/_ph/login?ticket=…
 * 2. login() (reached through the edge, so its cookie lands on the devbox's own host) redeems it
 *    and sets a host-only __Host- cookie for 12 hours.
 * 3. authorize() answers nginx's auth_request for every request: the cookie still belongs to that
 *    host, the devbox runs and the person may still use it; it hands nginx the devbox's node:port.
 *
 * login() and authorize() run without the web middleware: no panel session or panel cookies ever
 * reach a devbox host.
 */
final class WebController
{
    private const TICKET_SECONDS = 60;

    private const SESSION_HOURS = 12;

    public static function ticket(Devbox $devbox, User $user): string
    {
        $ticket = Str::random(48);
        Cache::put(self::ticketKey($ticket), [
            'devbox' => $devbox->id,
            'user' => $user->id,
            'host' => DevboxWeb::host($devbox),
        ], self::TICKET_SECONDS);

        return $ticket;
    }

    public function login(Request $request): Response
    {
        $host = strtolower((string) $request->header('X-Devbox-Host', ''));
        $ticket = (string) $request->query('ticket', '');

        $data = strlen($ticket) === 48 ? Cache::pull(self::ticketKey($ticket)) : null;
        if (!is_array($data) || ($data['host'] ?? null) !== $host) {
            return $this->denied($host);
        }

        $devbox = Devbox::query()->find($data['devbox']);
        $user = User::query()->find($data['user']);
        if ($devbox === null || $user === null || !$this->allowed($devbox, $user)) {
            return $this->denied($host);
        }

        $expires = now()->addHours(self::SESSION_HOURS);
        $value = Crypt::encryptString((string) json_encode([
            'd' => $devbox->id,
            'u' => $user->id,
            'h' => $host,
            'e' => $expires->getTimestamp(),
        ]));

        // A relative Location: the browser stays on the devbox's host.
        return response('', 302, ['Location' => '/', 'Cache-Control' => 'no-store'])
            ->withCookie(Cookie::create(DevboxWeb::COOKIE, $value, $expires, '/', null, true, true, false, Cookie::SAMESITE_LAX));
    }

    public function authorize(Request $request): Response
    {
        $host = strtolower((string) $request->header('X-Devbox-Host', ''));
        $session = $this->session((string) $request->cookies->get(DevboxWeb::COOKIE, ''), $host);
        if ($session === null) {
            return response('', 401, ['Cache-Control' => 'no-store']);
        }

        [$devbox, $user] = $session;
        $ok = Cache::remember(
            "plugged-host-devbox:web-ok:{$devbox->id}:{$user->id}",
            30,
            fn (): bool => $this->allowed($devbox, $user),
        );
        $upstream = $ok ? DevboxWeb::upstream($devbox) : null;
        if ($upstream === null) {
            return response('', 401, ['Cache-Control' => 'no-store']);
        }

        return response('', 204, ['X-Devbox-Upstream' => $upstream, 'Cache-Control' => 'no-store']);
    }

    /**
     * Where the edge sends a browser without a valid cookie: through the panel's sign-in, then a
     * fresh ticket. Runs with the web middleware (the panel session).
     */
    public function reopen(Request $request): Response
    {
        $devbox = DevboxWeb::devboxForHost((string) $request->query('host', ''));
        $panel = Filament::getPanel('app');

        if ($devbox === null) {
            return redirect($panel->getUrl());
        }

        $user = $request->user();
        if (!$user instanceof User) {
            return redirect($panel->getLoginUrl());
        }

        if (!$this->allowed($devbox, $user)) {
            // Stopped (or no longer theirs): back to the server's settings to start it again.
            return redirect($devbox->game ? ServerSettings::getUrl(panel: 'server', tenant: $devbox->game) . '#vscode' : $panel->getUrl());
        }

        return redirect()->away(DevboxWeb::url($devbox, '/_ph/login?ticket=' . self::ticket($devbox, $user)));
    }

    /**
     * @return array{0: Devbox, 1: User}|null
     */
    private function session(string $cookie, string $host): ?array
    {
        if ($cookie === '' || strlen($cookie) > 4096) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($cookie), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || ($data['h'] ?? null) !== $host || (int) ($data['e'] ?? 0) < time()) {
            return null;
        }

        $devbox = Devbox::query()->find((int) ($data['d'] ?? 0));
        $user = User::query()->find((int) ($data['u'] ?? 0));
        if ($devbox === null || $user === null || DevboxWeb::host($devbox) !== $host) {
            return null;
        }

        return [$devbox, $user];
    }

    private function allowed(Devbox $devbox, User $user): bool
    {
        return DevboxAccess::ready()
            && $devbox->isRunning()
            && DevboxAccess::people($devbox)->contains(fn (User $person): bool => (int) $person->id === (int) $user->id);
    }

    private function denied(string $host): Response
    {
        $panel = rtrim((string) config('app.url'), '/');

        return response(
            '<!doctype html><meta charset="utf-8"><title>Devbox</title><p>This link is used up or expired. Open the devbox again from the <a href="' . e($panel) . '">plugged.host panel</a>.</p>',
            401,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    private static function ticketKey(string $ticket): string
    {
        return 'plugged-host-devbox:web-ticket:' . hash('sha256', $ticket);
    }
}
