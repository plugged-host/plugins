<?php

namespace PluggedHost\ThemeV2\Livewire;

use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Livewire\ServerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The server card on the server list, drawn by Theme v2.
 *
 * Upstream's card (livewire/server-entry.blade.php) lays its three usage bars and the address
 * out as one fixed row, so in a card narrower than about 30rem -- two columns on a mid-size
 * window, any tablet -- every label wraps onto three lines and the address is cut off. This is
 * the same component (registered under upstream's `server-entry` name in
 * PluggedHostThemeV2ServiceProvider, so the list, the lazy loading, the power actions and
 * plugged-host-ads' PowerActionInterceptor are all unchanged) with its own view, laid out by a
 * container query on the card itself: one line per resource when the card is narrow, three
 * compact columns with the address underneath when it is medium, four columns when it is wide.
 * The list's grid (theme.css) fits as many cards per row as the window has room for.
 *
 * Upstream's files are untouched; only which class the name resolves to changes.
 */
class ThemeV2ServerEntry extends ServerEntry
{
    public function render(): View
    {
        return view('plugged-host-theme-v2::server-entry', $this->card(loading: false) + ['component' => $this]);
    }

    public function placeholder(): View
    {
        return view('plugged-host-theme-v2::server-entry', $this->card(loading: true) + ['component' => $this]);
    }

    /**
     * Everything the view shows, worked out here so the view only lays it out.
     *
     * @return array<string, mixed>
     */
    protected function card(bool $loading): array
    {
        $server = $this->server;
        $resources = $loading ? [] : $this->resources();

        return [
            'server' => $server,
            'loading' => $loading,
            'icon' => $server->icon ?: $server->egg?->icon,
            'color' => $loading ? 'gray' : ($server->condition->getColor() ?: 'gray'),
            'status' => $loading ? trans('server/dashboard.loading') : $this->status($resources),
            'description' => filled($server->description) ? Str::limit((string) $server->description, 80, preserveWords: true) : null,
            'address' => $server->allocation?->address,
            'actions' => $loading ? null : ListServers::getPowerActionGroup()->record($server),
            'meters' => [
                $this->meter('cpu', (int) round((float) ($resources['cpu_absolute'] ?? 0)), (int) $server->cpu, 'percent'),
                $this->meter('memory', $this->megabytes($resources['memory_bytes'] ?? 0), (int) $server->memory, 'size'),
                $this->meter('disk', $this->megabytes($resources['disk_bytes'] ?? 0), (int) $server->disk, 'size'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resources(): array
    {
        try {
            return (array) $this->server->retrieveResources();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * "Running · 2h 14m", "Offline", "Installing".
     *
     * @param  array<string, mixed>  $resources
     */
    protected function status(array $resources): string
    {
        $label = (string) $this->server->condition->getLabel();
        $uptime = (int) ($resources['uptime'] ?? 0);

        if ($this->server->status !== null || $uptime <= 0) {
            return $label;
        }

        return $label . ' · ' . now()->subMillis($uptime)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2);
    }

    /**
     * One usage row: "12 / 100%" or "1.2 / 2 GB", its bar width and how full it is.
     *
     * @return array{key: string, label: string, used: string, limit: string, percent: int, level: string}
     */
    protected function meter(string $key, int $used, int $limit, string $kind): array
    {
        $percent = $limit > 0 ? (int) min(100, round($used / $limit * 100)) : 0;

        $level = match (true) {
            $limit > 0 && $used / $limit >= ListServers::DANGER_THRESHOLD => 'danger',
            $limit > 0 && $used / $limit >= ListServers::WARNING_THRESHOLD => 'warning',
            default => 'normal',
        };

        [$usedText, $limitText] = $kind === 'percent'
            ? [(string) $used, $limit > 0 ? $limit . '%' : '∞']
            : $this->sizes($used, $limit);

        return [
            'key' => $key,
            'label' => trans("plugged-host-theme-v2::strings.usage.{$key}"),
            'used' => $usedText,
            'limit' => $limitText,
            'percent' => $percent,
            'level' => $level,
        ];
    }

    /**
     * "1.2 / 2 GB": the unit once when both sides share it, as the sidebar does. MB here are the
     * panel's (1 GB = 1024 MB), the same unit the plans and the Resource Store use.
     *
     * @return array{0: string, 1: string}
     */
    protected function sizes(int $used, int $limit): array
    {
        $binary = (bool) config('panel.use_binary_prefix');
        $format = fn (int $mb): array => $mb >= 1024
            ? [rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.'), $binary ? 'GiB' : 'GB']
            : [(string) $mb, $binary ? 'MiB' : 'MB'];

        [$usedValue, $usedUnit] = $format($used);

        if ($limit <= 0) {
            return ["{$usedValue} {$usedUnit}", '∞'];
        }

        [$limitValue, $limitUnit] = $format($limit);

        return [
            $usedUnit === $limitUnit || $used === 0 ? $usedValue : "{$usedValue} {$usedUnit}",
            "{$limitValue} {$limitUnit}",
        ];
    }

    protected function megabytes(mixed $bytes): int
    {
        return (int) round(max(0, (int) $bytes) / 1048576);
    }
}
