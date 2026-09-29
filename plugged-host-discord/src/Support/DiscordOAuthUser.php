<?php

namespace PluggedHost\Discord\Support;

use SocialiteProviders\Manager\OAuth2\User as BaseOAuthUser;

/**
 * Custom Socialite OAuth User for Discord.
 *
 * Upstream Pelican's OAuthController checks:
 *   if (isset($oauthUser->email_verified) && !filter_var($oauthUser->email_verified, FILTER_VALIDATE_BOOLEAN))
 *
 * The upstream Socialite Discord provider does not declare or map `email_verified`,
 * and AbstractUser does not implement __isset(), so unverified Discord emails bypass
 * Pelican's verification check entirely. This class explicitly declares the property
 * and carries Discord's `verified` status so unverified emails are rejected.
 */
#[\AllowDynamicProperties]
class DiscordOAuthUser extends BaseOAuthUser
{
    public ?bool $email_verified = null;
}
