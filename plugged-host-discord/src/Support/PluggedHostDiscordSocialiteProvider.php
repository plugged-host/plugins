<?php

namespace PluggedHost\Discord\Support;

use SocialiteProviders\Discord\Provider as BaseDiscordProvider;

class PluggedHostDiscordSocialiteProvider extends BaseDiscordProvider
{
    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        $oauthUser = new DiscordOAuthUser();

        $discriminator = $user['discriminator'] ?? '0';
        $nickname = $user['username'] . ($discriminator !== '0' ? '#' . $discriminator : '');

        $oauthUser->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $nickname,
            'name' => $user['username'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $this->formatAvatar($user),
        ]);

        // Discord's /users/@me returns boolean `verified` representing whether
        // the email address on the account is verified.
        // If an attacker creates a Discord account with a victim's email and verifies
        // a phone number instead, Discord returns verified => false.
        // By mapping it explicitly to $oauthUser->email_verified, Pelican's
        // OAuthController rejects unverified emails immediately.
        $oauthUser->email_verified = !empty($user['verified']);

        return $oauthUser;
    }
}
