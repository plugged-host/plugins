<?php

namespace PluggedHost\Economy\Services\Rewards;

use PluggedHost\Economy\Models\TokenClaim;

/**
 * Shared plumbing for the link-shortener providers.
 *
 * One provider uses it today. It stays a base class because everything in here is
 * about the *shape* of a shortener trip rather than about Linkvertise — a
 * configured publisher link, a token appended to the destination, an API call to
 * check it — so a second shortener is a subclass with its own config prefix and its
 * own verification call, not another copy of this.
 */
abstract class AbstractLinkProvider extends AbstractRewardProvider
{
    /**
     * Query parameters a token might arrive under.
     *
     * These are set by the provider's dashboard, not by us, and the naming is not
     * consistent between them or stable over time — so read a small allow-list
     * rather than one hard-coded name. Anything not on the list is ignored, which
     * is why a misconfigured destination shows up as "no token found" instead of
     * as a payout for an empty string.
     */
    protected const TOKEN_PARAMS = ['token', 'hash', 'key', 'k'];

    /** Bound so a hung provider cannot hold a web worker open. */
    protected const HTTP_TIMEOUT = 8;

    /**
     * A link that is not `https://` cannot be built into a redirect, so treating
     * it as configured would put a card on the earn page whose only outcome is an
     * error notification. Fail the check here instead and the provider simply
     * does not appear.
     */
    public function isConfigured(): bool
    {
        return (bool) $this->config('enabled', false) && preg_match('#^https://#i', $this->link()) === 1;
    }

    protected function link(): string
    {
        return trim((string) $this->config('link', ''));
    }

    /**
     * The configured link, with the return URL substituted if the admin marked a
     * place for it.
     *
     * A publisher can pin the destination in their own dashboard, which is the
     * normal setup for a static link and needs no placeholder at all. The
     * placeholders exist for the dynamic-link formats: `{return}` for a URL-encoded
     * target, `{return_b64}` for the base64 form Linkvertise's `?r=` parameter
     * expects.
     */
    public function buildUrl(TokenClaim $claim, string $returnUrl): ?string
    {
        $link = $this->link();

        if ($link === '' || !preg_match('#^https://#i', $link)) {
            return null;
        }

        return str_replace(
            ['{return}', '{return_b64}', '{nonce}'],
            [rawurlencode($returnUrl), base64_encode($returnUrl), rawurlencode($claim->nonce)],
            $link,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function extractToken(array $input): ?string
    {
        foreach (static::TOKEN_PARAMS as $param) {
            $value = $input[$param] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            // Provider tokens are opaque and alphanumeric-with-dashes. Bounding
            // the shape here keeps a hostile query string from reaching an HTTP
            // client or a log line as anything other than a token.
            if ($value !== '' && preg_match('/^[A-Za-z0-9_-]{8,255}$/', $value)) {
                return $value;
            }
        }

        return null;
    }
}
