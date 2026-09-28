<?php

namespace PluggedHost\Storage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Storage\Services\StorageService;

class BackupPortalController extends Controller
{
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
            $queryParam = $initialServerId !== '' ? ('?server=' . urlencode($initialServerId)) : '';

            return redirect("{$panelUrl}/auth/backups-sso{$queryParam}");
        }

        $discordLink = null;
        $userServers = collect();

        if ($user) {
            if (class_exists(DiscordLink::class)) {
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

        // If user is already logged into the panel
        if ($user) {
            $ts = time();
            $sig = hash_hmac('sha256', "sso:{$user->id}:{$ts}", config('app.key'));

            return redirect("{$portalUrl}/auth/sso?uid={$user->id}&ts={$ts}&sig={$sig}{$queryServerParam}");
        }

        // If prompt requested (user explicitly clicked "Login with Panel Account")
        if ($prompt) {
            $returnTo = "{$panelUrl}/auth/backups-sso" . ($returnServer ? ('?server=' . urlencode((string) $returnServer)) : '');
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

        // Verify cryptographic signature using panel app key
        $expectedSig = hash_hmac('sha256', "sso:{$uid}:{$ts}", config('app.key'));
        if (!hash_equals($expectedSig, $sig)) {
            return redirect("{$portalUrl}/?error=" . urlencode('Invalid login signature.'));
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
        $panelUrl = $this->panelBaseUrl();
        $server = $request->query('server');
        $query = '?prompt=1' . ($server ? ('&server=' . urlencode((string) $server)) : '');

        return redirect("{$panelUrl}/auth/backups-sso{$query}");
    }

    /**
     * Handle Discord OAuth callback directly on backups portal (fallback).
     */
    public function authCallback(Request $request): RedirectResponse
    {
        $panelUrl = $this->panelBaseUrl();

        return redirect("{$panelUrl}/auth/backups-sso?prompt=1");
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
