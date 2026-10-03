<?php

return [
    // Status pipeline config moved to plugged-host-status in v1.2.0 (WP1/WP15).
    // user-creatable-servers + nodes config moved to plugged-host-servers in v1.2.0 (WP1).
    // Sign-up (which methods are open), the legal documents and their acceptance, the compute
    // plan and the store prices are set on noprofit.space (Admin > Settings) since 2.1.

    /*
     * The first-login welcome tour (PluggedHost\Core\Livewire\PanelTourOverlay over the server
     * list and a server's console, gated by RequirePanelTour, state in users.panel_tour via
     * Support\PanelTour; /welcome?restart=1 replays it).
     *
     * enabled is the kill switch. It is read on the request path, so turning it off in .env
     * stops the redirect for everyone immediately -- which is the thing you want to be able
     * to do without a deploy if the gate ever misbehaves.
     *
     * version re-prompts everyone when it changes, the same way a legal document's version
     * does for a changed document. Bump it when the tour is rewritten; leave it alone for wording fixes.
     */
    'tour' => [
        'enabled' => (bool) env('TOUR_ENABLED', true),
        // 2: the tour moved from a text card onto the real pages (PanelTourOverlay).
        'version' => env('TOUR_VERSION', '2'),
    ],

    /*
     * The "remember me" cookie's name (NamedRecallerSessionGuard). Leave unset on the panel whose
     * cookies cover the parent domain (prod: SESSION_DOMAIN=.plugged.host); set it on a panel
     * under that domain (dev), or it reads the other panel's cookie of the same default name.
     */
    'session' => [
        'remember_cookie' => (string) env('SESSION_REMEMBER_COOKIE', ''),
    ],

    /*
     * Sidebar entries to drop, as a comma-joined list of page/resource class names.
     *
     * Filament keys every navigation item by the class that registered it, so a
     * fully-qualified class name identifies one entry across all three panels. Read by
     * PluggedHost\Core\Filament\HiddenNavigationManager, which also always hides the two
     * upstream pages the combined Settings page replaces.
     *
     * Comma-joined rather than an array because the admin UI persists it through
     * EnvironmentWriterTrait, which writes flat strings into .env.
     *
     * Default hides Mounts and Webhooks: both are admin-oriented and mean nothing to a
     * plugged.host customer.
     */
    'navigation' => [
        'hidden' => env('NAV_HIDDEN_ITEMS', implode(',', [
            \App\Filament\Server\Pages\Mounts::class,
            \App\Filament\Server\Resources\Webhooks\WebhookResource::class,
        ])),
    ],

    /*
     * noprofit.space integration: single sign-on, the account link and the signed internal
     * API client (PluggedHost\Core\Services\NoprofitClient).
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
    'noprofit' => [
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
    ],

    /*
     * Abuse protection (PluggedHost\Core\Services\Abuse; admin page Servers > Abuse).
     *
     * mode: suspend (a critical finding suspends the server), report (record and alert only) or
     * off. It defaults to report so a fresh install, or a rewrite of the rules, shows what it
     * would do before it suspends anyone; switch it in the plugin settings.
     *
     * alert_webhook: a Discord webhook for suspensions and new critical findings (empty: none).
     *
     * cpu_percent / cpu_minutes: the CPU watch. A server at cpu_percent of its CPU limit for
     * cpu_minutes gets a review finding and the full check.
     *
     * delete_after_hours: a server the abuse checks suspended is deleted this long after the
     * suspension, unless it was unsuspended, parked by limbo or a finding on it was dismissed
     * (Services\Abuse\AbusePurge). 0 never deletes.
     */
    'abuse' => [
        'mode' => (string) env('ABUSE_MODE', 'report'),
        'alert_webhook' => (string) env('ABUSE_ALERT_WEBHOOK', ''),
        'cpu_percent' => (int) env('ABUSE_CPU_PERCENT', 90),
        'cpu_minutes' => (int) env('ABUSE_CPU_MINUTES', 20),
        'delete_after_hours' => (int) env('ABUSE_DELETE_AFTER_HOURS', 24),
    ],
];
