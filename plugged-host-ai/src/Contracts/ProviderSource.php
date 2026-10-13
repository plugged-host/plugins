<?php

namespace PluggedHost\Ai\Contracts;

use PluggedHost\Ai\Chat\AiUnavailableException;

/**
 * Where neo's AI providers come from, for Chat\ProviderChain (rux, and neo when this panel runs
 * neo itself). Standalone (Chat\ConfiguredProviders) they are set on this panel (NEO_P{n}_* in the
 * panel .env, edited in neo's settings); with plugged-host-noprofit they are fetched from
 * noprofit.space, so rux runs the chain neo runs there.
 *
 * A provider is ['slot', 'enabled', 'label', 'driver' (openai, anthropic or gemini), 'endpoint',
 * 'api_key', 'model', 'max_tokens', 'timeout', 'daily_token_cap', 'extra_headers', 'extra_body',
 * 'premium']. A premium slot answers only turns that ask for the premium model (ProviderChain::turn()).
 */
interface ProviderSource
{
    /** Whether there is a chain to be had at all (no network involved). */
    public function configured(): bool;

    /**
     * Every provider slot, usable or not, in failover order.
     *
     * @return list<array<string, mixed>>
     */
    public function providers(): array;

    /**
     * The failover timings (seconds).
     *
     * @return array{default_cooldown: int, auth_cooldown: int, max_cooldown: int, deadline: int}
     */
    public function failover(): array;

    /** When the chain in use was fetched (unix time); null when it never was, or is not fetched at all. */
    public function fetchedAt(): ?int;

    /**
     * Fetch now, whatever the age of the copy in hand (nothing to do for a chain set here).
     *
     * @throws AiUnavailableException When the chain cannot be fetched.
     */
    public function refresh(): void;
}
