<?php

namespace PluggedHost\Discord\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use PluggedHost\Core\Contracts\AccountPortal;
use PluggedHost\Discord\Contracts\DiscordServersStatus;
use Throwable;

/**
 * The "Discord servers" tab on the profile page: the Discord servers to join for more resources
 * (plugged.host, plugged.cloud, noprofit.space, and partner servers), which ones the account is
 * in, what each adds to its noprofit.space pool and the credits some pay once for joining.
 * noprofit.space checks membership and decides all of it; this shows its answer
 * (DiscordServersStatus, bound by plugged-host-noprofit), and "Check again" asks it to look
 * right away (at most once a minute). Without a status source the tab is hidden.
 *
 * Our own servers come first, then the partner servers noprofit.space lists in sold slots
 * (Admin > Discord servers there).
 */
class DiscordServers extends Component
{
    public const TAB_ID = 'discord-servers';

    /**
     * The tab's address (the profile page opened on it), or null while no status source
     * (noprofit.space) is set up and the tab is hidden.
     */
    public static function url(): ?string
    {
        if (!app(DiscordServersStatus::class)->available()) {
            return null;
        }

        try {
            $profile = Filament::getPanel('app')->getProfileUrl();
        } catch (Throwable) {
            return null;
        }

        return $profile !== null ? $profile . '?tab=' . self::TAB_ID : null;
    }

    public function checkNow(DiscordServersStatus $source): void
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return;
        }

        $key = 'plugged-host-discord:servers-check:' . $user->getKey();

        if (RateLimiter::tooManyAttempts($key, 1)) {
            Notification::make()
                ->title(trans('plugged-host-discord::strings.rewards.check_wait', ['seconds' => RateLimiter::availableIn($key)]))
                ->warning()
                ->send();

            return;
        }

        RateLimiter::hit($key, 60);

        $status = $source->forUser($user, check: true);

        if ($status === null) {
            Notification::make()->title(trans('plugged-host-discord::strings.servers.check_failed'))->danger()->send();

            return;
        }

        Notification::make()
            ->title($status['paid'] > 0
                ? trans('plugged-host-discord::strings.servers.checked_paid', ['credits' => number_format((int) $status['paid'])])
                : trans('plugged-host-discord::strings.servers.checked'))
            ->success()
            ->send();
    }

    public function render(DiscordServersStatus $source): View
    {
        $user = auth()->user();
        $status = $user instanceof User ? $source->forUser($user) : null;
        $servers = array_map(fn (array $server): array => [
            'name' => (string) $server['name'],
            'joined' => (bool) $server['joined'],
            'boosts' => (int) $server['boosts'],
            'invite_url' => (string) $server['invite_url'],
            'own' => (bool) ($server['own'] ?? true),
            'gain' => $this->gains($server),
            'credits' => (int) ($server['credits'] ?? 0),
            'credits_paid' => (bool) ($server['credits_paid'] ?? false),
        ], (array) ($status['servers'] ?? []));

        return view('plugged-host-discord::livewire.servers', [
            'status' => $status,
            'linkUrl' => $source->linkUrl() ?? app(AccountPortal::class)->discordLinkUrl(),
            'linkElsewhere' => app(AccountPortal::class)->external() ? app(AccountPortal::class)->name() : null,
            'own' => array_values(array_filter($servers, fn (array $server): bool => $server['own'])),
            'partners' => array_values(array_filter($servers, fn (array $server): bool => !$server['own'])),
            'joined' => count(array_filter($servers, fn (array $server): bool => $server['joined'])),
            'total' => count($servers),
            'bonus' => $this->gains((array) ($status['bonus'] ?? ['memory' => $status['memory_bonus'] ?? 0])),
            'maxBonus' => $this->gains((array) ($status['max_bonus'] ?? ['memory' => $status['max_memory_bonus'] ?? 0])),
            'allServers' => $this->gigabytes((int) ($status['memory_all_servers'] ?? 0)),
        ]);
    }

    /**
     * What a server (or all of them) adds to the pool: "+1 GB RAM, +50% CPU". Empty for nothing.
     *
     * @param  array<string, mixed>  $resources
     */
    private function gains(array $resources): string
    {
        return implode(', ', array_filter([
            ($resources['memory'] ?? 0) > 0 ? trans('plugged-host-discord::strings.servers.gain_memory', ['amount' => $this->gigabytes((int) $resources['memory'])]) : null,
            ($resources['cpu'] ?? 0) > 0 ? trans('plugged-host-discord::strings.servers.gain_cpu', ['amount' => (int) $resources['cpu']]) : null,
            ($resources['disk'] ?? 0) > 0 ? trans('plugged-host-discord::strings.servers.gain_disk', ['amount' => $this->gigabytes((int) $resources['disk'])]) : null,
        ]));
    }

    private function gigabytes(int $mebibytes): string
    {
        return rtrim(rtrim(number_format($mebibytes / 1024, 1), '0'), '.') . ' GB';
    }
}
