@php
    use Illuminate\Support\Number;

    // Tokens and credits can link through to their respective store tab if plugged-host-store
    // is present on the panel. If it isn't (or if the user cannot view the store), both render
    // inertly: losing the link doesn't stop the user from caring about what they have left.
    $storeUrl = null;
    if (class_exists(\PluggedHost\Store\Filament\Pages\Store::class)
        && auth()->check()
        && \PluggedHost\Store\Filament\Pages\Store::canAccess()) {
        try {
            $storeUrl = \PluggedHost\Store\Filament\Pages\Store::getUrl();
        } catch (\Throwable) {
            $storeUrl = null;
        }
    }

    // A linked balance wraps in an <a> with Filament's wire:navigate so click-through doesn't
    // dump the user's active page state; an unlinked balance wraps in a <div>. The markup is
    // worth showing either way, but it must not look clickable if nothing happens on click.
    $tag = $storeUrl ? 'a' : 'div';
    $locale = app()->getLocale();
@endphp

<div class="phv2-balance" wire:poll.60s>
    @if (!empty($resources))
        @php
            $formatSize = function (int $mb): string {
                if ($mb >= 1024) {
                    return round($mb / 1024, 1) . ' GB';
                }
                return $mb . ' MB';
            };

            $hasCpuLimit = ($resources['cpu_limit'] ?? 0) > 0;
            $hasMemLimit = ($resources['memory_limit'] ?? 0) > 0;
            $hasDiskLimit = ($resources['disk_limit'] ?? 0) > 0;

            $cpuLimit = $hasCpuLimit ? $resources['cpu_limit'] . '%' : '∞';
            $memLimit = $hasMemLimit ? $formatSize($resources['memory_limit']) : '∞';
            $diskLimit = $hasDiskLimit ? $formatSize($resources['disk_limit']) : '∞';

            $cpuPercent = $hasCpuLimit ? min(100, max(0, round((($resources['cpu_used'] ?? 0) / $resources['cpu_limit']) * 100))) : 0;
            $memPercent = $hasMemLimit ? min(100, max(0, round((($resources['memory_used'] ?? 0) / $resources['memory_limit']) * 100))) : 0;
            $diskPercent = $hasDiskLimit ? min(100, max(0, round((($resources['disk_used'] ?? 0) / $resources['disk_limit']) * 100))) : 0;

            $quotaTitle = "CPU: " . ($resources['cpu_used'] ?? 0) . "% / {$cpuLimit} | RAM: " . $formatSize($resources['memory_used'] ?? 0) . " / {$memLimit} | Disk: " . $formatSize($resources['disk_used'] ?? 0) . " / {$diskLimit}";
        @endphp
        <div class="phv2-resources-card" title="{{ $quotaTitle }}">
            <div class="phv2-resources-header">
                <span class="phv2-resources-title">Resources</span>
            </div>

            <div class="phv2-resource-rows">
                <div class="phv2-resource-row">
                    <div class="phv2-resource-info">
                        <span class="phv2-resource-name">CPU</span>
                        <span class="phv2-resource-metrics">
                            <span class="phv2-resource-value">{{ $resources['cpu_used'] ?? 0 }}%</span>
                            <span class="phv2-resource-limit">/ {{ $cpuLimit }}</span>
                        </span>
                    </div>
                    @if ($hasCpuLimit)
                        <div class="phv2-resource-track">
                            <div class="phv2-resource-bar" style="width: {{ $cpuPercent }}%"></div>
                        </div>
                    @endif
                </div>

                <div class="phv2-resource-row">
                    <div class="phv2-resource-info">
                        <span class="phv2-resource-name">RAM</span>
                        <span class="phv2-resource-metrics">
                            <span class="phv2-resource-value">{{ $formatSize($resources['memory_used'] ?? 0) }}</span>
                            <span class="phv2-resource-limit">/ {{ $memLimit }}</span>
                        </span>
                    </div>
                    @if ($hasMemLimit)
                        <div class="phv2-resource-track">
                            <div class="phv2-resource-bar" style="width: {{ $memPercent }}%"></div>
                        </div>
                    @endif
                </div>

                <div class="phv2-resource-row">
                    <div class="phv2-resource-info">
                        <span class="phv2-resource-name">Disk</span>
                        <span class="phv2-resource-metrics">
                            <span class="phv2-resource-value">{{ $formatSize($resources['disk_used'] ?? 0) }}</span>
                            <span class="phv2-resource-limit">/ {{ $diskLimit }}</span>
                        </span>
                    </div>
                    @if ($hasDiskLimit)
                        <div class="phv2-resource-track">
                            <div class="phv2-resource-bar" style="width: {{ $diskPercent }}%"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($tokens !== null || $credits !== null)
    <{{ $tag }}
        @if ($storeUrl)
            href="{{ $storeUrl }}"
            wire:navigate
            title="{{ trans('plugged-host-theme-v2::strings.balance.go_to_store') }}"
        @endif
        class="phv2-balance-pills{{ $storeUrl ? ' phv2-balance-pills-linked' : '' }}"
    >
        @if ($tokens !== null)
            <span class="phv2-balance-item">
                <span class="phv2-balance-value">{{ Number::format($tokens, locale: $locale) }}</span>

                <span class="phv2-balance-label">{{ trans('plugged-host-theme-v2::strings.balance.tokens') }}</span>
            </span>
        @endif

        @if ($credits !== null)
            <span class="phv2-balance-item">
                <span class="phv2-balance-value">{{ Number::format($credits, locale: $locale) }}</span>

                <span class="phv2-balance-label">{{ trans('plugged-host-theme-v2::strings.balance.credits') }}</span>
            </span>
        @endif
    </{{ $tag }}>
    @endif
</div>
