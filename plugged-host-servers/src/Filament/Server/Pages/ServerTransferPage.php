<?php

namespace PluggedHost\Servers\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Models\Allocation;
use App\Models\Server;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use PluggedHost\Servers\Exceptions\TransferException;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Services\Transfers\TransferService;
use Throwable;

/**
 * Transfer: pick a location, press Move, watch it happen.
 *
 * Deliberately stays reachable while the server is transferring (unlike most server pages,
 * which hide in a conflict state): this is where the progress is. It polls only while a
 * move is running; the numbers come from the relocation row the watcher keeps up to date.
 */
class ServerTransferPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::TruckDelivery;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'transfer';

    protected string $view = 'plugged-host-servers::filament.pages.server-transfer';

    /** The location picked in the list (a location code). */
    public ?string $location = null;

    /** @var array<string, mixed> per-render memo, never sent to the browser */
    protected array $memo = [];

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (!$server instanceof Server || !$server->exists || !ServerRelocation::ready()) {
            return false;
        }

        if (!TransferService::canUse(user(), $server)) {
            return false;
        }

        return app(TransferService::class)->enabled() || ServerRelocation::activeFor($server) !== null;
    }

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-servers::strings.transfers.nav');
    }

    public function getTitle(): string
    {
        return trans('plugged-host-servers::strings.transfers.title');
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-servers::strings.transfers.subheading');
    }

    public function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public function pick(string $code): void
    {
        $offer = $this->locations()->firstWhere('code', strtolower($code));

        $this->location = is_array($offer) && $this->pickable($offer) ? $offer['code'] : null;
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->label(fn (): string => $this->location
                ? trans('plugged-host-servers::strings.transfers.move_to', ['location' => $this->service()->locationName($this->location)])
                : trans('plugged-host-servers::strings.transfers.choose'))
            ->icon(TablerIcon::TruckDelivery)
            ->button()
            ->color('primary')
            ->size('lg')
            ->extraAttributes(['class' => 'pht-move'])
            ->disabled(fn (): bool => $this->location === null || $this->blocked() !== null)
            ->requiresConfirmation()
            ->modalIcon(TablerIcon::TruckDelivery)
            ->modalHeading(fn (): string => trans('plugged-host-servers::strings.transfers.confirm_heading', [
                'location' => $this->service()->locationName($this->location),
            ]))
            ->modalDescription(fn (): string => trans('plugged-host-servers::strings.transfers.confirm_body', [
                'location' => $this->service()->locationName($this->location),
                'time' => $this->duration($this->estimate()),
            ]))
            ->modalSubmitActionLabel(trans('plugged-host-servers::strings.transfers.move'))
            ->action(function (): void {
                $user = user();

                if (!$user instanceof User) {
                    return;
                }

                try {
                    $this->service()->start($this->getServer(), $user, $this->location);

                    Notification::make()->title(trans('plugged-host-servers::strings.transfers.started'))->success()->send();
                } catch (TransferException $e) {
                    Notification::make()
                        ->title(trans('plugged-host-servers::strings.transfers.blocked.' . $e->reason, $e->params))
                        ->warning()
                        ->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(trans('plugged-host-servers::strings.transfers.reasons.internal_error'))->danger()->send();
                }

                $this->location = null;
                $this->memo = [];
            });
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(trans('plugged-host-servers::strings.transfers.cancel'))
            ->icon(TablerIcon::X)
            ->button()
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalIcon(TablerIcon::AlertTriangle)
            ->modalHeading(trans('plugged-host-servers::strings.transfers.cancel_heading'))
            ->modalDescription(trans('plugged-host-servers::strings.transfers.cancel_body'))
            ->visible(fn (): bool => (bool) $this->active()?->isCancellable())
            ->action(function (): void {
                $relocation = $this->active();
                $user = user();

                abort_unless(TransferService::canUse($user, $this->getServer()), 403);

                if ($relocation === null || !$user instanceof User || !$this->service()->cancel($relocation, $user)) {
                    Notification::make()->title(trans('plugged-host-servers::strings.transfers.cancel_failed'))->warning()->send();

                    return;
                }

                Notification::make()->title(trans('plugged-host-servers::strings.transfers.cancelling'))->send();
                $this->memo = [];
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $server = $this->getServer();
        $active = $this->active();

        $data = [
            'server' => $server,
            'active' => $active,
            'recent' => $active === null ? $this->recent() : null,
            'history' => $this->history(),
        ];

        if ($active === null) {
            $data += [
                'locations' => $this->locations(),
                'currentCode' => $this->service()->planner()->locationOf($server->node),
                'address' => $this->address($server),
                'dataBytes' => $this->dataBytes(),
                'backups' => $this->backupSummary(),
                'estimate' => $this->estimate(),
                'blocked' => $this->blocked(),
            ];
        }

        return $data;
    }

    // ---------------------------------------------------------------------------------
    // Helpers the view calls
    // ---------------------------------------------------------------------------------

    public function service(): TransferService
    {
        return app(TransferService::class);
    }

    public function flag(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return LocationCatalog::flagSvg(app(LocationCatalog::class)->resolve($code)[0]);
    }

    public function tier(?string $code): string
    {
        return $code === null ? LocationCatalog::FREE : app(LocationCatalog::class)->resolve($code)[1];
    }

    public function bytes(int|float $bytes): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1000 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return ($i === 0 || $bytes >= 100 ? number_format($bytes) : number_format($bytes, 1)) . ' ' . $units[$i];
    }

    public function duration(int $seconds): string
    {
        return CarbonInterval::seconds(max(1, $seconds))->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    /**
     * Timeline entries, newest first, with their parameters made readable.
     *
     * @return list<array{at: string, text: string, level: string}>
     */
    public function events(ServerRelocation $relocation): array
    {
        return $this->service()->timeline($relocation, user()?->timezone);
    }

    /**
     * The stepper: each step is done, current or todo. Backups only show when there are some.
     *
     * @return list<array{key: string, icon: string, status: string}>
     */
    public function steps(ServerRelocation $relocation): array
    {
        $steps = [
            'prepare' => TablerIcon::ListCheck,
            'stop' => TablerIcon::PlayerStop,
            'copy' => TablerIcon::Files,
            'backups' => TablerIcon::Archive,
            'verify' => TablerIcon::ShieldCheck,
            'start' => TablerIcon::PlayerPlay,
        ];

        if ($relocation->backups_total === 0 && (array) $relocation->info('backups', []) === []) {
            unset($steps['backups']);
        }

        $current = match (true) {
            in_array($relocation->state, [ServerRelocation::PREPARING, ServerRelocation::RETRYING], true) => 'prepare',
            $relocation->state === ServerRelocation::STOPPING => 'stop',
            $relocation->state === ServerRelocation::COPYING && $relocation->phase === ServerRelocation::PHASE_BACKUPS && isset($steps['backups']) => 'backups',
            $relocation->state === ServerRelocation::COPYING && $relocation->phase === ServerRelocation::PHASE_HANDOFF => 'verify',
            $relocation->state === ServerRelocation::COPYING => 'copy',
            $relocation->state === ServerRelocation::FINALIZING => 'verify',
            $relocation->state === ServerRelocation::STARTING => 'start',
            $relocation->state === ServerRelocation::COMPLETED => 'done',
            default => 'prepare',
        };

        $keys = array_keys($steps);
        $index = $current === 'done' ? count($keys) : (int) array_search($current, $keys, true);
        $out = [];

        foreach ($keys as $i => $key) {
            $out[] = [
                'key' => $key,
                'icon' => $steps[$key]->value,
                'status' => $i < $index ? 'done' : ($i === $index ? 'current' : 'todo'),
            ];
        }

        return $out;
    }

    /** The headline for a move's state. */
    public function headline(ServerRelocation $relocation): string
    {
        return trans('plugged-host-servers::strings.transfers.state.' . $relocation->headlineKey());
    }

    public function pickable(array $offer): bool
    {
        return ($offer['available'] ?? false) && ($offer['unlocked'] ?? true);
    }

    // ---------------------------------------------------------------------------------

    protected function active(): ?ServerRelocation
    {
        if (!array_key_exists('active', $this->memo)) {
            $this->memo['active'] = ServerRelocation::activeFor($this->getServer());
        }

        return $this->memo['active'];
    }

    /** A move that finished in the last ten minutes, to show its outcome on top. */
    protected function recent(): ?ServerRelocation
    {
        return ServerRelocation::query()
            ->where('server_id', $this->getServer()->getKey())
            ->where('finished_at', '>', now()->subMinutes(10))
            ->latest('id')
            ->first();
    }

    /** @return Collection<int, ServerRelocation> */
    protected function history(): Collection
    {
        return ServerRelocation::query()
            ->where('server_id', $this->getServer()->getKey())
            ->whereNotIn('state', ServerRelocation::ACTIVE)
            ->latest('id')
            ->limit(6)
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function locations(): Collection
    {
        return $this->memo['locations'] ??= $this->service()->planner()->locations($this->getServer());
    }

    /** @return null|array{0: string, 1: array<string, scalar>} */
    protected function blocked(): ?array
    {
        if (!array_key_exists('blocked', $this->memo)) {
            $this->memo['blocked'] = $this->service()->blockedReason($this->getServer(), user());
        }

        return $this->memo['blocked'];
    }

    protected function address(Server $server): ?string
    {
        $allocation = Allocation::withoutGlobalScopes()->find($server->allocation_id);

        return $allocation instanceof Allocation
            ? ($allocation->ip_alias ?: $server->node?->fqdn ?: $allocation->ip) . ':' . $allocation->port
            : null;
    }

    protected function dataBytes(): int
    {
        try {
            return (int) ($this->getServer()->retrieveResources()['disk_bytes'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{count: int, bytes: int} */
    protected function backupSummary(): array
    {
        return $this->memo['backups'] ??= [
            'count' => (int) $this->getServer()->backups()->where('is_successful', true)->count(),
            'bytes' => (int) $this->getServer()->backups()->where('is_successful', true)->sum('bytes'),
        ];
    }

    /**
     * Seconds offline: data and backups at the median speed of recent moves (40 MB/s until
     * there are any), plus the stop, the check on the new node and the start.
     */
    protected function estimate(): int
    {
        return $this->memo['estimate'] ??= (function (): int {
            $rates = ServerRelocation::query()
                ->where('state', ServerRelocation::COMPLETED)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(function (ServerRelocation $relocation): ?float {
                    $seconds = (int) $relocation->info('copied_at', 0) - (int) $relocation->info('launched_at', 0);
                    $bytes = $relocation->bytes_total + $relocation->backup_bytes;

                    return $seconds > 0 && $bytes > 0 && $relocation->info('launched_at') ? $bytes / $seconds : null;
                })
                ->filter();

            $rate = $rates->isNotEmpty() ? (float) $rates->median() : 40 * 1024 * 1024;
            $bytes = $this->dataBytes() + $this->backupSummary()['bytes'];

            return (int) ceil($bytes / max($rate, 1024 * 1024)) + 45;
        })();
    }
}
