<?php

namespace PluggedHost\Rux\Tools;

use Illuminate\Contracts\Container\Container;
use PluggedHost\Rux\Support\RuxAccess;

/**
 * Everything rux can do. Admins get every tool; members of a rux moderator role only the ones
 * whose level() is moderator (RuxAgent never offers, nor runs, the others for them).
 */
final class RuxToolRegistry
{
    /** @var list<class-string<RuxTool>> */
    private const TOOLS = [
        Giveaways\CreateGiveawayTool::class,
        Giveaways\ListGiveawaysTool::class,
        Giveaways\EditGiveawayTool::class,
        Giveaways\EndGiveawayTool::class,
        Giveaways\CancelGiveawayTool::class,
        Giveaways\RerollGiveawayTool::class,
        Moderation\LookupMemberTool::class,
        Moderation\WarnMemberTool::class,
        Moderation\TimeoutMemberTool::class,
        Moderation\KickMemberTool::class,
        Moderation\BanMemberTool::class,
        Moderation\UnbanUserTool::class,
        Moderation\PurgeMessagesTool::class,
        Moderation\ChannelSettingsTool::class,
        Moderation\ManageRoleTool::class,
        Moderation\SendMessageTool::class,
        Moderation\SendDmTool::class,
        Moderation\ServerOverviewTool::class,
        Moderation\ClearWarningsTool::class,
        Rules\AddRuleTool::class,
        Rules\ListRulesTool::class,
        Rules\RemoveRuleTool::class,
        Rules\WatchChannelTool::class,
        Rules\ModeratorRoleTool::class,
    ];

    /** @var array<string, RuxTool>|null */
    private ?array $tools = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, RuxTool>
     */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];

            foreach (self::TOOLS as $class) {
                $tool = $this->container->make($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function find(string $name): ?RuxTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, RuxTool>
     */
    public function available(string $level): array
    {
        return $level === RuxAccess::ADMIN
            ? $this->all()
            : array_filter($this->all(), static fn (RuxTool $tool): bool => $tool->level() === RuxAccess::MODERATOR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(string $level = RuxAccess::ADMIN): array
    {
        return array_values(array_map(static fn (RuxTool $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
        ], $this->available($level)));
    }
}
