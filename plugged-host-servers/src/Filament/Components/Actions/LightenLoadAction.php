<?php

namespace PluggedHost\Servers\Filament\Components\Actions;

use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Models\Node;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\LoadLightener;

/**
 * "Lighten the load" on the admin node page: move some of the node's servers to another node
 * the admin picks. The admin chooses stopped servers (nobody is kicked) or the busiest running
 * ones, and how many; the list shows exactly which will go and can be trimmed. The moves are
 * queued and run a couple at a time (LoadLightener); the node's Transfers tab follows them.
 */
class LightenLoadAction extends Action
{
    private const DEFAULT_COUNT = 5;

    public static function getDefaultName(): ?string
    {
        return 'ph_lighten';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn () => trans('plugged-host-servers::strings.transfers.lighten.action'));
        $this->tooltip(fn (Node $record): string => $record->servers()->exists()
            ? trans('plugged-host-servers::strings.transfers.lighten.action')
            : trans('plugged-host-servers::strings.transfers.lighten.no_servers'));
        $this->icon(TablerIcon::Feather);
        $this->color('gray');

        $this->visible(fn (?Node $record): bool => $record instanceof Node
            && ServerRelocation::ready()
            && (bool) user()?->can('update', $record));

        $this->disabled(fn (Node $record): bool => !$record->servers()->exists());

        $this->modalHeading(fn (Node $record) => trans('plugged-host-servers::strings.transfers.lighten.heading', ['node' => $record->name]));
        $this->modalSubmitActionLabel(fn () => trans('plugged-host-servers::strings.transfers.lighten.submit'));
        $this->modalWidth('xl');

