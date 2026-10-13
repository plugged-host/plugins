<?php

namespace PluggedHost\Ai\Contracts;

use App\Models\User;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Services\Gateway\GatewayBusyException;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\Gateway\NotLinkedException;

/**
 * Where neo's model turns run and its usage limits are kept. Standalone (Services\LocalNeoBackend)
 * that is this panel: neo's providers (Chat\ProviderChain) and the 5-hour and weekly windows in
 * neo_usage_*. With plugged-host-noprofit it is noprofit.space's neo gateway.
 *
 * The subject is a panel user (the chat), or a Discord account (neo on Discord): the account it is
 * linked to, or on its own at a share of the limits when nobody linked it.
 *
 * A chat turn asks for the model its user picked (Support\NeoModel): the premium slots, whose
 * tokens count premium_multiplier times, or the others.
 *
 * A turn answers ['message' => ['content', 'tool_calls'], 'finish_reason', 'provider' => ['slot',
 * 'label', 'driver', 'model'], 'model' (premium or standard: which kind of slot answered), 'usage' =>
 * ['prompt_tokens', 'completion_tokens', 'total_tokens', 'multiplier', 'charged_tokens'], 'limits',
 * 'premium_model', 'account_id', 'linked']. limits is ['short' => window, 'long' => window], a window
 * ['used', 'limit', 'percent', 'active', 'started_at', 'resets_at', 'window_seconds'].
 * premium_model is ['label', 'multiplier'], or null while no premium slot is ready.
 */
interface NeoBackend
{
    /** Whether neo can think here at all (no network involved). */
    public function configured(): bool;

    /**
     * One model turn for a panel user, in the canonical chat shape (OpenAI style).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    public function turn(User $user, NeoConversation $conversation, array $messages, array $tools, string $toolChoice): array;

    /**
     * One model turn for neo on Discord, metered against that Discord account.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|GatewayBusyException|GatewayUnavailableException
     */
    public function discordTurn(string $discordId, string $conversation, array $messages, array $tools, string $toolChoice): array;

    /**
     * Who a Discord account is and where its windows stand: linked, account_id, user_id (the
     * panel user, or null) and name when linked, limits, blocked (the window that refuses new
     * turns, or null) and unlinked_percent.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayUnavailableException
     */
    public function discordUsage(string $discordId): array;

    /**
     * The user's current windows and the premium model on offer. Null when unknown (the backend
     * is down); ['linked' => false] when the backend does not know the user's account.
     *
     * @return array{linked: bool, limits?: array<string, mixed>|null, premium_model?: array{label: string, multiplier: int}|null}|null
     */
    public function usage(User $user): ?array;

    /** Where a user whose account the backend does not know yet connects it, or null when there is nothing to connect. */
    public function activationUrl(): ?string;
}
