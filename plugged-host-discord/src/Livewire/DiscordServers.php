<?php

namespace PluggedHost\Discord\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Discord\Services\NoprofitDiscordStatus;
use Throwable;

/**
 * The "Discord servers" tab on the profile page: the Discord servers to join for more memory
 * (plugged.host, plugged.cloud, noprofit.space), which ones the account is in and what each adds
 * to its noprofit.space pool. noprofit.space checks membership and decides the memory; this
 * shows its answer (NoprofitDiscordStatus), and "Check again" asks it to look right away (at
 * most once a minute).
 *
 * The servers are one list of rows, so other servers can be added to it later.
 */
class DiscordServers extends Component
{
    public const TAB_ID = 'discord-servers';

    /**
     * The tab's address (the profile page opened on it), or null while noprofit.space is not
     * set up and the tab is hidden.
     */
    public static function url(): ?string
    {
        if (!NoprofitDiscordStatus::available()) {
            return null;
        }

        try {
            $profile = Filament::getPanel('app')->getProfileUrl();
        } catch (Throwable) {
            return null;
        }

        return $profile !== null ? $profile . '?tab=' . self::TAB_ID : null;
    }

    public function checkNow(NoprofitDiscordStatus $noprofit): void
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

        $status = $noprofit->forUser($user, check: true);

        if ($status === null) {
            Notification::make()->title(trans('plugged-host-discord::strings.servers.check_failed'))->danger()->send();

            return;
        }

        Notification::make()
            ->title(trans('plugged-host-discord::strings.servers.checked', ['amount' => $this->gigabytes((int) $status['memory_bonus'])]))
            ->success()
            ->send();
    }

    public function render(NoprofitDiscordStatus $noprofit): View
    {
        $user = auth()->user();
        $status = $user instanceof User ? $noprofit->forUser($user) : null;
        $servers = (array) ($status['servers'] ?? []);

        return view('plugged-host-discord::livewire.servers', [
            'status' => $status,
            'linkUrl' => class_exists(NoprofitLink::class) ? NoprofitLink::accountsUrl('/settings') : null,
            'servers' => array_map(fn (array $server): array => [
                'name' => (string) $server['name'],
                'joined' => (bool) $server['joined'],
                'boosts' => (int) $server['boosts'],
                'invite_url' => (string) $server['invite_url'],
                'gain' => $this->gigabytes((int) ($status['memory_per_server'] ?? 0)),
            ], $servers),
            'joined' => count(array_filter($servers, fn (array $server): bool => (bool) $server['joined'])),
            'bonus' => $this->gigabytes((int) ($status['memory_bonus'] ?? 0)),
            'maxBonus' => $this->gigabytes((int) ($status['max_memory_bonus'] ?? 0)),
            'perServer' => $this->gigabytes((int) ($status['memory_per_server'] ?? 0)),
            'allServers' => $this->gigabytes((int) ($status['memory_all_servers'] ?? 0)),
        ]);
    }

    private function gigabytes(int $mebibytes): string
    {
        return rtrim(rtrim(number_format($mebibytes / 1024, 1), '0'), '.') . ' GB';
    }
}