        $this->schema(fn (Node $record): array => [
            Select::make('to')
                ->label(trans('plugged-host-servers::strings.transfers.lighten.to'))
                ->helperText(trans('plugged-host-servers::strings.transfers.lighten.to_hint'))
                ->required()
                ->live()
                ->native(false)
                ->searchable()
                ->options(fn (): array => $this->destinations($record)['options'])
                ->disableOptionWhen(fn (string $value): bool => in_array($value, $this->destinations($record)['disabled'], true)),
            ToggleButtons::make('pick')
                ->label(trans('plugged-host-servers::strings.transfers.lighten.pick'))
                ->options([
                    LoadLightener::PICK_STOPPED => trans('plugged-host-servers::strings.transfers.lighten.picks.stopped'),
                    LoadLightener::PICK_BUSIEST => trans('plugged-host-servers::strings.transfers.lighten.picks.busiest'),
                ])
                ->helperText(fn (Get $get): string => trans('plugged-host-servers::strings.transfers.lighten.pick_hints.' . ($get('pick') ?: LoadLightener::PICK_STOPPED)))
                ->inline()
                ->required()
                ->default(LoadLightener::PICK_STOPPED)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $this->refill($record, $get, $set)),
            TextInput::make('count')
                ->label(trans('plugged-host-servers::strings.transfers.lighten.count'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(fn (Get $get): int => max(1, $this->candidates($record, (string) $get('pick'))?->count() ?? 1))
                ->default(fn (): int => $this->defaultCount($record))
                ->hidden(fn (Get $get): bool => ($this->candidates($record, (string) $get('pick'))?->count() ?? 0) === 0)
                ->live(debounce: 600)
                ->afterStateUpdated(fn (Get $get, Set $set) => $this->refill($record, $get, $set)),
            CheckboxList::make('servers')
                ->label(trans('plugged-host-servers::strings.transfers.lighten.servers'))
                ->helperText(trans('plugged-host-servers::strings.transfers.lighten.servers_hint'))
                ->options(fn (Get $get): array => $this->picked($record, $get)->mapWithKeys(fn (array $row) => [$row['id'] => $row['name']])->all())
                ->descriptions(fn (Get $get): array => $this->picked($record, $get)->mapWithKeys(fn (array $row) => [$row['id'] => $this->line($row)])->all())
                ->default(fn (): array => ($this->candidates($record, LoadLightener::PICK_STOPPED) ?? collect())->take($this->defaultCount($record))->pluck('id')->map(fn ($id) => (string) $id)->all())
                ->hidden(fn (Get $get): bool => $this->picked($record, $get)->isEmpty())
                ->bulkToggleable()
                ->required()
                ->live(),
            Text::make(fn (Get $get): string => $this->summary($record, $get)),
        ]);

        $this->action(function (Node $record, array $data): void {
            $user = user();
            $to = Node::query()->find((int) ($data['to'] ?? 0));

            if (!$user instanceof User || !$to instanceof Node || $to->is($record) || !$user->can('update', $record)) {
                return;
            }

            $pick = (string) ($data['pick'] ?? LoadLightener::PICK_STOPPED);
            $result = app(LoadLightener::class)->queue($record, $to, array_values((array) ($data['servers'] ?? [])), $user, $pick);

            $refused = collect($result['refused'])
                ->map(fn (int $count, string $reason) => $count . ' × ' . trans('plugged-host-servers::strings.transfers.lighten.refused_reasons.' . $reason))
                ->implode(', ');

            if ($result['queued'] === 0) {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.transfers.lighten.nothing'))
                    ->body($refused)
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title(trans('plugged-host-servers::strings.transfers.lighten.started', ['count' => $result['queued'], 'node' => $to->name]))
                ->body(trim(trans('plugged-host-servers::strings.transfers.lighten.started_body', ['parallel' => app(LoadLightener::class)->parallel()])
                    . ($refused !== '' ? ' ' . trans('plugged-host-servers::strings.transfers.lighten.refused', ['reasons' => $refused]) : '')))
                ->success()
                ->send();

            $this->redirect(EditNode::getUrl(['record' => $record], panel: 'admin') . '?tab=ph-transfers', navigate: false);
        });
    }

    /** @var array<string, mixed> */
    private array $memo = [];

    /** @return array{options: array<string, array<string, string>>, disabled: array<int, string>} */
    private function destinations(Node $node): array
    {
        return $this->memo['destinations'] ??= (function () use ($node): array {
            $disabled = [];
            $accessible = user()?->accessibleNodes()->get() ?? collect();
            $options = app(LoadLightener::class)->destinationOptions($node, $accessible, $disabled);

            return ['options' => $options, 'disabled' => $disabled];
        })();
    }

    /** @return Collection<int, array<string, mixed>>|null null when the node does not answer */
    private function candidates(Node $node, string $pick): ?Collection
    {
        $pick = $pick !== '' ? $pick : LoadLightener::PICK_STOPPED;

        return $this->memo["candidates.$pick"] ??= app(LoadLightener::class)->candidates($node, $pick);
    }

    private function defaultCount(Node $node): int
    {
        return min(self::DEFAULT_COUNT, max(1, $this->candidates($node, LoadLightener::PICK_STOPPED)?->count() ?? 1));
    }

    /** @return Collection<int, array<string, mixed>> the first `count` candidates: the list shown */
    private function picked(Node $node, Get $get): Collection
    {
        return ($this->candidates($node, (string) $get('pick')) ?? collect())->take(max(1, (int) $get('count')));
    }

    /** A new pick or count: the list shows the first `count` of it, all ticked. */
    private function refill(Node $node, Get $get, Set $set): void
    {
        $all = $this->candidates($node, (string) $get('pick')) ?? collect();
        $count = min(max(1, (int) $get('count')), max(1, $all->count()));

        if ((int) $get('count') !== $count) {
            $set('count', $count);
        }

        $set('servers', $all->take($count)->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    /** @param array<string, mixed> $row */
    private function line(array $row): string
    {
        $memory = $row['memory'] > 0
            ? LoadLightener::size($row['memory'] * 1024 * 1024)
            : trans('plugged-host-servers::strings.transfers.lighten.unlimited');

        return $row['state'] === 'offline'
            ? trans('plugged-host-servers::strings.transfers.lighten.line_stopped', [
                'owner' => $row['owner'],
                'memory' => $memory,
                'data' => LoadLightener::size($row['data']),
            ])
            : trans('plugged-host-servers::strings.transfers.lighten.line_running', [
                'owner' => $row['owner'],
                'used' => LoadLightener::size($row['memory_used']),
                'memory' => $memory,
                'cpu' => (int) round($row['cpu']),
                'data' => LoadLightener::size($row['data']),
            ]);
    }

    private function summary(Node $node, Get $get): string
    {
        $pick = (string) ($get('pick') ?: LoadLightener::PICK_STOPPED);
        $all = $this->candidates($node, $pick);

        if ($all === null) {
            return trans('plugged-host-servers::strings.transfers.lighten.unreachable', ['node' => $node->name]);
        }

        if ($all->isEmpty()) {
            return trans('plugged-host-servers::strings.transfers.lighten.none.' . $pick);
        }

        $chosen = array_flip(array_map(intval(...), (array) $get('servers')));
        $rows = $all->filter(fn (array $row) => isset($chosen[$row['id']]));
        $to = Node::query()->find((int) $get('to'));

        return trans('plugged-host-servers::strings.transfers.lighten.summary', [
            'count' => $rows->count(),
            'memory' => LoadLightener::size($rows->sum('memory') * 1024 * 1024),
            'data' => LoadLightener::size($rows->sum('data')),
            'node' => $to?->name ?? trans('plugged-host-servers::strings.transfers.lighten.chosen_node'),
            'parallel' => app(LoadLightener::class)->parallel(),
        ]);
    }
}
