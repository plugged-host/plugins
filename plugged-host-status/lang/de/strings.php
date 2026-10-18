<?php

return [
    'settings' => [
        'section_export' => 'Export der Statusseite',
        'section_export_hint' => 'Wohin die JSON- und RSS-Artefakte geschrieben werden, die die statische Statusseite liest, und die öffentliche URL, die im Feed verwendet wird.',
        'export_path' => 'Exportpfad',
        'export_path_hint' => 'Verzeichnis, in das die Artefakte JSON und feed.xml geschrieben werden. Leer lassen, um den Export zu deaktivieren — Snapshots werden weiterhin erfasst und gespeichert.',
        'rss_base_url' => 'URL der Statusseite',
        'rss_base_url_hint' => 'Öffentliche URL der Statusseite, wird nur im Link des RSS-Feeds verwendet. Leer lassen, wenn du keinen Feed veröffentlichst.',

        'section_probes' => 'Health-Checks',
        'section_probes_hint' => 'Endpunkte, die jede Minute abgefragt werden, um zu prüfen, ob Website und Panel erreichbar sind.',
        'site_health_url' => 'Health-URL der Website',
        'site_health_url_hint' => 'Endpunkt, der abgefragt wird, um die öffentliche Website zu prüfen. Leer lassen, um den Website-Check zu überspringen: Die Zeile „Website“ fehlt dann auf der Statusseite und zählt nicht zum Gesamtstatus.',
        'site_health_host' => 'Host-Header für den Website-Check',
        'site_health_host_hint' => 'Optionaler Host-Header, der mit dem Website-Health-Check gesendet wird, falls der Endpunkt über die IP erreicht wird.',
        'panel_health_url' => 'Health-URL des Panels',
        'panel_health_url_hint' => 'Endpunkt, der abgefragt wird, um das Panel zu prüfen. Leer lassen, um den Panel-Check zu überspringen: Die Zeile „Panel“ fehlt dann auf der Statusseite und zählt nicht zum Gesamtstatus.',

        'section_retention' => 'Aufbewahrung & Schwellenwerte',
        'section_retention_hint' => 'Wie lange der Verlauf aufbewahrt wird, bevor er bereinigt wird, und ab wann ein Node als ausgelastet gilt.',
        'history_days' => 'Aufbewahrung des Verlaufs (Tage)',
        'history_days_hint' => 'Anzahl der Tage an Snapshots, die aufbewahrt werden, bevor ältere Einträge bereinigt werden.',
        'resource_threshold_percent' => 'Schwellenwert für CPU-Auslastung (%)',
        'resource_threshold_percent_hint' => 'Eine CPU-Auslastung ab diesem Prozentwert bei fünf Prüfungen in Folge markiert einen Node als beeinträchtigt. Ein Node, der nur voll ist (RAM oder Speicher), bleibt betriebsbereit.',
    ],

    'admin' => [
        'status_page' => 'Statusseite',
    ],
];
