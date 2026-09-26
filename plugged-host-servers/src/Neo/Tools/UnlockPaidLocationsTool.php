<?php

namespace PluggedHost\Servers\Neo\Tools;

use InvalidArgumentException;
use PluggedHost\Neo\Tools\ToolContext;
use PluggedHost\Neo\Tools\ToolResult;
use PluggedHost\Neo\Tools\ToolRisk;
use PluggedHost\Servers\Neo\AccountTool;
use PluggedHost\Servers\Services\PremiumLocationService;
use stdClass;

final class UnlockPaidLocationsTool extends AccountTool
{
    public function name(): string
    {
        return 'unlock_paid_locations';
    }

    public function description(): string
    {
        return 'Spend tokens once to permanently unlock every Plus and Premium location for the account. Only when the user clearly asks for it; the user always confirms.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Critical;
    }

    public function summarize(array $arguments): string
    {
        return 'Unlock Plus/Premium locations for ' . app(PremiumLocationService::class)->cost() . ' tokens';
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $result = app(PremiumLocationService::class)->unlock($context->user);

        if (!($result['ok'] ?? false)) {
            throw new InvalidArgumentException(($result['reason'] ?? '') === 'insufficient_tokens'
                ? 'Not enough tokens (' . ($result['balance'] ?? 0) . ' of ' . ($result['cost'] ?? '?') . ').'
                : 'The unlock could not be completed; no tokens were spent.');
        }

        $this->activity($context, 'locations.unlock')->property('cost', (int) ($result['cost'] ?? 0))->log();

        return ToolResult::ok((($result['cost'] ?? 0) > 0 ? 'Unlocked Plus and Premium locations for ' . $result['cost'] . ' tokens.' : 'Paid locations were already unlocked.'), 'Unlocked paid locations');
    }
}
