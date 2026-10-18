<?php

namespace PluggedHost\Ai\Support;

use PluggedHost\Core\Support\Plugins;

/**
 * Which AI providers neo's consent notice and its settings page say people's messages go to.
 *
 *  - NEO_PROVIDER_DISCLOSURE set: those words, the operator's own (e.g. "OpenAI and Anthropic");
 *  - otherwise with plugged-host-noprofit: noprofit.space's chain, which runs neo's turns there
 *    and whose providers the original texts name (consent.body, profile.privacy);
 *  - otherwise: a generic sentence about this panel's providers, which the admin set up.
 */
final class ProviderDisclosure
{
    /** The consent notice's body. */
    public static function consent(): string
    {
        return self::text('consent.body');
    }

    /** The note under neo's settings in the chat. */
    public static function privacy(): string
    {
        return self::text('profile.privacy');
    }

    private static function text(string $key): string
    {
        $providers = rtrim(trim((string) config('plugged-host-ai.provider_disclosure', '')), '. ');

        if ($providers !== '') {
            return trans("plugged-host-ai::strings.{$key}_named", ['providers' => $providers]);
        }

        return trans('plugged-host-ai::strings.' . $key . (Plugins::enabled('plugged-host-noprofit') ? '' : '_generic'));
    }
}
