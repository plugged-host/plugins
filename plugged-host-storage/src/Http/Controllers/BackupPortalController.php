<?php

namespace PluggedHost\Storage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use PluggedHost\Discord\Models\DiscordLink;
use PluggedHost\Storage\Services\StorageService;

class BackupPortalController extends Controller
{
    public function __construct(
        protected StorageService $storageService
    ) {}

    /**
     * Show the backups retrieval homepage.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $user = auth()->user();

        // Auto-SSO handshake: if not logged in on backups and hasn't checked panel yet in this browser session,
        // do a fast silent handshake with panel.plugged.host to auto-get panel session.
        if (!$user && !$request->has('guest') && !$request->session()->has('sso_checked')) {
            $request->session()->put('sso_checked', true);

            return redirect('https://panel.plugged.host/auth/backups-sso');
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

            $userServers = $servers->map(function (Server $server) {
                $info = $this->storageService->backupInfo($server->uuid);
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
                    'has_backup' => $hasBackup,
                    'bytes' => $info['bytes'] ?? null,
                    'mtime' => $info['mtime'] ?? null,
                    'download_url' => $hasBackup
                        ? $this->storageService->archiveUrlForIdentifier($server->uuid, $filename)
                        : route('backups.portal.download', ['identifier' => $server->uuid_short]),
                ];
            });
        }

        return view('plugged-host-storage::backup-portal', [
            'user' => $user,
            'discordLink' => $discordLink,
            'userServers' => $userServers,
        ]);
    }

    /**
     * SSO Handshake on panel.plugged.host: checks if user is logged into the panel.
     */
    public function panelSso(Request $request): RedirectResponse
    {
        $user = auth()->user();
        $prompt = $request->boolean('prompt');

        // If user is already logged into the panel
        if ($user) {
            $ts = time();
            $sig = hash_hmac('sha256', "sso:{$user->id}:{$ts}", config('app.key'));

            return redirect("https://backups.plugged.host/auth/sso?uid={$user->id}&ts={$ts}&sig={$sig}");
        }

        // If prompt requested (user explicitly clicked "Login with Panel Account")
        if ($prompt) {
            $request->session()->put('url.intended', 'https://panel.plugged.host/auth/backups-sso');

            return redirect('/login');
        }

        // Silent check without active session -> return to backups portal as guest
        return redirect('https://backups.plugged.host/?guest=1');
    }

    /**
     * Complete the SSO login on backups.plugged.host.
     */
    public function portalSsoCallback(Request $request): RedirectResponse
    {
        $uid = (int) $request->input('uid');
        $ts = (int) $request->input('ts');
        $sig = (string) $request->input('sig');

        if (!$uid || !$ts || !$sig) {
            return redirect('https://backups.plugged.host/?guest=1');
        }

        // Token valid for 2 minutes
        if (abs(time() - $ts) > 120) {
            return redirect('https://backups.plugged.host/?error=' . urlencode('Login token expired. Please try again.'));
        }

        // Verify cryptographic signature using panel app key
        $expectedSig = hash_hmac('sha256', "sso:{$uid}:{$ts}", config('app.key'));
        if (!hash_equals($expectedSig, $sig)) {
            return redirect('https://backups.plugged.host/?error=' . urlencode('Invalid login signature.'));
        }

        $user = \App\Models\User::find($uid);
        if (!$user) {
            return redirect('https://backups.plugged.host/?error=' . urlencode('User account not found.'));
        }

        auth()->guard()->login($user, true);
        $request->session()->regenerate();
        $request->session()->put('sso_checked', true);

        return redirect('https://backups.plugged.host/');
    }

    /**
     * Initiate login by directing to panel SSO handshake.
     */
    public function authRedirect(Request $request): RedirectResponse
    {
        return redirect('https://panel.plugged.host/auth/backups-sso?prompt=1');
    }

    /**
     * Handle Discord OAuth callback directly on backups portal (fallback).
     */
    public function authCallback(Request $request): RedirectResponse
    {
        return redirect('https://panel.plugged.host/auth/backups-sso?prompt=1');
    }

