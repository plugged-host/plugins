<?php

namespace PluggedHost\Servers\Filament\App\Widgets;

use App\Filament\Pages\Auth\EditProfile;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PluggedHost\Servers\Models\RoleResourceLimits;
use PluggedHost\Economy\Filament\App\Pages\ResourceStorePage;
use PluggedHost\Economy\Models\UserCredits;

class UserResourceLimitsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $user = auth()->user();
        if (!$user) {
            return [];
        }

        $resourceLimits = RoleResourceLimits::forUser($user);

        if (!$resourceLimits) {
            return [];
        }

        $userServers = $user->servers;

        $suffix = config('panel.use_binary_prefix') ? ' MiB' : ' MB';

        $usedCpu = (int) $userServers->sum('cpu');
        $usedMemory = (int) $userServers->sum('memory');
        $usedDisk = (int) $userServers->sum('disk');

        $cpu = $usedCpu . '% / ' . ($resourceLimits->cpu > 0 ? "{$resourceLimits->cpu}%" : '∞');
        $memory = $usedMemory . "{$suffix} / " . ($resourceLimits->memory > 0 ? "{$resourceLimits->memory}{$suffix}" : '∞');
        $disk = $usedDisk . "{$suffix} / " . ($resourceLimits->disk > 0 ? "{$resourceLimits->disk}{$suffix}" : '∞');

        $cpuExhausted = $resourceLimits->cpu > 0 && $usedCpu >= $resourceLimits->cpu;
        $memoryExhausted = $resourceLimits->memory > 0 && $usedMemory >= $resourceLimits->memory;
        $diskExhausted = $resourceLimits->disk > 0 && $usedDisk >= $resourceLimits->disk;
        $serverLimitExhausted = $resourceLimits->server_limit !== null && $userServers->count() >= $resourceLimits->server_limit;

        $anyExhausted = $cpuExhausted || $memoryExhausted || $diskExhausted || $serverLimitExhausted;

        // Past the limit, not merely at it. Telling someone with 20 GB allocated against
        // a 16 GB limit to "upgrade in the store" is misleading — the store cannot sell
        // them back under quota, and buying 8 GB would still leave them unable to grow.
        // These get their own message on the affected stat instead.
        $overage = $resourceLimits->getOverage($user);

        $roleName = config('plugged-host-discord.role_name', 'Discord Linked');
        $canUpgradeWithDiscord = $anyExhausted && !$user->roles()->where('name', $roleName)->exists();

        $discordProfileUrl = env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space') . '/dashboard';

        // Both the store page and the balance live in plugged-host-economy, which is a
        // separate plugin and may be uninstalled. Everything credit-shaped here is
        // therefore conditional: with the economy gone the widget still shows the three
        // resource stats, just without a balance tile or a "buy more" link.
        $storeUrl = class_exists(ResourceStorePage::class)
            ? ResourceStorePage::getUrl(panel: 'app')
            : null;

        $userCredits = class_exists(UserCredits::class) ? UserCredits::forUser($user) : null;
        $creditsBalance = $userCredits ? $userCredits->credits : 0;

        $cpuStat = Stat::make(trans('plugged-host-servers::strings.cpu'), $cpu);
        $memoryStat = Stat::make(trans('plugged-host-servers::strings.memory'), $memory);
        $diskStat = Stat::make(trans('plugged-host-servers::strings.disk'), $disk);
        $creditsStat = $userCredits === null
            ? null
            : Stat::make(trans('plugged-host-servers::strings.credits.credits'), number_format($creditsBalance))
                ->description(trans('plugged-host-servers::strings.credits.store_description'))
                ->descriptionIcon('tabler-shopping-bag', IconPosition::Before)
                ->url($storeUrl);

        $formatAmount = fn (string $resource, int $amount): string => match ($resource) {
            'cpu' => $amount . '%',
            'server_limit' => (string) $amount,
            default => $amount . $suffix,
        };

        $markOverQuota = function (Stat $stat, string $resource) use ($overage, $formatAmount): bool {
            if (($overage[$resource] ?? 0) <= 0) {
                return false;
            }

            $stat->description(trans(
                $resource === 'server_limit'
                    ? 'plugged-host-servers::strings.credits.over_quota_slots'
                    : 'plugged-host-servers::strings.credits.over_quota',
                ['amount' => $formatAmount($resource, $overage[$resource])]
            ))
                ->descriptionIcon('tabler-alert-triangle', IconPosition::Before)
                ->color('danger');

            return true;
        };

        $cpuOver = $markOverQuota($cpuStat, 'cpu');
        $memoryOver = $markOverQuota($memoryStat, 'memory');
        $diskOver = $markOverQuota($diskStat, 'disk');

        // Server slots have no stat of their own, so the notice rides on memory — the
        // same place the exhausted branch below puts it.
        if (!$cpuOver && !$memoryOver && !$diskOver) {
            $memoryOver = $markOverQuota($memoryStat, 'server_limit');
        }

        if ($anyExhausted) {
            if ($canUpgradeWithDiscord && $discordProfileUrl) {
                $reminderText = trans('plugged-host-discord::strings.need_more_stuff') ?: 'Need more stuff? Link Discord';
                $targetUrl = $discordProfileUrl;
                $targetIcon = 'tabler-brand-discord';
            } elseif ($storeUrl) {
                $reminderText = trans('plugged-host-servers::strings.credits.need_more_upgrade_store');
                $targetUrl = $storeUrl;
                $targetIcon = 'tabler-shopping-bag';
            } else {
                $reminderText = null;
                $targetUrl = null;
                $targetIcon = null;
            }

            if ($reminderText && $targetUrl) {
                if ($cpuExhausted && !$cpuOver) {
                    $cpuStat->description($reminderText)
                        ->descriptionIcon($targetIcon, IconPosition::Before)
                        ->url($targetUrl);
                }

                if ($memoryExhausted && !$memoryOver) {
                    $memoryStat->description($reminderText)
                        ->descriptionIcon($targetIcon, IconPosition::Before)
                        ->url($targetUrl);
                }

                if ($diskExhausted && !$diskOver) {
                    $diskStat->description($reminderText)
                        ->descriptionIcon($targetIcon, IconPosition::Before)
                        ->url($targetUrl);
                }

                if (!$cpuExhausted && !$memoryExhausted && !$diskExhausted && $serverLimitExhausted && !$memoryOver) {
                    $memoryStat->description($reminderText)
                        ->descriptionIcon($targetIcon, IconPosition::Before)
                        ->url($targetUrl);
                }
            }
        }

        return array_values(array_filter([
            $cpuStat,
            $memoryStat,
            $diskStat,
            $creditsStat,
        ]));
    }

    public static function canView(): bool
    {
        return RoleResourceLimits::forUser(auth()->user()) !== null;
    }
}
