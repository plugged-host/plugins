@php
    $server = \Filament\Facades\Filament::getTenant();
    if (!$server) {
        return;
    }

    $portalBaseUrl = env('BACKUPS_PORTAL_URL', 'https://backups.plugged.host');
    $portalUrl = "{$portalBaseUrl}/?server={$server->uuid_short}";

    $storageService = app(\PluggedHost\Storage\Services\StorageService::class);
    $backupInfo = null;
    $hasBackup = false;
    $formattedSize = null;
    $lastSyncTime = null;

    try {
        $backupInfo = $storageService->backupInfo($server->uuid);
        $hasBackup = (bool) ($backupInfo['exists'] ?? false);
        if ($hasBackup) {
            $bytes = (int) ($backupInfo['bytes'] ?? 0);
            if ($bytes >= 1073741824) {
                $formattedSize = round($bytes / 1073741824, 2) . ' GB';
            } elseif ($bytes >= 1048576) {
                $formattedSize = round($bytes / 1048576, 1) . ' MB';
            } else {
                $formattedSize = round($bytes / 1024, 0) . ' KB';
            }

            if (!empty($backupInfo['mtime'])) {
                try {
                    $lastSyncTime = \Carbon\Carbon::parse($backupInfo['mtime'])->diffForHumans();
                } catch (\Throwable) {
                    $lastSyncTime = null;
                }
            }
        }
    } catch (\Throwable) {
        $hasBackup = false;
    }

    $cleanName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $server->name);
    $archiveFilename = "backup-{$cleanName}-{$server->uuid_short}.tar.gz";
    $directDownloadUrl = $hasBackup ? $storageService->archiveUrlForIdentifier($server->uuid, $archiveFilename) : null;
@endphp

<div class="mb-6 rounded-2xl border border-blue-500/20 bg-gradient-to-r from-blue-950/30 via-slate-900/40 to-slate-950/60 p-5 shadow-lg backdrop-blur-sm transition-all hover:border-blue-500/35">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-4">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-blue-500/30 bg-blue-500/10 text-blue-400 shadow-inner">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                </svg>
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-bold text-white tracking-tight" style="font-family: 'Space Grotesk', sans-serif;">
                        Off-site Storage Mirror Active
                    </h3>
                    @if($hasBackup)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Synced {{ $formattedSize ? "({$formattedSize})" : '' }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-blue-500/30 bg-blue-500/10 px-2.5 py-0.5 text-xs font-semibold text-blue-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-400"></span>
                            Replication scheduled
                        </span>
                    @endif
                </div>
                <p class="mt-1 text-xs text-slate-300 sm:text-sm">
                    Your server files are continuously delta-mirrored to our independent storage cluster (<code class="rounded bg-black/40 px-1 py-0.5 font-mono text-xs text-blue-300">storage.plugged.host</code>).
                    @if($hasBackup && $lastSyncTime)
                        Last synchronized <span class="font-medium text-white">{{ $lastSyncTime }}</span>.
                    @endif
                    Safe off-site downloads are available even if the server is offline or deleted.
                </p>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2.5 pt-1 sm:pt-0">
            @if($directDownloadUrl)
                <a href="{{ $directDownloadUrl }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-white/10 hover:border-white/20">
                    <svg class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    <span>Download Snapshot</span>
                </a>
            @endif

            <a href="{{ $portalUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="inline-flex items-center gap-2 rounded-xl border border-blue-500/30 bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-md shadow-blue-600/25 transition hover:bg-blue-500 hover:shadow-blue-500/35">
                <span>Open in Backups Portal</span>
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    </div>
</div>
