<?php

namespace PluggedHost\Servers\Filament\Components\Actions;

use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use PluggedHost\Servers\Exceptions\TransferException;
use PluggedHost\Servers\Models\ServerRelocation;
use PluggedHost\Servers\Services\Transfers\TransferService;
use Throwable;

/**
 * "Transfer" on the admin server page, through the same orchestrator users get: clean stop,
 * live progress, retries, rollback, backups moving along. It replaces upstream's button in
 * the Manage tab (hidden by TransfersProvider), which asked for existing free allocations and
 * a backup selection and ignored whether wings accepted the job.
 *
 * Unlike users, an admin may pick a node, not only a location ("best node" is the automatic
 * choice), and is not held by the self-service switch, the cooldown, suspension or the paid
 * tier unlock. Ports are never picked by hand: the planner keeps the server's port numbers
 * where the node has them free and creates the rest.
 */
class AdminTransferAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'ph_transfer';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Icon only, like the page's other header actions (delete, console, save); the label
        // is the tooltip.
        $this->label(fn () => trans('plugged-host-servers::strings.transfers.admin.action'));
        $this->hiddenLabel();
        $this->iconButton();
        $this->icon(TablerIcon::TruckDelivery);
        $this->color('primary');

        $this->visible(fn (?Server $record): bool => $record instanceof Server
            && ServerRelocation::ready()
            && (bool) user()?->can('update server', $record));

        $this->disabled(fn (Server $record): bool => ServerRelocation::activeFor($record) !== null || $record->transfer !== null);
        $this->tooltip(fn (Server $record): string => ServerRelocation::activeFor($record) !== null || $record->transfer !== null
            ? trans('plugged-host-servers::strings.transfers.blocked.busy')
            : trans('plugged-host-servers::strings.transfers.admin.action'));

        $this->modalHeading(fn (Server $record) => trans('plugged-host-servers::strings.transfers.admin.heading', ['server' => $record->name]));
        $this->modalIcon(TablerIcon::TruckDelivery);
        $this->modalSubmitActionLabel(fn () => trans('plugged-host-servers::strings.transfers.move'));
        $this->modalWidth('lg');

        $this->schema(fn (Server $record): array => [
            Select::make('target')
                ->label(trans('plugged-host-servers::strings.transfers.admin.target'))
                ->helperText(trans('plugged-host-servers::strings.transfers.admin.target_hint'))
                ->prefixIcon(TablerIcon::MapPin)
                ->required()
                ->live()
                ->native(false)
                ->searchable()
                ->options(fn (): array => $this->options($record)['options'])
                ->disableOptionWhen(fn (string $value): bool => in_array($value, $this->options($record)['disabled'], true)),
            Text::make(fn (Get $get): string => $this->portsLine($record, (string) $get('target'))),
            Toggle::make('start_after')
                ->label(trans('plugged-host-servers::strings.transfers.admin.start_after'))
                ->helperText(trans('plugged-host-servers::strings.transfers.admin.start_after_hint'))
                ->default(false),
            Text::make(fn (): string => $this->backupsLine($record)),
        ]);

        $this->action(function (Server $record, array $data): void {
            $user = user();

            if (!$user instanceof User) {
                return;
            }

            [$location, $only] = $this->target((string) ($data['target'] ?? ''));

            try {
                app(TransferService::class)->start($record, $user, $location, ServerRelocation::ORIGIN_ADMIN, $only, (bool) ($data['start_after'] ?? false));
            } catch (TransferException $e) {
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.transfers.blocked.' . $e->reason, $e->params))
                    ->warning()
                    ->send();

                return;
            } catch (Throwable $e) {
                report($e);
                Notification::make()
                    ->title(trans('plugged-host-servers::strings.transfers.reasons.internal_error'))
                    ->body(Str::limit($e->getMessage(), 200))
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title(trans('plugged-host-servers::strings.transfers.started'))
                ->body(trans('plugged-host-servers::strings.transfers.admin.started_body'))
                ->success()
                ->send();

            // Straight to the Transfers tab, where the progress is.
            $this->redirect(EditServer::getUrl(['record' => $record]) . '?tab=ph-transfers', navigate: false);
        });
    }

    /** @var array<int, array{options: array<string, array<string, string>>, disabled: array<int, string>}> */
    private array $optionMemo = [];

    /** @return array{options: array<string, array<string, string>>, disabled: array<int, string>} */
    private function options(Server $server): array
    {
        return $this->optionMemo[$server->getKey()] ??= (function () use ($server): array {
            $disabled = [];
            $accessible = user()?->accessibleNodes()->get() ?? collect();
            $options = app(TransferService::class)->planner()->adminOptions($server, $accessible, $disabled);

            return ['options' => $options, 'disabled' => $disabled];
        })();
    }

    /**
     * @return array{0: string|null, 1: array<int, int>|null} [location, only-these-nodes]
     */
    private function target(string $value): array
    {
        if (str_starts_with($value, 'loc:')) {
            return [substr($value, 4), null];
        }

        if (str_starts_with($value, 'node:')) {
            return [null, [(int) substr($value, 5)]];
        }

        return [null, null];
    }

    /** What the ports will be: kept, or which new ones, for a picked node. */
    private function portsLine(Server $server, string $target): string
    {
        [$location, $only] = $this->target($target);

        if ($only === null) {
            return trans('plugged-host-servers::strings.transfers.admin.ports_auto');
        }

        $node = Node::query()->find($only[0]);
        $plan = $node instanceof Node ? app(TransferService::class)->planner()->portPlan($server, $node) : null;

        if ($plan === null) {
            return trans('plugged-host-servers::strings.transfers.admin.ports_none');
        }

        $current = app(TransferService::class)->planner()->currentAllocations($server)->values();
        $parts = [];

        foreach ($plan as $index => $pick) {
            $old = (int) ($current[$index]->port ?? 0);
            $parts[] = $pick['same'] ? (string) $pick['port'] : "$old → {$pick['port']}";
        }

        return trans('plugged-host-servers::strings.transfers.admin.ports', ['ports' => implode(', ', $parts)]);
    }

    private function backupsLine(Server $server): string
    {
        $backups = $server->backups()->where('is_successful', true);
        $count = (int) $backups->count();

        return $count === 0
            ? trans('plugged-host-servers::strings.transfers.admin.no_backups')
            : trans('plugged-host-servers::strings.transfers.admin.backups', [
                'count' => $count,
                'size' => number_format(((int) $server->backups()->where('is_successful', true)->sum('bytes')) / 1024 ** 3, 1) . ' GB',
            ]);
    }
}
