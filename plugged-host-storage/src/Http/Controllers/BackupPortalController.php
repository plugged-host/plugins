<?php

namespace PluggedHost\Storage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Storage\Services\StorageService;

class BackupPortalController extends Controller
{
    private const SSO_STATE = 'plugged-host-storage.backups-sso.state';

    public function __construct(
        protected StorageService $storageService
    ) {}

    /**
     * Resolve the base URL of the panel.
     */
    protected function panelBaseUrl(): string
    {
        return rtrim(config('app.url', 'https://panel.plugged.host'), '/');
    }

    /**
     * Resolve the base URL of the backups portal.
     */
    protected function portalBaseUrl(): string
    {
        return rtrim(env('BACKUPS_PORTAL_URL', 'https://backups.plugged.host'), '/');
    }

    /**
     * The panel's SSO handshake URL, with a fresh state kept in this browser's session. The
     * panel signs the state into the link it sends back, and the callback only accepts a link
     * carrying this browser's state, so a link made in someone else's browser cannot sign
     * this one in (login CSRF).
     *
     * @param  array<string, string>  $query
     */
    protected function ssoStartUrl(Request $request, array $query = []): string
    {
        $state = Str::random(40);
        $request->session()->put(self::SSO_STATE, $state);

        return $this->panelBaseUrl() . '/auth/backups-sso?' . http_build_query(['state' => $state] + array_filter($query, fn ($v) => $v !== null && $v !== ''));
    }

    protected static function ssoSignature(int $uid, int $ts, string $state): string
    {
        return hash_hmac('sha256', "sso:{$uid}:{$ts}:{$state}", (string) config('app.key'));
    }

    /**
     * Show the backups retrieval homepage.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = auth()->user();
        $panelUrl = $this->panelBaseUrl();
        $portalUrl = $this->portalBaseUrl();
        // Only used to highlight one of the signed-in owner's own server cards.
        $initialServerId = preg_replace('/[^a-f0-9]/', '', strtolower(trim((string) ($request->query('server') ?? $request->query('s') ?? ''))));

        // Auto-SSO handshake: if not logged in on backups and hasn't checked panel yet in this browser session,
        // do a fast silent handshake with the panel to auto-get panel session.
        if (!$user && !$request->has('guest') && !$request->session()->has('sso_checked')) {
            $request->session()->put('sso_checked', true);

            return redirect($this->ssoStartUrl($request, ['server' => $initialServerId]));
        }

        $discordLink = null;
        $userServers = collect();

        if ($user) {
            if (Plugins::available(DiscordLink::class)) {
                $discordLink = DiscordLink::where('user_id', $user->id)->first();
            }

            $servers = Server::query()
                ->where('owner_id', $user->id)
                ->with(['egg', 'node'])
                ->orderBy('created_at', 'desc')
                ->get();

            // One concurrent round to the storage agent for every server, not one after another.
            $infos = $this->storageService->backupInfoMany($servers->pluck('uuid')->all());

            $userServers = $servers->map(function (Server $server) use ($panelUrl, $infos) {
                $info = $infos[$server->uuid] ?? null;
                $hasBackup = (bool) ($info['exists'] ?? false);
                $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $server->name);
                $filename = "backup-{$cleanName}-{$server->uuid_short}.tar.gz";

                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'uuid' => $server->uuid,
                    'uuid_short' => $server->uuid_short,
                    'egg' => $server->egg?->name ?? 'Game Server',
                    'node' => $server->node?->name ?? 'Node',
                    'cpu' => $server->cpu,
                    'memory' => $server->memory,
                    'disk' => $server->disk,
                    'has_backup' => $hasBackup,
                    // The storage agent did not answer: say so instead of "waiting for the first copy".
                    'unreachable' => $info === null,
                    'bytes' => $info['bytes'] ?? null,
                    // Set when the archive is already built: the download then starts at full
                    // speed with a known size; otherwise it streams while it is being packed.
                    'archive_bytes' => $info['archive_bytes'] ?? null,
                    'mtime' => $info['mtime'] ?? null,
                    // Signed, short-lived storage link; only ever built for the owner's own servers.
                    // stream=1: the agent sends the archive while it packs it, so the browser's
                    // download starts at once instead of after the whole archive is built.
                    'download_url' => $hasBackup
                        ? $this->storageService->archiveUrlForIdentifier($server->uuid, $filename) . '&stream=1'
                        : null,
                    'manage_url' => "{$panelUrl}/server/{$server->uuid_short}",
                    'backups_url' => "{$panelUrl}/server/{$server->uuid_short}/backups",
                ];
            });
        }

        return view('plugged-host-storage::backup-portal', [
            'user' => $user,
            'discordLink' => $discordLink,
            'userServers' => $userServers,
            'panelUrl' => $panelUrl,
            'portalUrl' => $portalUrl,
            'initialServerId' => $initialServerId,
        ]);
    }

    /**
     * SSO Handshake on panel: checks if user is logged into the panel.
     */
    public function panelSso(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $prompt = $request->boolean('prompt');
        $portalUrl = $this->portalBaseUrl();
        $panelUrl = $this->panelBaseUrl();
        $returnServer = $request->query('server') ?? $request->query('s');
        $queryServerParam = $returnServer ? ('&server=' . urlencode((string) $returnServer)) : '';

        // The portal's state for this browser; without one there is no handshake to answer.
        $state = (string) $request->query('state', '');
        if (preg_match('/^[A-Za-z0-9]{32,64}$/', $state) !== 1) {
            return redirect("{$portalUrl}/?guest=1" . $queryServerParam);
        }

        // If user is already logged into the panel
        if ($user) {
            $ts = time();
            $sig = self::ssoSignature((int) $user->id, $ts, $state);

            return redirect("{$portalUrl}/auth/sso?uid={$user->id}&ts={$ts}&state={$state}&sig={$sig}{$queryServerParam}");
        }

        // If prompt requested (user explicitly clicked "Login with Panel Account")
        if ($prompt) {
            $returnTo = "{$panelUrl}/auth/backups-sso?" . http_build_query(array_filter(['state' => $state, 'server' => $returnServer ? (string) $returnServer : null]));
            $request->session()->put('url.intended', $returnTo);

            return redirect('/login');
        }

        // Silent check without active session -> return to backups portal as guest
        return redirect("{$portalUrl}/?guest=1" . ($returnServer ? ('&server=' . urlencode((string) $returnServer)) : ''));
    }

