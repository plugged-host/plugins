<?php

namespace PluggedHost\Rux\Tools\Moderation;

use PluggedHost\Rux\Discord\DiscordRest;
use PluggedHost\Rux\Discord\Resolver;
use PluggedHost\Rux\Moderation\ModerationService;
use PluggedHost\Rux\Models\RuxCase;
use PluggedHost\Rux\Noprofit\NoprofitUnavailable;
use PluggedHost\Rux\Noprofit\RuxNoprofit;
use PluggedHost\Rux\Support\RuxAccess;
use PluggedHost\Rux\Tools\RuxContext;
use PluggedHost\Rux\Tools\RuxTool;
use InvalidArgumentException;

abstract class ModerationTool extends RuxTool
{
    public function __construct(
        protected readonly ModerationService $moderation,
        protected readonly Resolver $resolver,
        protected readonly DiscordRest $discord,
        protected readonly RuxAccess $access,
    ) {}

    /**
     * Moderators may not act on staff (the owner, people with moderation permissions, other
     * moderators) or on noprofit.space admins, whatever their Discord roles; admins may.
     *
     * @param  array{id: string, member?: array<string, mixed>}  $target
     */
    protected function guard(RuxContext $context, array $target): void
    {
        if ($context->isAdmin()) {
            return;
        }

        $roles = isset($target['member']) ? array_values(array_map('strval', (array) ($target['member']['roles'] ?? []))) : null;

        $refusal = new InvalidArgumentException('Moderators cannot do this to staff, admins, other moderators or themselves. Tell them to ask an admin.');

        if ($target['id'] === $context->actorDiscordId || $this->access->isStaff($target['id'], $roles)) {
            throw $refusal;
        }

        try {
            $isAdmin = app(RuxNoprofit::class)->account($target['id'], 4)['is_admin'] ?? false;
        } catch (NoprofitUnavailable) {
            // Cannot tell: refusing is the safe mistake.
            $isAdmin = true;
        }

        if ($isAdmin) {
            throw $refusal;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function memberProperty(string $description = 'The member: <@mention>, user id, or their name.'): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reasonProperty(): array
    {
        return ['type' => 'string', 'description' => 'Why, in a few words. Shown to the member and in the audit log.'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function caseResult(RuxCase $case, array $extra = []): array
    {
        return $extra + ['case' => $case->id, 'action' => $case->action, 'member' => $case->target_id !== null ? "<@{$case->target_id}>" : null];
    }
}