    /**
     * Log out the current user session.
     */
    public function logout(Request $request): RedirectResponse
    {
        auth()->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('sso_checked', true);

        return redirect('https://backups.plugged.host/?guest=1');
    }

    /**
     * AJAX/POST lookup for a server by short ID (e.g. a1b2c3d4) or full UUID.
     */
    public function lookup(Request $request): JsonResponse
    {
        $input = strtolower(trim((string) $request->input('server_id', '')));
        $cleanId = preg_replace('/[^a-f0-9-]/i', '', $input);

        if (empty($cleanId) || strlen($cleanId) < 6) {
            return response()->json([
                'ok' => false,
                'message' => 'Please provide a valid Server ID (at least 6 characters, e.g. a1b2c3d4).',
            ], 422);
        }

        // 1. Search in Pelican database
        $server = Server::query()
            ->where('uuid_short', $cleanId)
            ->orWhere('uuid', $cleanId)
            ->orWhere('uuid', 'like', "$cleanId%")
            ->with(['egg', 'node'])
            ->first();

        $lookupKey = $server ? $server->uuid : $cleanId;

        // 2. Query storage agent
        $info = $this->storageService->backupInfo($lookupKey);

        if (!$server && (!$info || empty($info['exists']))) {
            return response()->json([
                'ok' => false,
                'message' => 'No server or backup found matching ID "' . htmlspecialchars($cleanId) . '".',
            ], 404);
        }

        $fullUuid = $server ? $server->uuid : ($info['uuid'] ?? $cleanId);
        $shortId = $server ? $server->uuid_short : substr($fullUuid, 0, 8);
        $hasBackup = (bool) ($info['exists'] ?? false);

        $cleanName = $server ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $server->name) : 'server';
        $filename = "backup-{$cleanName}-{$shortId}.tar.gz";
        $directDownloadUrl = $hasBackup ? $this->storageService->archiveUrlForIdentifier($fullUuid, $filename) : null;

        return response()->json([
            'ok' => true,
            'server' => [
                'name' => $server?->name ?? 'Server (' . $shortId . ')',
                'uuid' => $fullUuid,
                'uuid_short' => $shortId,
                'egg' => $server?->egg?->name ?? 'Game Server',
                'node' => $server?->node?->name ?? 'storage',
                'has_backup' => $hasBackup,
                'bytes' => $info['bytes'] ?? null,
                'mtime' => $info['mtime'] ?? null,
                'download_url' => route('backups.portal.download', ['identifier' => $shortId]),
                'direct_download_url' => $directDownloadUrl,
            ],
        ]);
    }

    /**
     * Download the backup tar.gz archive via direct storage redirect.
     */
    public function download(Request $request, string $identifier): RedirectResponse
    {
        $safeId = preg_replace('/[^a-f0-9-]/i', '', strtolower(trim($identifier)));

        if (empty($safeId) || strlen($safeId) < 6) {
            abort(404, 'Invalid Server ID.');
        }

        $server = Server::query()
            ->where('uuid_short', $safeId)
            ->orWhere('uuid', $safeId)
            ->first();

        $lookupKey = $server ? $server->uuid : $safeId;
        $info = $this->storageService->backupInfo($lookupKey);

        if (!$info || empty($info['exists'])) {
            abort(404, 'Backup not found or still syncing on the storage server.');
        }

        $fullUuid = $info['uuid'] ?? ($server ? $server->uuid : $safeId);
        $shortId = $server ? $server->uuid_short : substr($fullUuid, 0, 8);

        $cleanName = $server ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $server->name) : 'server';
        $filename = "backup-{$cleanName}-{$shortId}.tar.gz";

        $directUrl = $this->storageService->archiveUrlForIdentifier($fullUuid, $filename);
        if (!$directUrl) {
            abort(500, 'Unable to generate storage download URL.');
        }

        return redirect()->away($directUrl);
    }
}


