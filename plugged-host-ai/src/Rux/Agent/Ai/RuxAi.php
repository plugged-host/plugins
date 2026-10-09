<?php

namespace PluggedHost\Ai\Rux\Agent\Ai;

use PluggedHost\Ai\Chat\AiBusyException;
use PluggedHost\Ai\Chat\AiUnavailableException;
use PluggedHost\Ai\Chat\ProviderChain;

/**
 * rux's AI: neo's provider chain, 1:1 (Chat\ProviderChain over the bound ProviderSource: this
 * panel's providers, or the ones noprofit.space runs neo on), called directly: the model calls go
 * nowhere near neo, and no neo limits apply. rux's cooldowns and counts are its own ('rux' scope).
 */
class RuxAi
{
    private readonly ProviderChain $chain;

    public function __construct(ProviderChain $chain)
    {
        $this->chain = $chain->for('rux');
    }

    /**
     * @return list<array<string, mixed>> The usable providers, in failover order.
     */
    public function providers(): array
    {
        return $this->chain->providers();
    }

    /**
     * Whether rux can get neo's providers at all. Whether neo has a usable one is only known
     * per call (AiUnavailableException).
     */
    public function configured(): bool
    {
        return $this->chain->configured();
    }

    /**
     * One model turn on the first provider that answers.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed> ['message' => ['content', 'tool_calls'], 'finish_reason', 'usage', 'provider']
     *
     * @throws AiBusyException|AiUnavailableException
     */
    public function turn(array $messages, array $tools = [], string $toolChoice = 'auto'): array
    {
        return $this->chain->turn($messages, $tools, $toolChoice);
    }
}
