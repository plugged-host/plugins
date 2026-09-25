<?php

namespace PluggedHost\Neo\Agent;

use App\Services\Servers\GetUserPermissionsService;
use PluggedHost\Neo\Support\NeoPreferences;
use PluggedHost\Neo\Tools\ToolContext;
use Throwable;

/**
 * neo's system prompt. The static part comes first and never varies between turns, so
 * providers that cache prompt prefixes can reuse it; the per-conversation facts (server,
 * permissions, approval mode) come last.
 */
final class SystemPromptBuilder
{
    public function build(ToolContext $context): string
    {
        $server = $context->server;
        $permissions = [];

        if ($server !== null) {
            try {
                $permissions = app(GetUserPermissionsService::class)->handle($server, $context->user);
            } catch (Throwable) {
                $permissions = [];
            }
        }

        return trim(view('plugged-host-neo::prompts.system', [
            'user' => $context->user,
            'server' => $server,
            'permissions' => in_array('*', $permissions, true) ? ['all (server owner)'] : $permissions,
            'autoApprove' => NeoPreferences::autoApprove($context->user),
            'supportUrl' => (string) config('plugged-host-neo.support_url'),
            'language' => (string) ($context->user->language ?: 'en'),
        ])->render());
    }
}
