<?php

namespace PluggedHost\Ai\Contracts;

use App\Models\User;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Services\Gateway\GatewayBusyException;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\Gateway\ModelUnavailableException;
use PluggedHost\Ai\Services\Gateway\NotLinkedException;

/**
 * Where neo's model turns run and its usage limits are kept. Standalone (Services\LocalNeoBackend)
 * that is this panel: neo's providers (Chat\ProviderChain) and the 5-hour and weekly windows in
 * neo_usage_*. With plugged-host-noprofit it is noprofit.space's neo gateway.
 *
 * The subject is a panel user (the chat), or a Discord account (neo on Discord): the account it is
 * linked to, or on its own at a share of the limits when nobody linked it.
 *
 * Every slot is a model people pick by name (Support\NeoModel); a chat turn runs on the picked one
 * only (the default model when none was picked), never on another as a fallback. The limits mode
 * says what is metered: 'models' (only limited models, each in a weekly window of its own) or
 * 'all' (every model in the shared 5-hour and weekly windows, its multiplier times its tokens).
 *
 * A turn answers ['message' => ['content', 'tool_calls'], 'thinking' (what the model thought, or
 * ''), 'finish_reason', 'provider' => ['slot', 'label', 'driver', 'model'], 'model' (the key of
 * the model that answered), 'usage' => ['prompt_tokens', 'completion_tokens', 'total_tokens',
 * 'multiplier', 'charged_tokens'], 'limits', 'mode', 'models', 'default_model', 'account_id',
 * 'linked']. limits is ['short' => window, 'long' => window], a window ['used', 'limit', 'percent',
 * 'active', 'started_at', 'resets_at', 'window_seconds']. models lists the usable models: ['key',
 * 'label', 'description', 'access' (everyone, plus, plusplus), 'allowed', 'limited', 'multiplier',
 * 'vision', 'thinking', 'window' (its weekly window when limited, else null)].
 */
interface NeoBackend
{
    /** Whether neo can think here at all (no network involved). */
    public function configured(): bool;

    /**
     * One model turn for a panel user, in the canonical chat shape (OpenAI style). With $onDelta
     * the answer is handed on while it is written: $onDelta('text' or 'thinking', …), and
     * $onDelta('reset', '') when what came so far is void (the call is being sent again).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  (callable(string, string): void)|null  $onDelta
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|NotLinkedException|GatewayBusyException|GatewayUnavailableException
     */
    public function turn(User $user, NeoConversation $conversation, array $messages, array $tools, string $toolChoice, ?callable $onDelta = null): array;

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
     * One picture from neo's image model for a Discord account (rux's make_image), metered
     * against that account like its other turns (the image model's daily images).
     *
     * @return array{mime: string, data: string, label: ?string} data is base64
     *
     * @throws LimitReachedException|ModelUnavailableException|GatewayBusyException|GatewayUnavailableException
     */
    public function discordImage(string $discordId, string $prompt): array;

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
     * The user's windows, the limits mode and the models they can pick: ['linked' => true,
     * 'limits', 'mode', 'models', 'default_model']. Null when unknown (the backend is down);
     * ['linked' => false] when the backend does not know the user's account.
     *
     * @return array<string, mixed>|null
     */
    public function usage(User $user): ?array;

    /** Where a user whose account the backend does not know yet connects it, or null when there is nothing to connect. */
    public function activationUrl(): ?string;
}
