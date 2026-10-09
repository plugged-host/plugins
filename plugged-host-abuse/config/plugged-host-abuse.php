<?php

return [
    /*
     * Abuse protection (PluggedHost\Abuse\Services; admin page Servers > Abuse).
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
     * (Services\AbusePurge). 0 never deletes.
     */
    'mode' => (string) env('ABUSE_MODE', 'report'),
    'alert_webhook' => (string) env('ABUSE_ALERT_WEBHOOK', ''),
    'cpu_percent' => (int) env('ABUSE_CPU_PERCENT', 90),
    'cpu_minutes' => (int) env('ABUSE_CPU_MINUTES', 20),
    'delete_after_hours' => (int) env('ABUSE_DELETE_AFTER_HOURS', 24),
];
