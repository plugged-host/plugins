<?php

return [
    // Status pipeline config moved to plugged-host-status in v1.2.0 (WP1/WP15).
    // user-creatable-servers + nodes config moved to plugged-host-servers in v1.2.0 (WP1).
    // Sign-up (which methods are open), the legal documents and their acceptance, the compute
    // plan and the store prices are set on noprofit.space (Admin > Settings) since 2.1.

    /*
     * The release shown in footers (Support\Release): normally the VERSION file plus the
     * plugins checkout's commit. Set it only for a deploy without .git, e.g. v2.2.0.9b53c14.
     */
    'release' => env('PLUGGED_HOST_RELEASE'),

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
];
