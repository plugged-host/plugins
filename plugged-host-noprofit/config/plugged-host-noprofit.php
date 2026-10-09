<?php

return [
    /*
     * noprofit.space integration: single sign-on, the account link and the signed internal
     * API client (Services\NoprofitClient).
     *
     * Each direction has its own secret and every token names its audience, so an SSO token
     * cannot be replayed as a migration token or the other way round. No secret has a
     * default: an unset secret rejects every token rather than falling back to one that is
     * readable in git.
     *
     * resolve_ip / ca_bundle are optional: point accounts.noprofit.space at the loopback
     * address (with the Cloudflare Origin CA bundle to verify its certificate) so internal
     * calls never leave the box and never meet Cloudflare's WAF.
     */
    'accounts_url' => rtrim((string) env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space'), '/'),
    'sso_secret' => (string) env('NOPROFIT_SSO_SECRET', ''),
    'migration_secret' => (string) env('NOPROFIT_MIGRATION_SECRET', ''),
    // Credit balances and Discord links, both directions. Separate from the migration
    // secret so a leak can move balances but never sign anyone in or delete an account.
    'sync_secret' => (string) env('NOPROFIT_SYNC_SECRET', ''),
    'migration_callback' => (string) (env('NOPROFIT_MIGRATION_CALLBACK')
        ?: rtrim((string) env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space'), '/').'/auth/plugged-host/callback'),
    'migration_ttl' => 900,
    'resolve_ip' => env('NOPROFIT_RESOLVE_IP'),
    'ca_bundle' => env('NOPROFIT_CA_BUNDLE'),
];
