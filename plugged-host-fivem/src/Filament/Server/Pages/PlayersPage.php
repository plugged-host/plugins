<?php

namespace PluggedHost\FiveM\Filament\Server\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Actions\Action;
use PluggedHost\FiveM\Services\LivePlayers;

/**
 * Who is on the server, live: FXServer's /players.json, refreshed every few seconds while the
 * page is open (wire:poll in the view), searchable by name, server id or identifier.
 *
 * Read from the game port the server browser reads, so it needs no console access and works
 * the same under txAdmin.
 */
class PlayersPage extends FiveMPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Users;

    protected static ?int $navigationSort = 14;

    // Minecraft's players page owns /players; the two never show on the same server, but they
    // share the panel's route table.
    protected static ?string $slug = 'player-list';

    protected string $view = 'plugged-host-fivem::filament.pages.players';

    public string $search = '';

    /**
     * The last answer from the server (LivePlayers::fetch()).
     *
     * @var array<string, mixed>
     */
    public array $snapshot = [];

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-fivem::strings.players.nav_label');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return trans('plugged-host-fivem::strings.players.subheading', ['seconds' => $this->pollSeconds()]);
    }

    public function mount(): void
    {
        $this->refreshPlayers();
    }

    public function refreshPlayers(): void
    {
        $this->snapshot = app(LivePlayers::class)->fetch($this->getServer());
    }

    public function pollSeconds(): int
    {
        return max(5, (int) config('plugged-host-fivem.players_poll', 12));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function players(): array
    {
        $players = (array) ($this->snapshot['players'] ?? []);
        $search = mb_strtolower(trim($this->search));

        if ($search === '') {
            return $players;
        }

        return array_values(array_filter($players, function (array $player) use ($search): bool {
            $haystack = mb_strtolower($player['name'] . ' ' . $player['id'] . ' ' . implode(' ', (array) $player['identifiers']));

            return str_contains($haystack, ltrim($search, '#'));
        }));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('plugged-host-fivem::strings.players.refresh'))
                ->icon(TablerIcon::Refresh)
                ->color('gray')
                ->action(fn () => $this->refreshPlayers()),
        ];
    }
}
