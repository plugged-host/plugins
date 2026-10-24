<?php

namespace PluggedHost\Ai\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PluggedHost\Ai\Chat\AiBusyException;
use PluggedHost\Ai\Chat\AiException;
use PluggedHost\Ai\Chat\ProviderChain;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Ai\Contracts\ProviderSource;
use PluggedHost\Ai\Models\NeoConversation;
use PluggedHost\Ai\Services\Gateway\GatewayBusyException;
use PluggedHost\Ai\Services\Gateway\GatewayUnavailableException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\Gateway\ModelUnavailableException;
use PluggedHost\Ai\Support\NeoModel;
use PluggedHost\Ai\Support\NeoSubject;
use PluggedHost\Ai\Support\NeoUsagePercent;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Discord\Contracts\DiscordAccounts;
use Throwable;

/**
 * neo run by this panel (without plugged-host-noprofit): what noprofit.space's neo gateway
 * (InternalNeoController) does, here. A turn takes the subject's turn lock and one of the
 * concurrency slots, checks its 5-hour and weekly windows (NeoUsageMeter), calls neo's providers
 * (ProviderChain, 'neo' scope) and charges the windows; the answers have the gateway's shapes and
 * its failures its exceptions. A chat turn runs on the model its user picked (NeoModel), the first
 * one open to them when none was, and counts its multiplier times its tokens. Here every model is
 * metered in the shared windows (the gateway's limits mode 'all'), and answers are not streamed:
 * a turn hands its text on in one piece.
 *
 * The subject is the panel user. On Discord it is the panel user a Discord account is linked to
 * (plugged-host-discord's DiscordAccounts), or the Discord account on its own at a share of the
 * limits. Every panel user is "linked": there is nothing to activate.
 */
final class LocalNeoBackend implements NeoBackend
{
    /** The service neo on Discord's turns are recorded under (as on noprofit.space). */
    public const DISCORD_SERVICE = 'plugged-host-discord';

    private readonly ProviderChain $chain;

    public function __construct(
        ProviderChain $chain,
        private readonly ProviderSource $providers,
        private readonly NeoUsageMeter $meter,
    ) {
        $this->chain = $chain->for('neo', (bool) config('plugged-host-ai.local.tools_probe', true));
    }

    public function configured(): bool
    {
        return $this->providers->configured();
    }

    public function turn(User $user, NeoConversation $conversation, array $messages, array $tools, string $toolChoice, ?callable $onDelta = null): array
    {
        $subject = NeoSubject::account($user)->withUsagePercent(NeoUsagePercent::for($user));
        $plan = NeoModel::plan($user);

        $result = $this->run($subject, 'plugged-host', (string) $conversation->uuid, $messages, $tools, $toolChoice, $this->slotFor(NeoModel::forTurn($user), $plan), $plan);

        if ($onDelta !== null && is_string($result['message']['content'] ?? null) && $result['message']['content'] !== '') {
            $onDelta('text', $result['message']['content']);
        }

        return $result;
    }

    public function discordTurn(string $discordId, string $conversation, array $messages, array $tools, string $toolChoice): array
    {
        [$subject] = $this->discordSubject($discordId);

        $plan = $subject->user === null ? 'free' : NeoModel::plan($subject->user);

        return $this->run($subject, self::DISCORD_SERVICE, 'discord:' . $conversation, $messages, $tools, $toolChoice, $this->slotFor(null, $plan), $plan);
    }

    /**
     * The panel's own providers only read and write text.
     */
    public function discordImage(string $discordId, string $prompt): array
    {
        throw new ModelUnavailableException('model_unavailable');
    }

    public function discordUsage(string $discordId): array
    {
        $this->assertReady();
        [$subject, $name] = $this->discordSubject($discordId);
        $snapshot = $this->meter->snapshot($subject);

        return array_filter([
            'account_id' => $subject->user?->id,
            'user_id' => $subject->user?->id,
            'linked' => !$subject->isUnlinkedDiscord(),
            'name' => $name,
            'unlinked_percent' => $this->meter->unlinkedDiscordPercent(),
            'gateway_enabled' => $this->enabled(),
            'limits' => $snapshot,
            'blocked' => $this->meter->blockingWindow($snapshot),
        ], static fn (mixed $value, string $key): bool => $value !== null || in_array($key, ['account_id', 'user_id', 'blocked'], true), ARRAY_FILTER_USE_BOTH);
    }

