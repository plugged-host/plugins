<?php

namespace PluggedHost\Ads\Services;

/**
 * The ad networks' site-verification meta tags (plugged-host-ads.verification), as HTML. Each
 * value is the content the network hands out; anything but letters, digits, `-` and `_` is
 * dropped so a pasted value can never become markup.
 */
class SiteVerification
{
    /** Config key => the meta tag's name attribute. */
    public const NETWORKS = ['monetag' => 'monetag'];

    public function tags(): string
    {
        $tags = [];

        foreach (self::NETWORKS as $key => $name) {
            $content = (string) config("plugged-host-ads.verification.{$key}", '');

            if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $content) === 1) {
                $tags[] = '<meta name="' . $name . '" content="' . $content . '">';
            }
        }

        return implode("\n", $tags);
    }
}
