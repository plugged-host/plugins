<?php

namespace PluggedHost\Discord\Services;

use App\Extensions\OAuth\OAuthSchemaInterface;
use App\Extensions\OAuth\OAuthService as BaseOAuthService;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as OAuthUser;
use Livewire\Livewire;
use PluggedHost\Discord\Models\DiscordLink;

class PluggedHostOAuthService extends BaseOAuthService
{
    /**
     * @return OAuthSchemaInterface[]
     */
    public function getEnabled(): array
    {
        $enabled = parent::getEnabled();

        if (!$this->isRenderingProfilePage()) {
            return $enabled;
        }

        // On the user profile page, exclude Discord from the generic OAuth schemas
        // so if Discord is the only enabled provider, the empty OAuth tab is hidden.
        return array_values(array_filter($enabled, fn (OAuthSchemaInterface $schema) => $schema->getId() !== 'discord'));
    }

    /**
     * Whether the profile page is the thing currently being rendered.
     *
     * Keyed off the Livewire component, not the request. A Livewire update POSTs to
     * livewire-<hash>/update, so route and path matching alone never recognise the
     * profile page once it starts talking to the server -- which is why this used to
     * treat *any* request carrying the X-Livewire header as the profile page.
     *
     * That stand-in also matched the login and registration pages, and it is why the
     * Discord button vanished there: those pages render fine on the initial GET, but
     * the first Livewire round-trip -- solving the Turnstile captcha, a failed login,
     * any live field -- re-rendered the form with Discord filtered out of getEnabled(),
     * leaving Filament's Actions component with no visible children, so it emitted an
     * empty hidden div and the button was gone until a full page reload.
     *
     * Livewire pushes the component onto its stack in both the mount and update paths,
     * so Livewire::current() identifies the page correctly in both. The route checks
     * stay as a fallback for any render that happens outside a Livewire context.
     */
    private function isRenderingProfilePage(): bool
    {
        if (Livewire::current() instanceof EditProfile) {
            return true;
        }

        return request()->routeIs('*edit-profile*') || request()->is('*profile*');
    }

    public function linkUser(User $user, OAuthSchemaInterface $schema, OAuthUser $oauthUser): User
    {
        if ($schema->getId() === 'discord' && $this->discordIdIsClaimedByAnother($user, (string) $oauthUser->getId())) {
            Notification::make()
                ->danger()
                ->title(trans('plugged-host-discord::strings.link_taken_title'))
                ->body(trans('plugged-host-discord::strings.link_taken_body'))
                ->persistent()
                ->send();

            Log::warning('[Discord] Refused to link an already-claimed Discord account', [
                'attempted_by_user' => $user->id,
                'discord_id' => (string) $oauthUser->getId(),
            ]);

            return $user;
        }

        $user = parent::linkUser($user, $schema, $oauthUser);

        if ($schema->getId() === 'discord') {
            /** @var DiscordVerificationService $verificationService */
            $verificationService = app(DiscordVerificationService::class);

            $rawUser = method_exists($oauthUser, 'getRaw') ? $oauthUser->getRaw() : ($oauthUser->user ?? []);

            $username = $oauthUser->getNickname() ?: $oauthUser->getName() ?: ($rawUser['username'] ?? null);
            $avatar = $oauthUser->getAvatar() ?: ($rawUser['avatar'] ?? null);

            $verificationService->saveOAuthCredentials(
                $user,
                (string) $oauthUser->getId(),
                (string) ($oauthUser->token ?? ''),
                $oauthUser->refreshToken ?? null,
                $oauthUser->expiresIn ?? null,
                [
                    'username' => $username,
                    'avatar' => $avatar,
                ]
            );

            // Automatically verify guild membership and assign role immediately
            $verificationService->syncUser($user);
        }

        return $user;
    }

    public function unlinkUser(User $user, OAuthSchemaInterface $schema): User
    {
        $user = parent::unlinkUser($user, $schema);

        if ($schema->getId() === 'discord') {
            app(DiscordVerificationService::class)->unlinkUser($user);
        }

        return $user;
    }

    /**
     * True when this Discord account already belongs to a different panel account.
     *
     * One Discord = one panel account: `discord_links.discord_id` is unique at the schema
     * level, so without this check the second account to authorise the same Discord user
     * would hit a raw constraint violation — after `parent::linkUser()` had already
     * written `users.oauth`, leaving the two stores disagreeing about who owns the id.
     * Both stores are checked because either one alone can hold a stale claim.
     */
    protected function discordIdIsClaimedByAnother(User $user, string $discordId): bool
    {
        if ($discordId === '') {
            return false;
        }

        $claimedByLink = DiscordLink::where('discord_id', $discordId)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($claimedByLink) {
            return true;
        }

        return User::query()
            ->whereKeyNot($user->id)
            ->whereJsonContains('oauth->discord', $discordId)
            ->exists();
    }
}
