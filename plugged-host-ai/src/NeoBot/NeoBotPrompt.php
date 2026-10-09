<?php

namespace PluggedHost\Ai\NeoBot;

use App\Models\User;
use Carbon\CarbonImmutable;
use PluggedHost\Core\Contracts\AccountPortal;
use Throwable;

/**
 * neo on Discord's system prompt: account support, the same plugged.host facts as the panel
 * chat, and who it is talking to (linked or not, DM or public channel, their usage).
 */
final class NeoBotPrompt
{
    /**
     * @param  array<string, mixed>  $who  noprofit.space's answer about this Discord account.
     */
    public function build(string $discordId, string $name, array $who, ?User $panelUser, bool $direct, bool $tools): string
    {
        return trim(view('plugged-host-ai::prompts.discord', [
            'discordId' => $discordId,
            'name' => $name,
            'linked' => (bool) ($who['linked'] ?? false),
            'accountName' => $who['name'] ?? null,
            'panelUser' => $panelUser,
            'limits' => is_array($who['limits'] ?? null) ? $who['limits'] : null,
            'unlinkedPercent' => (int) ($who['unlinked_percent'] ?? 50),
            'direct' => $direct,
            'tools' => $tools,
            'panelUrl' => rtrim((string) config('app.url'), '/'),
            'accountsUrl' => app(AccountPortal::class)->manageUrl() ?? rtrim((string) config('app.url'), '/'),
            'linkUrl' => NeoBotConfig::linkUrl(),
            'supportUrl' => (string) (config('plugged-host-discord.invite_url') ?: config('plugged-host-ai.support_url')),
            'resets' => static function (string $iso): string {
                try {
                    return '<t:' . CarbonImmutable::parse($iso)->getTimestamp() . ':R>';
                } catch (Throwable) {
                    return $iso;
                }
            },
        ])->render());
    }
}
