<?php

namespace PluggedHost\Servers\Neo\Tools;

use App\Models\User;
use PluggedHost\Core\Services\AccountSummary;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Servers\Support\AccountAllowance;
use PluggedHost\Servers\Neo\AccountTool;
use PluggedHost\Servers\Services\ServerProvisioner;
use PluggedHost\Servers\Services\LocationCatalog;
use PluggedHost\Servers\Support\PremiumAccess;
use stdClass;

final class AccountOverviewTool extends AccountTool
{
    public function name(): string
    {
        return 'account_overview';
    }

    public function description(): string
    {
        return 'The user\'s account: tokens and credits balance, resource allowance (CPU/memory/disk/server count) with what is used and left, and whether Plus/Premium locations are unlocked. Use before creating or resizing servers.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function summarize(array $arguments): string
    {
        return 'Check account and allowance';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $user = $context->user;
        app(ServerProvisioner::class)->forget($user);

        $summary = app(AccountSummary::class)->forUser($user);
        if ($summary === null) {
            return ToolResult::ok('This account has no server allowance, so it cannot create servers.', 'No allowance');
        }

        $fmt = static fn (?int $used, ?int $limit, string $unit): string => ($used ?? 0) . $unit . ' used of ' . (($limit ?? 0) > 0 ? $limit . $unit : 'unlimited');
        $limits = AccountAllowance::forUser($user);
        $overage = $limits?->getOverage($user) ?? [];

        // Not "tokens:": SecretRedactor masks any "token…: value" line, balance included.
        $lines = [
            'token balance: ' . (int) ($summary['tokens'] ?? 0),
            'credit balance: ' . (int) ($summary['credits'] ?? 0),
            'servers: ' . ($summary['servers_used'] ?? 0),
            'cpu: ' . $fmt($summary['cpu_used'] ?? 0, $summary['cpu_limit'] ?? 0, '%'),
            'memory: ' . $fmt($summary['memory_used'] ?? 0, $summary['memory_limit'] ?? 0, ' MB'),
            'disk: ' . $fmt($summary['disk_used'] ?? 0, $summary['disk_limit'] ?? 0, ' MB'),
            'plugged.host Plus: ' . (PremiumAccess::isUnlocked($user, LocationCatalog::PLUS) ? 'yes (Plus locations, queue priority, no in-game ads)' : 'no'),
            'plugged.host PlusPlus: ' . $this->plusPlus($user),
        ];

        if (array_filter($overage) !== []) {
            $lines[] = 'over_quota: ' . json_encode($overage) . ' (existing servers keep running but cannot grow; shrink or delete something)';
        }

        return ToolResult::ok(implode("\n", $lines), 'Checked account');
    }

    private function plusPlus(User $user): string
    {
        $membership = 'PluggedHost\\Economy\\Services\\MembershipService';
        $until = Plugins::available($membership) ? app($membership)->plusPlusUntil($user) : null;

        return $until === null ? 'no' : 'yes, until ' . $until->toDateTimeString() . ' UTC (Premium locations, no ads, front of the queue, extra CPU)';
    }
}
