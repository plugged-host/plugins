<?php

namespace PluggedHost\Economy\Services\Rewards;

use Illuminate\Support\Facades\Http;
use PluggedHost\Economy\Models\TokenClaim;
use Throwable;

/**
 * Linkvertise, verified through the publisher anti-bypass API.
 *
 * Two integrations, chosen by config rather than by a mode switch: set a numeric
 * `user_id` and this is the **Full Script API**, leave it unset and a pinned
 * publisher `link` still works exactly as it did.
 *
 * Full Script is not a URL we build. It is `linkvertise.js` in the top document,
 * which rewrites anchors client-side into
 * `https://link-to.net/<user_id>/<random>/dynamic/?r=<payload>&v=2`. So in that mode
 * `buildUrl()` hands back *our own return URL* and the script turns it into the
 * outbound trip on load. Two properties of that script drive the rest of the design
 * and are worth knowing before changing any of this:
 *
 * It rewrites **every** `a[href]` in the document unless a non-empty whitelist
 * narrows it. An empty whitelist on a panel page would monetise the sidebar, the
 * console and the file manager. `whitelist()` therefore defaults to the marker our
 * return URL carries and is never allowed to be empty.
 *
 * Its patterns are not globs: it replaces every `*` with `.*` and uses the result
 * as a regex, so a `?` in a pattern is read as a quantifier and silently stops
 * matching. `whitelist()` drops any pattern carrying a regex metacharacter rather
 * than shipping one that quietly matches nothing.
 *
 * Verification is the same in both modes and is the reason this provider is
 * stricter than a plain link: the check needs a publisher API token as well, so
 * `isConfigured()` requires one — without it every completion would be
 * unverifiable and the honest thing is to not offer the provider at all. Note that
 * the token only reaches the return URL if Anti-Bypass is enabled on the account;
 * if it is not, a return trip arrives with nothing to check and is rejected as
 * `no_token`, with the parameter names it *did* carry recorded on the claim row.
 *
 * The hash Linkvertise appends is only valid for a few seconds. That is why the
 * whole redemption runs inline on the returning request and is never queued: a job
 * that starts even ten seconds late would report a genuine completion as a forgery.
 *
 * The response shape is read tolerantly (`status`, `valid`, `success`, or a bare
 * `true` body) because it is not versioned in any way we can rely on, and a
 * publisher API that starts answering with a differently-named boolean should
 * show up in the log as a rejection we can read, not as a silent grant.
 *
 * Every rejection this class reports goes through `redact()`, because `reject()` is
 * overridden to do it rather than each call site remembering to. The API token travels
 * as a query parameter and the publisher echoes it back inside failure bodies, so the
 * raw diagnostic `reject()` is designed to log is also the one place our own credential
 * would end up in plaintext on disk.
 */
class LinkvertiseProvider extends AbstractLinkProvider
{
    protected const VERIFY_ENDPOINT = 'https://publisher.linkvertise.com/api/v1/anti_bypassing';

    /** The script the Full Script API is served from. */
    public const SCRIPT_URL = 'https://publisher.linkvertise.com/cdn/linkvertise.js';

    /** Marker the return URL carries so the whitelist can match it and nothing else. */
    public const RETURN_PARAM = 'lvr';

    /** Linkvertise calls its token a "hash" in the return URL. */
    protected const TOKEN_PARAMS = ['hash', 'token', 'key', 'k'];

    /** What a credential is replaced with on its way into the log. */
    protected const REDACTED = '[redacted]';

    public function key(): string
    {
        return 'linkvertise';
    }

    public function label(): string
    {
        return trans('plugged-host-economy::strings.start_provider_linkvertise');
    }

