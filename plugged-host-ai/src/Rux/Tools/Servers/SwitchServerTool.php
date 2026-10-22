<?php

namespace PluggedHost\Ai\Rux\Tools\Servers;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PluggedHost\Ai\Rux\Agent\RuxAgent;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Support\RuxConfig;
use PluggedHost\Ai\Rux\Support\RuxGuild;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Ai\Rux\Tools\ToolResult;

/**
 * In a DM, which of rux's servers the conversation acts on (the primary one until someone picks
 * another; the pick holds for a day). Only offered in DMs while rux works on more than one
 * server: an order in a server channel always acts on that server. A moderator can only pick a
 * server where they hold a rux moderator role. RuxAgent carries the rest of the order out on the
 * picked server.
 */
final class SwitchServerTool extends RuxTool
{
    /** How long a DM keeps acting on the picked server. */
    public const REMEMBER_SECONDS = 86400;

    public function __construct(private readonly RuxAccess $access) {}

    public function name(): string
    {
        return 'switch_server';
    }

    public function description(): string
    {
        return 'Only in a DM: choose which of rux\'s Discord servers this DM acts on from now on (members, channels, roles, rules, cases and giveaways are all per server). Call it before acting when they mean a server other than the one this DM acts on.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function offeredIn(RuxContext $context): bool
    {
        return $context->direct && count(RuxConfig::guilds()) > 1;
    }

    public function parameters(): array
    {
        return $this->schema([
            'server' => ['type' => 'string', 'description' => 'The server: its name or id, as the system prompt lists them.'],
        ], ['server']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        if (!$context->direct) {
            throw new InvalidArgumentException('This order came from a server channel, so it acts on that server only. For another server, they ask there or in a DM.');
        }

        $guild = $this->find($this->required($arguments, 'server'));

        if (!$context->isAdmin() && !$this->access->isModerator($guild->id, $context->actorDiscordId)) {
            throw new InvalidArgumentException("This person is not a rux moderator on {$guild->label()}, so rux does nothing there for them.");
        }

        Cache::put(RuxAgent::dmServerKey($context->actorDiscordId), $guild->id, self::REMEMBER_SECONDS);

        return ToolResult::ok([
            'server' => $guild->label(),
            'discord_name' => $this->access->serverName($guild->id),
            'note' => 'From now on this DM acts on this server.',
        ]);
    }

    private function find(string $input): RuxGuild
    {
        $guilds = RuxConfig::guilds();
        $plain = static fn (string $text): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
        $wanted = $plain($input);

        foreach ($guilds as $guild) {
            if ($guild->id === trim($input) || ($wanted !== '' && in_array($wanted, [$plain($guild->hostName()), $plain($this->access->serverName($guild->id))], true))) {
                return $guild;
            }
        }

        $partial = array_values(array_filter($guilds, fn (RuxGuild $guild): bool => $wanted !== ''
            && (str_contains($plain($guild->hostName()), $wanted) || str_contains($plain($this->access->serverName($guild->id)), $wanted))));

        if (count($partial) === 1) {
            return $partial[0];
        }

        throw new InvalidArgumentException('rux works on these servers: ' . implode('; ', array_map(static fn (RuxGuild $g): string => $g->label(), $guilds)) . '. Say which one is meant.');
    }
}
