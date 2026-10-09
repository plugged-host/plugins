<?php

namespace PluggedHost\Core\Filament\Admin\Pages;

use App\Models\Node;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use PluggedHost\Core\Filament\Admin\Clusters\Abuse;
use PluggedHost\Core\Models\AbuseAgent;
use PluggedHost\Core\Models\AbuseFinding;
use PluggedHost\Core\Services\Abuse\AgentGateway;

/**
 * Which nodes run the abuse agent (agent/abuse-agent.py) and when each last reported. A node
 * without it is only covered by the panel-side checks: files, startup, console and CPU.
 */
class AbuseNodeAgents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = Abuse::class;

    protected static ?string $slug = 'node-agents';

    protected static ?int $navigationSort = 2;

    protected string $view = 'plugged-host-core::filament.admin.abuse-node-agents';

    public static function getNavigationLabel(): string
    {
        return 'Node agents';
    }

    public function getTitle(): string
    {
        return 'Node agents';
    }

    public function getSubheading(): ?string
    {
        return 'The agent reports what is running on each node every minute, so miners and proxies are caught while they run. Latest version: ' . AgentGateway::latestVersion() . '.';
    }

    public static function canAccess(): bool
    {
        return (user()?->can('viewAny', Node::class) ?? false) && AbuseFinding::ready();
    }

    public static function installCommand(): string
    {
        return 'curl -fsSL ' . url('/plugged-host/abuse/agent/install.sh') . ' | sudo bash';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('install')
                ->label('Install command')
                ->modalHeading('Install or update the agent')
                ->modalDescription('Run this as root on each Wings node. It signs its reports with the node\'s own Wings token, so there is nothing to configure.')
                ->schema([
                    TextEntry::make('command')
                        ->hiddenLabel()
                        ->state(self::installCommand())
                        ->fontFamily(FontFamily::Mono)
                        ->copyable(),
                ])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
        ];
    }

    public function table(Table $table): Table
    {
        $agent = fn (string $column) => AbuseAgent::query()->select($column)->whereColumn('node_id', 'nodes.id')->limit(1);

        return $table
            ->query(fn () => Node::query()->addSelect([
                'nodes.*',
                'agent_version' => $agent('version'),
                'agent_seen' => $agent('last_report_at'),
                'agent_stats' => $agent('stats'),
            ]))
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Node')
                    ->sortable(),
                TextColumn::make('agent_status')
                    ->label('Agent')
                    ->badge()
                    ->state(fn (Node $record) => self::status($record))
                    ->color(fn (string $state) => match ($state) {
                        'Reporting' => 'success',
                        'Not reporting' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('agent_version')
                    ->label('Version')
                    ->placeholder('—')
                    ->color(fn (?string $state) => $state !== null && $state !== AgentGateway::latestVersion() ? 'warning' : null),
                TextColumn::make('agent_seen')
                    ->label('Last report')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never'),
                TextColumn::make('servers_seen')
                    ->label('Running servers')
                    ->state(fn (Node $record) => self::stat($record, 'containers')),
                TextColumn::make('host_cpu')
                    ->label('Node CPU')
                    ->state(fn (Node $record) => ($cpu = self::stat($record, 'cpu_percent')) !== null ? $cpu . '%' : null)
                    ->placeholder('—'),
            ]);
    }

    private static function status(Node $node): string
    {
        $seen = $node->getAttribute('agent_seen');

        if ($seen === null) {
            return 'Not installed';
        }

        return now()->diffInSeconds($seen, true) <= 300 ? 'Reporting' : 'Not reporting';
    }

    private static function stat(Node $node, string $key): mixed
    {
        $stats = $node->getAttribute('agent_stats');
        $stats = is_string($stats) ? json_decode($stats, true) : $stats;

        return is_array($stats) ? ($stats[$key] ?? null) : null;
    }
}