    /**
     * Complete the SSO login on backups portal.
     */
    public function portalSsoCallback(Request $request): RedirectResponse
    {
        $uid = (int) $request->input('uid');
        $ts = (int) $request->input('ts');
        $sig = (string) $request->input('sig');
        $portalUrl = $this->portalBaseUrl();
        $returnServer = $request->query('server') ?? $request->query('s');
        $redirectTarget = $portalUrl . ($returnServer ? ('/?server=' . urlencode((string) $returnServer)) : '/');

        if (!$uid || !$ts || !$sig) {
            return redirect("{$portalUrl}/?guest=1");
        }

        // Token valid for 2 minutes
        if (abs(time() - $ts) > 120) {
            return redirect("{$portalUrl}/?error=" . urlencode('Login token expired. Please try again.'));
        }

        // The link must answer the handshake this browser started (see ssoStartUrl()).
        $state = (string) $request->input('state', '');
        $expectedState = $request->session()->pull(self::SSO_STATE);
        if (!is_string($expectedState) || $state === '' || !hash_equals($expectedState, $state)) {
            return redirect("{$portalUrl}/?error=" . urlencode('This sign-in link was not started in this browser. Please try again.'));
        }

        // Verify cryptographic signature using panel app key
        $expectedSig = self::ssoSignature($uid, $ts, $state);
        if (!hash_equals($expectedSig, $sig)) {
            return redirect("{$portalUrl}/?error=" . urlencode('Invalid login signature.'));
        }

        // Each link signs in once.
        if (!Cache::add('plugged-host-storage:backups-sso:' . $sig, true, 300)) {
            return redirect("{$portalUrl}/?error=" . urlencode('Login token already used. Please try again.'));
        }

        $user = \App\Models\User::find($uid);
        if (!$user) {
            return redirect("{$portalUrl}/?error=" . urlencode('User account not found.'));
        }

        auth()->guard()->login($user, true);
        $request->session()->regenerate();
        $request->session()->put('sso_checked', true);

        return redirect($redirectTarget);
    }

    /**
     * Initiate login by directing to panel SSO handshake.
     */
    public function authRedirect(Request $request): RedirectResponse
    {
        $server = $request->query('server');

        return redirect($this->ssoStartUrl($request, ['prompt' => '1', 'server' => $server ? (string) $server : null]));
    }

    /**
     * Handle Discord OAuth callback directly on backups portal (fallback).
     */
    public function authCallback(Request $request): RedirectResponse
    {
        return redirect($this->ssoStartUrl($request, ['prompt' => '1']));
    }

    /**
     * Log out the current user session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $portalUrl = $this->portalBaseUrl();

        auth()->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('sso_checked', true);

        return redirect("{$portalUrl}/?guest=1");
    }
}