    public function usage(User $user): ?array
    {
        try {
            if (!$this->meter->ready()) {
                return null;
            }

            return [
                'linked' => true,
                'limits' => $this->meter->snapshot(NeoSubject::account($user)->withUsagePercent(NeoUsagePercent::for($user))),
            ] + $this->catalog(NeoModel::plan($user));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function activationUrl(): ?string
    {
        return null;
    }

    /**
     * One metered turn (InternalNeoController::turn).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     *
     * @throws LimitReachedException|GatewayBusyException|GatewayUnavailableException
     */
    private function run(NeoSubject $subject, string $service, string $conversation, array $messages, array $tools, string $toolChoice, int $modelSlot, string $plan): array
    {
        if (!$this->enabled()) {
            throw new GatewayUnavailableException('gateway_disabled');
        }

        $this->assertReady();

        $lockSeconds = (int) $this->providers->failover()['deadline'] + 15;
        $turnLock = Cache::lock('plugged-host-ai:neo:turn:' . $subject->lockKey(), $lockSeconds);

        try {
            $turnLock->block(3);
        } catch (LockTimeoutException) {
            throw new GatewayBusyException('busy', 2);
        }

        try {
            $now = CarbonImmutable::now();
            $snapshot = $this->meter->snapshot($subject, $now);

            if (($window = $this->meter->blockingWindow($snapshot)) !== null) {
                throw new LimitReachedException($window, $snapshot[$window]['resets_at'], $snapshot);
            }

            $slot = $this->acquireConcurrencySlot($lockSeconds);
            if ($slot === null) {
                throw new GatewayBusyException('busy', 3);
            }

            $event = [
                'service_key' => $service,
                'conversation' => Str::limit($conversation, 64, ''),
                'request_id' => (string) Str::uuid(),
            ];

            try {
                $result = $this->chain->turn($messages, $tools, $toolChoice, slot: $modelSlot);
            } catch (AiException $e) {
                $this->meter->recordFailure($subject, $event + ['attempts' => [['reason' => Str::limit($e->getMessage(), 200, ''), 'retry_after' => $e->retryAfter]]]);

                throw $this->unavailable($e);
            } catch (Throwable $e) {
                // A bug, not a provider failing (the gateway answered these with a 500).
                report($e);
                $this->meter->recordFailure($subject, $event + ['attempts' => [['reason' => class_basename($e)]]]);

                throw new GatewayUnavailableException('providers_unavailable (' . class_basename($e) . ')');
            } finally {
                $slot->release();
            }

            $prompt = max(0, (int) ($result['usage']['prompt_tokens'] ?? 0));
            $completion = max(0, (int) ($result['usage']['completion_tokens'] ?? 0));
            $total = $prompt + $completion;
            $multiplier = max(1, (int) ($result['provider']['multiplier'] ?? 1));
            $charged = $total * $multiplier;
            $provider = $this->provider((array) ($result['provider'] ?? []));
            $finishReason = Str::limit((string) ($result['finish_reason'] ?? 'stop'), 24, '');

            $this->meter->charge($subject, $charged, $event + [
                'provider_slot' => $provider['slot'],
                'provider_label' => Str::limit((string) $provider['label'], 64, ''),
                'driver' => Str::limit((string) $provider['driver'], 16, ''),
                'model' => Str::limit((string) $provider['model'], 128, ''),
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'finish_reason' => $finishReason,
                'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
            ], $now);

            return [
                'message' => (array) ($result['message'] ?? []),
                'finish_reason' => $finishReason,
                'provider' => $provider,
                'model' => (string) $modelSlot,
                'thinking' => '',
                'usage' => [
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => $completion,
                    'total_tokens' => $total,
                    'multiplier' => $multiplier,
                    'charged_tokens' => $charged,
                ],
                'limits' => $this->meter->snapshot($subject),
                'account_id' => $subject->user?->id,
                'linked' => !$subject->isUnlinkedDiscord(),
            ] + $this->catalog($plan);
        } finally {
            $turnLock->release();
        }
    }

    /**
     * Whose limits a Discord account's turns use, and the name of its panel user when linked.
     *
     * @return array{0: NeoSubject, 1: ?string}
     *
     * @throws GatewayUnavailableException
     */
    private function discordSubject(string $discordId): array
    {
        $account = null;

        // enabled(), not available(): class_exists() is false for an interface.
        if (Plugins::enabled('plugged-host-discord')) {
            try {
                $account = app(DiscordAccounts::class)->account($discordId);
            } catch (Throwable $e) {
                throw new GatewayUnavailableException('discord_accounts_unavailable: ' . Str::limit($e->getMessage(), 100, ''));
            }
        }

        $user = isset($account['user_id']) ? User::query()->find((int) $account['user_id']) : null;

        if (!$user instanceof User) {
            return [NeoSubject::unlinkedDiscord($discordId), null];
        }

        return [
            NeoSubject::account($user, $discordId)->withUsagePercent(NeoUsagePercent::for($user)),
            (string) ($account['name'] ?? '') !== '' ? (string) $account['name'] : (string) $user->username,
        ];
    }

    /**
     * Every provider failed. Only rate limits and outages that clear within max_busy_wait are
     * worth waiting out (busy); anything longer, or a chain that cannot answer at all, is down.
     */
    private function unavailable(AiException $e): GatewayBusyException|GatewayUnavailableException
    {
        if ($e instanceof AiBusyException && $e->retryAfter <= max(1, (int) config('plugged-host-ai.local.failover.max_busy_wait', 60))) {
            return new GatewayBusyException('busy', max(1, $e->retryAfter));
        }

        return new GatewayUnavailableException('providers_unavailable (' . $e->getMessage() . ')', $e->retryAfter);
    }

    /**
     * The answering provider as the gateway names it: slot, label, driver, model.
     *
     * @param  array<string, mixed>  $used
     * @return array{slot: ?int, label: ?string, driver: ?string, model: ?string}
     */
    private function provider(array $used): array
    {
        $slot = isset($used['slot']) ? (int) $used['slot'] : null;
        $driver = null;

        foreach ($this->providers->providers() as $provider) {
            if ((int) ($provider['slot'] ?? 0) === $slot) {
                $driver = (string) ($provider['driver'] ?? '');
            }
        }

        return [
            'slot' => $slot,
            'label' => isset($used['label']) ? (string) $used['label'] : null,
            'driver' => $driver,
            'model' => isset($used['model']) ? (string) $used['model'] : null,
        ];
    }

    /**
     * The slot a turn runs on: the picked one while it is usable and open to $plan ('premium', from
     * before models had names: the first one not open to everyone), else the first usable one open
     * to $plan, preferring ones open to everyone.
     *
     * @throws ModelUnavailableException
     */
    private function slotFor(?string $picked, string $plan): int
    {
        $usable = array_values(array_filter($this->providers->providers(), static fn ($provider): bool => is_array($provider) && ProviderChain::usable($provider)));

        if ($picked !== null && ctype_digit($picked)) {
            foreach ($usable as $provider) {
                if ((int) $provider['slot'] === (int) $picked) {
                    if (!NeoModel::allows($plan, (string) ($provider['access'] ?? 'everyone'))) {
                        throw new ModelUnavailableException('model_not_allowed', (string) $provider['label'], (string) ($provider['access'] ?? 'everyone'));
                    }

                    return (int) $provider['slot'];
                }
            }

            throw new ModelUnavailableException('model_unavailable');
        }

        $open = array_values(array_filter($usable, static fn (array $provider): bool => NeoModel::allows($plan, (string) ($provider['access'] ?? 'everyone'))));

        if ($picked === 'premium') {
            foreach ($open as $provider) {
                if (($provider['access'] ?? 'everyone') !== 'everyone') {
                    return (int) $provider['slot'];
                }
            }
        }

        foreach ($open as $provider) {
            if (($provider['access'] ?? 'everyone') === 'everyone') {
                return (int) $provider['slot'];
            }
        }

        return isset($open[0]) ? (int) $open[0]['slot'] : throw new ModelUnavailableException('no_model');
    }

    /**
     * The limits mode and the model picker, as the gateway answers them.
     *
     * @return array{mode: string, default_model: ?string, models: list<array<string, mixed>>}
     */
    private function catalog(string $plan): array
    {
        $models = [];

        foreach ($this->providers->providers() as $provider) {
            if (!is_array($provider) || !ProviderChain::usable($provider)) {
                continue;
            }

            $models[] = [
                'key' => (string) $provider['slot'],
                'label' => (string) $provider['label'],
                'description' => (string) ($provider['description'] ?? ''),
                'access' => (string) ($provider['access'] ?? 'everyone'),
                'allowed' => NeoModel::allows($plan, (string) ($provider['access'] ?? 'everyone')),
                'limited' => false,
                'multiplier' => max(1, (int) ($provider['multiplier'] ?? 1)),
                'vision' => ProviderChain::seesImages($provider),
                'thinking' => false,
                'window' => null,
            ];
        }

        try {
            $default = (string) $this->slotFor(null, $plan);
        } catch (ModelUnavailableException) {
            $default = null;
        }

        return ['mode' => 'all', 'default_model' => $default, 'models' => $models];
    }

    /**
     * A cap on concurrent provider calls: every one holds a PHP worker for its whole length.
     */
    private function acquireConcurrencySlot(int $seconds): ?Lock
    {
        $slots = max(1, (int) config('plugged-host-ai.local.failover.max_concurrent', 4));

        for ($i = 1; $i <= $slots; $i++) {
            $lock = Cache::lock("plugged-host-ai:neo:concurrency:{$i}", $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    private function enabled(): bool
    {
        return (bool) config('plugged-host-ai.local.enabled', true);
    }

    /**
     * @throws GatewayUnavailableException
     */
    private function assertReady(): void
    {
        if (!$this->meter->ready()) {
            throw new GatewayUnavailableException('usage_tables_missing');
        }
    }
}
