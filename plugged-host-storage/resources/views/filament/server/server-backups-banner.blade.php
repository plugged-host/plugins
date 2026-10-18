@php
    $server = \Filament\Facades\Filament::getTenant();
    if (!$server) {
        return;
    }

    $portalUrl = \PluggedHost\Storage\Support\BackupsPortal::forServer($server->uuid_short);

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
    // The off-site copy holds every server file, so it needs the same permission as downloading a backup.
    $canDownload = (bool) user()?->can(\App\Enums\SubuserPermission::BackupDownload, $server);
    $directDownloadUrl = $hasBackup && $canDownload ? $storageService->archiveUrlForIdentifier($server->uuid, $archiveFilename, $backupInfo['box'] ?? null) : null;
@endphp

{{--
    Off-site copy of this server, above the backups table.

    A plain card in the panel's one card style (`.ph-card` / `.ph-row`, plugged-host-core's
    plugged-host.css): the explanation on the left, a secondary download and a text link to the
    portal on the right. The page's primary action is the backups table's own Create button,
    so nothing here is filled.
--}}
<div class="ph-card mb-6">
    <div class="ph-row">
        <div class="ph-row-text">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="ph-heading">Off-site copy</h3>

                @if($hasBackup)
                    <x-filament::badge color="success">Available</x-filament::badge>
                @elseif($backupInfo === null)
                    <x-filament::badge color="gray">Unreachable right now</x-filament::badge>
                @else
                    <x-filament::badge color="gray">Waiting for first copy</x-filament::badge>
                @endif
            </div>

            <p class="ph-row-body">
                Your server files are also copied to a separate storage server, so you can download them even if this server is offline or deleted.
                @if($hasBackup && $lastSyncTime)
                    Last copied <strong>{{ $lastSyncTime }}</strong>@if($formattedSize) ({{ $formattedSize }})@endif.
                @elseif($hasBackup && $formattedSize)
                    Size: <strong>{{ $formattedSize }}</strong>.
                @endif
            </p>
        </div>

        <div class="ph-row-actions">
            @if($directDownloadUrl)
                <x-filament::button
                    tag="a"
                    :href="$directDownloadUrl"
                    color="gray"
                    size="sm"
                >
                    Download
                </x-filament::button>
            @endif

            <x-filament::link
                :href="$portalUrl"
                target="_blank"
                rel="noopener noreferrer"
                size="sm"
            >
                Open backups portal
            </x-filament::link>
        </div>
    </div>
</div>
