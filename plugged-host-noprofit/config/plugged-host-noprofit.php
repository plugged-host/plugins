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
    /*
     * Which noprofit.space sign-in client this panel is. The production panel is `plugged-host`
     * (its tokens may name the panel user an account is linked to). A dev or staging panel is a
     * client of its own, e.g. `plugged-host-dev` with audience `plugged-host-dev:sso`: its
     * tokens carry only the account, it matches users by account or email (sso_link_by_email)
     * and never reports links back, so it cannot touch production's links.
     */
    'sso_client' => (string) env('NOPROFIT_SSO_CLIENT', 'plugged-host'),
    'sso_audience' => (string) env('NOPROFIT_SSO_AUDIENCE', 'plugged-host:sso'),
    'sso_link_by_email' => (bool) env('NOPROFIT_SSO_LINK_BY_EMAIL', false),
    'migration_secret' => (string) env('NOPROFIT_MIGRATION_SECRET', ''),
    // Credit balances and Discord links, both directions. Separate from the migration
    // secret so a leak can move balances but never sign anyone in or delete an account.
    'sync_secret' => (string) env('NOPROFIT_SYNC_SECRET', ''),
    'migration_callback' => (string) (env('NOPROFIT_MIGRATION_CALLBACK')
        ?: rtrim((string) env('NOPROFIT_ACCOUNTS_URL', 'https://accounts.noprofit.space'), '/').'/auth/plugged-host/callback'),
    'migration_ttl' => 900,
    'resolve_ip' => env('NOPROFIT_RESOLVE_IP'),
    'ca_bundle' => env('NOPROFIT_CA_BUNDLE'),

    /*
     * neo's AI gateway (plugged-host-ai): noprofit.space runs neo's model calls and meters its
     * limits, and hands rux neo's providers. Signs every call to /api/v1/internal/neo/* (same
     * value as NEO_GATEWAY_SECRET in noprofit.space's .env). Was plugged-host-ai.gateway_secret.
     */
    'neo_gateway_secret' => (string) env('NEO_GATEWAY_SECRET', ''),
    'neo_gateway_timeout' => (int) env('NEO_GATEWAY_TIMEOUT', 90),
    // rux fetches neo's providers again once its copy is this old (seconds).
    'neo_providers_refresh' => 60,
];