    /**
     * The publisher id the Full Script call takes, or null when none is set.
     *
     * Its presence is what selects Full Script, so it is read strictly: a stray
     * non-numeric value falls back to the static link rather than emitting
     * `linkvertise(0, …)`, which would rewrite links to a publisher that does not
     * exist.
     */
    public function userId(): ?int
    {
        $value = trim((string) $this->config('user_id', ''));

        return $value !== '' && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function usesFullScript(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Patterns the script is allowed to rewrite.
     *
     * Never empty: an empty list means "every link on the page" to linkvertise.js,
     * so a misconfigured value falls back to the return marker instead of opening
     * the whole panel up. Metacharacters are dropped for the reason in the class
     * docblock.
     *
     * @return array<int, string>
     */
    public function whitelist(): array
    {
        $configured = array_filter(array_map(
            'trim',
            explode(',', (string) $this->config('whitelist', '')),
        ), fn (string $pattern): bool => $pattern !== ''
            && preg_match('/^[A-Za-z0-9._\/=*-]+$/', $pattern) === 1);

        return $configured === []
            ? [self::RETURN_PARAM . '=']
            : array_values($configured);
    }

    public function isConfigured(): bool
    {
        if (!(bool) $this->config('enabled', false) || $this->apiToken() === '') {
            return false;
        }

        // Full Script needs no link at all — the script builds the outbound URL
        // from the publisher id. Only the static-link mode has a link to validate.
        return $this->usesFullScript() || parent::isConfigured();
    }

    protected function apiToken(): string
    {
        return trim((string) $this->config('token', ''));
    }

    /**
     * In Full Script mode the "destination" is our own return URL: the script
     * rewrites it in the browser, so handing back anything else would send the user
     * out through a link Linkvertise never saw and earn nothing.
     */
    public function buildUrl(TokenClaim $claim, string $returnUrl): ?string
    {
        if (!$this->usesFullScript()) {
            return parent::buildUrl($claim, $returnUrl);
        }

        return preg_match('#^https?://#i', $returnUrl) === 1 ? $returnUrl : null;
    }

    public function verifyToken(string $token): bool
    {
        $apiToken = $this->apiToken();

        if ($apiToken === '') {
            return $this->reject('no_api_token');
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->post(self::VERIFY_ENDPOINT . '?token=' . rawurlencode($apiToken), [
                    'hash' => $token,
                ]);

            if (!$response->successful()) {
                return $this->reject('http_' . $response->status(), $response->body());
            }

            foreach (['status', 'valid', 'success'] as $field) {
                $value = $response->json($field);

                if ($value === true || $value === 1 || $value === 'true') {
                    return true;
                }
            }

            if (trim($response->body()) === 'true') {
                return true;
            }

            return $this->reject('not_valid', $response->body());
        } catch (Throwable $e) {
            // Fail closed, same as every other provider: no answer is a "no".
            return $this->reject('exception', $e->getMessage());
        }
    }

    /**
     * Every rejection from this provider, with our own credential taken back out of it.
     *
     * Overridden here rather than redacted at each call site, because the call sites are
     * exactly the thing that gets forgotten: the next diagnostic somebody adds would log
     * raw again, and nothing would say so. One choke point cannot be bypassed.
     *
     * Arrays are walked rather than skipped — `AbstractRewardProvider::reject()` json
     * encodes a non-scalar context, so a credential inside one reaches the log just the
     * same.
     */
    protected function reject(string $reason, mixed $context = null): bool
    {
        return parent::reject($reason, $this->redactContext($context));
    }

    protected function redactContext(mixed $context): mixed
    {
        if (is_string($context)) {
            return $this->redact($context);
        }

        if (is_array($context)) {
            return array_map(fn (mixed $value): mixed => $this->redactContext($value), $context);
        }

        return $context;
    }

    /**
     * Strip our own publisher credential out of a diagnostic.
     *
     * Every string that reaches `reject()` from here has carried it at some point.
     * The API token rides on the *request URI* (`?token=…`), which is what Guzzle
     * quotes verbatim in the message of a connection or 4xx exception; and the
     * publisher echoes it straight back inside a failure body as `user_token`. So a
     * rejection logged raw put the live credential into
     * `storage/logs/laravel-<date>.log` in plaintext, where it long outlives the
     * request and is readable by anything that can read the log.
     *
     * Three passes rather than one, and the order matters. The literal value goes
     * first because it is the only pass that cannot miss; the two patterns then catch
     * a credential this process does not know it is holding — one already rotated in
     * the dashboard, one echoed under a field name Linkvertise has not used yet. The
     * completion hash is deliberately *not* redacted: it is the user's single-use
     * token, already spent by the time this runs, and it is the one value that makes
     * a rejection diagnosable.
     */
    protected function redact(string $context): string
    {
        $apiToken = $this->apiToken();

        if ($apiToken !== '') {
            $context = str_replace(
                array_unique([$apiToken, rawurlencode($apiToken), urlencode($apiToken)]),
                self::REDACTED,
                $context,
            );
        }

        // `?token=…` / `&api_token=…` on a URI a Guzzle message quotes.
        $context = preg_replace(
            '/([?&][A-Za-z_]*(?:token|key|secret)=)[^&\s"\'<>]+/i',
            '${1}' . self::REDACTED,
            $context,
        ) ?? $context;

        // `"user_token": "…"` in a body the publisher echoed it back in.
        return preg_replace(
            '/("[A-Za-z_]*(?:token|key|secret)"\s*:\s*")[^"]*(")/i',
            '${1}' . self::REDACTED . '${2}',
            $context,
        ) ?? $context;
    }
}
