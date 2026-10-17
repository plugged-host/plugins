<?php

namespace PluggedHost\Ai\Rux\Agent\Ai;

use PluggedHost\Ai\Chat\AiBusyException;
use PluggedHost\Ai\Chat\AiUnavailableException;
use PluggedHost\Ai\Chat\ProviderChain;

/**
 * rux's AI: neo's provider chain, 1:1 (Chat\ProviderChain over the bound ProviderSource: this
 * panel's providers, or the ones noprofit.space runs neo on), called directly: the model calls go
 * nowhere near neo, and no neo limits apply. rux's cooldowns and counts are its own ('rux' scope).
 *
 * neo's premium slots (Haiku 5.5, the model PlusPlus users pick in neo) answer rux's orders first,
 * before the others ($premium); everything else rux asks (the checks of watched channels and
 * standing rules) never reaches them. Their quota is small, and the user would rather have no chat
 * moderation than spend it there (2026-10-15), so a check the other slots cannot answer is not
 * made at all. Screenshots go to the slots that see images first (ProviderChain::seesImages()).
 */
class RuxAi
{
    private readonly ProviderChain $chain;

    public function __construct(ProviderChain $chain)
    {
        $this->chain = $chain->for('rux');
    }

    /**
     * The usable providers, in failover order: for orders ($premium) the premium slots first and
     * then the others, for checks only the others.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(bool $premium = false): array
    {
        $usable = $this->chain->providers();
        $others = array_values(array_filter($usable, static fn (array $p): bool => empty($p['premium'])));

        return $premium
            ? [...array_values(array_filter($usable, static fn (array $p): bool => !empty($p['premium']))), ...$others]
            : $others;
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
     * One model turn on the first provider that answers; premium slots first when $premium,
     * never otherwise.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed> ['message' => ['content', 'tool_calls'], 'finish_reason', 'usage', 'provider']
     *
     * @throws AiBusyException|AiUnavailableException
     */
    public function turn(array $messages, array $tools = [], string $toolChoice = 'auto', bool $premium = false): array
    {
        return $this->chain->turn($messages, $tools, $toolChoice, $premium);
    }
}
