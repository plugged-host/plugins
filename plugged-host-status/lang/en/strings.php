<?php

return [
    'settings' => [
        'section_export' => 'Status site export',
        'section_export_hint' => 'Where the JSON and RSS artifacts the static status site reads are written, and the public URL used in the feed.',
        'export_path' => 'Export path',
        'export_path_hint' => 'Directory the JSON and feed.xml artifacts are written to. Leave blank to disable export — snapshots are still collected and stored.',
        'rss_base_url' => 'Status site URL',
        'rss_base_url_hint' => 'Public status site URL, used only in the RSS feed link. Leave blank if you do not publish a feed.',

        'section_probes' => 'Health probes',
        'section_probes_hint' => 'Endpoints polled each minute to check that the public site and the panel are reachable.',
        'site_health_url' => 'Site health URL',
        'site_health_url_hint' => 'Endpoint probed to check the public site. Leave blank to skip the website probe: the Website row is then left off the status page and out of the overall status.',
        'site_health_host' => 'Site health Host header',
        'site_health_host_hint' => 'Optional Host header sent with the site health probe, for when the endpoint is reached by IP.',
        'panel_health_url' => 'Panel health URL',
        'panel_health_url_hint' => 'Endpoint probed to check the panel. Leave blank to skip the panel probe: the Panel row is then left off the status page and out of the overall status.',

        'section_retention' => 'Retention & thresholds',
        'section_retention_hint' => 'How long history is kept before pruning, and when a node counts as under pressure.',
        'history_days' => 'History retention (days)',
        'history_days_hint' => 'Number of days of snapshots kept before older rows are pruned.',
        'resource_threshold_percent' => 'CPU pressure threshold (%)',
        'resource_threshold_percent_hint' => 'CPU use at or above this percentage for five checks in a row marks a node degraded. A node that is only full (memory or disk) stays operational.',
    ],

    'admin' => [
        'status_page' => 'Status page',
    ],
];
