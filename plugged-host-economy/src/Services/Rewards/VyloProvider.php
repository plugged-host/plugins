<?php

namespace PluggedHost\Economy\Services\Rewards;

use Illuminate\Support\Facades\Http;
use PluggedHost\Economy\Models\TokenClaim;
use Throwable;

/**
 * Vylo (vylo.cc), a Linkvertise alternative: a link shortener that shows its ads before it
 * forwards to the destination.
 *
 * There is no anti-bypass API to ask, so the destination itself is the secret. Every trip gets
 * its own short link, made through `https://vylo.cc/api?api=<key>&url=<destination>` when the
 * user clicks Earn, and the destination is our return URL carrying the claim's nonce and a proof
 * (`vt`, an HMAC of the nonce under the app key). The user only ever sees the short link; the
 * return URL is revealed at the end of the ads. verifyClaim() then checks the proof against the
 * claim it was minted for, so a hand-made `?provider=vylo&vt=…` settles nothing, and refuses a
 * trip that came back faster than `min_seconds`, which no real pass through the ads does.
 *
 * What this cannot stop is a bypass service that resolves the short link without showing the
 * ads. The daily cap and the minimum trip time bound what that is worth.
 *
 * The API key rides in the query string, so every rejection goes through redact().
 */
class VyloProvider extends AbstractLinkProvider implements VerifiesClaim
{
    protected const API_ENDPOINT = 'https://vylo.cc/api';

    /** The proof's query key on the return URL. */
    public const PROOF_PARAM = 'vt';

    protected const TOKEN_PARAMS = [self::PROOF_PARAM];

    public function key(): string
    {
        return 'vylo';
    }

    public function label(): string
    {
        return trans('plugged-host-economy::strings.start_provider_vylo');
    }

    public function isConfigured(): bool
    {
        return (bool) $this->config('enabled', false) && $this->apiKey() !== '';
    }

    protected function apiKey(): string
    {
        return trim((string) $this->config('api_key', ''));
    }

    public function minSeconds(): int
    {
        return max(0, (int) $this->config('min_seconds', 10));
    }

    /**
     * A fresh short link to our return URL with the proof attached. Null when Vylo does not
     * answer with one, which the earn page reports as "not available".
     */
    public function buildUrl(TokenClaim $claim, string $returnUrl): ?string
    {
        if (preg_match('#^https?://#i', $returnUrl) !== 1) {
            return null;
        }

        $destination = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?')
            . self::PROOF_PARAM . '=' . $this->proof($claim);

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->get(self::API_ENDPOINT, ['api' => $this->apiKey(), 'url' => $destination]);

            $short = $response->json('shortenedUrl');

            if (!$response->successful() || $response->json('status') !== 'success'
                || !is_string($short) || preg_match('#^https://\S+$#i', $short) !== 1) {
                $this->reject('shorten_failed', ['http' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

                return null;
            }

            return $short;
        } catch (Throwable $e) {
            $this->reject('shorten_exception', $e->getMessage());

            return null;
        }
    }

    /** Nothing to ask without the claim: fail closed. */
    public function verifyToken(string $token): bool
    {
        return $this->reject('needs_claim');
    }

    public function verifyClaim(TokenClaim $claim, string $token): bool
    {
        if (!hash_equals($this->proof($claim), $token)) {
            return $this->reject('bad_proof');
        }

        $elapsed = now()->getTimestamp() - $claim->created_at->getTimestamp();

        if ($elapsed < $this->minSeconds()) {
            return $this->reject('too_fast', ['seconds' => $elapsed]);
        }

        return true;
    }

    protected function proof(TokenClaim $claim): string
    {
        return substr(hash_hmac('sha256', 'vylo|' . $claim->nonce, (string) config('app.key')), 0, 40);
    }

    protected function reject(string $reason, mixed $context = null): bool
    {
        return parent::reject($reason, $this->redact($context));
    }

    /** Our API key is in the request URI that Guzzle quotes in its exception messages. */
    protected function redact(mixed $context): mixed
    {
        if (is_array($context)) {
            return array_map(fn (mixed $value): mixed => $this->redact($value), $context);
        }

        if (!is_string($context)) {
            return $context;
        }

        $key = $this->apiKey();

        if ($key !== '') {
            $context = str_replace(array_unique([$key, rawurlencode($key), urlencode($key)]), '[redacted]', $context);
        }

        return preg_replace('/([?&]api=)[^&\s"\'<>]+/i', '${1}[redacted]', $context) ?? $context;
    }
}
