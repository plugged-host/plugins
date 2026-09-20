<?php

return [
    'legal' => [
        'terms_url' => env('LEGAL_TERMS_URL', ''),
        'privacy_url' => env('LEGAL_PRIVACY_URL', ''),
        'terms_version' => env('LEGAL_TERMS_VERSION', '2026-08-21'),
        'privacy_version' => env('LEGAL_PRIVACY_VERSION', '2026-08-21'),
    ],

    // Status pipeline config moved to plugged-host-status in v1.2.0 (WP1/WP15).
    // user-creatable-servers + nodes config moved to plugged-host-servers in v1.2.0 (WP1).

    'register' => [
        'password_registration_enabled' => (bool) env('REGISTER_PASSWORD_REGISTRATION_ENABLED', true),
        'oauth_registration_enabled' => (bool) env('REGISTER_OAUTH_REGISTRATION_ENABLED', true),
    ],

    /*
     * The first-login welcome tour (PluggedHost\Core\Filament\App\Pages\WelcomeTourPage,
     * gated by RequirePanelTour, state in users.panel_tour via Support\PanelTour).
     *
     * enabled is the kill switch. It is read on the request path, so turning it off in .env
     * stops the redirect for everyone immediately -- which is the thing you want to be able
     * to do without a deploy if the gate ever misbehaves.
     *
     * version re-prompts everyone when it changes, the same way LEGAL_*_VERSION does for a
     * changed document. Bump it when the tour is rewritten; leave it alone for wording fixes.
     */
    'tour' => [
        'enabled' => (bool) env('TOUR_ENABLED', true),
        'version' => env('TOUR_VERSION', '1'),
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
