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
    public function index(Request $request): View
    {
        $user = auth()->user();
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

                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'uuid' => $server->uuid,
                    'uuid_short' => $server->uuid_short,
                    'egg' => $server->egg?->name ?? 'Game Server',
                    'node' => $server->node?->name ?? 'Node',
                    'has_backup' => (bool) ($info['exists'] ?? false),
                    'bytes' => $info['bytes'] ?? null,
                    'mtime' => $info['mtime'] ?? null,
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
     * Initiate Discord OAuth login.
     */
    public function authRedirect(Request $request): RedirectResponse
    {
        // Store intended destination back to backups portal
        $request->session()->put('url.intended', 'https://backups.plugged.host/');

        // If Discord OAuth driver is configured in Socialite, redirect with appropriate callback
        try {
            return Socialite::driver('discord')
                ->redirectUrl('https://backups.plugged.host/auth/discord/callback')
                ->redirect();
        } catch (\Throwable) {
            // Fallback: route via panel OAuth flow
            return redirect('https://panel.plugged.host/auth/oauth/redirect/discord');
        }
    }

    /**
     * Handle Discord OAuth callback directly on backups portal.
     */
    public function authCallback(Request $request): RedirectResponse
    {
        try {
            $oauthUser = Socialite::driver('discord')
                ->redirectUrl('https://backups.plugged.host/auth/discord/callback')
                ->user();

            $discordId = (string) $oauthUser->getId();

            // Find matching user from discord_links or user json oauth
            $userId = null;
            if (class_exists(DiscordLink::class)) {
                $link = DiscordLink::where('discord_id', $discordId)->first();
                if ($link) {
                    $userId = $link->user_id;
                }
            }

            if (!$userId) {
                $user = \App\Models\User::whereJsonContains('oauth->discord', $discordId)->first();
                if ($user) {
                    $userId = $user->id;
                }
            }

            if (!$userId && $oauthUser->getEmail()) {
                $user = \App\Models\User::where('email', $oauthUser->getEmail())->first();
                if ($user) {
                    $userId = $user->id;
                }
            }

            if ($userId) {
                auth()->guard()->loginUsingId($userId, true);
                return redirect('/');
            }

            return redirect('/?error=' . urlencode('No linked plugged.host account found for this Discord user. Please login to panel.plugged.host first.'));
        } catch (\Throwable $e) {
            return redirect('/?error=' . urlencode('Discord login failed: ' . $e->getMessage()));
        }
    }

    /**
     * Log out the current user session.
     */
    public function logout(Request $request): RedirectResponse
    {
        auth()->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
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
            ],
        ]);
    }

    /**
     * Download the backup tar.gz archive via X-Accel-Redirect.
     */
    public function download(Request $request, string $identifier): Response
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
            abort(404, 'Backup not found on the storage server.');
        }

        $fullUuid = $info['uuid'] ?? ($server ? $server->uuid : $safeId);
        $shortId = $server ? $server->uuid_short : substr($fullUuid, 0, 8);

        $internalUri = $this->storageService->internalArchiveUri($fullUuid);
        if (!$internalUri) {
            abort(500, 'Unable to generate storage download URI.');
        }

        $cleanName = $server ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $server->name) : 'server';
        $filename = "backup-{$cleanName}-{$shortId}.tar.gz";

        return response('', 200, [
            'X-Accel-Redirect' => $internalUri,
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}


