<?php

return [
    'settings' => [
        // Agent-Verbindung.
        'section_agent' => 'Storage-Agent',
        'section_agent_hint' => 'Verbindung zum Node.js-Storage-Agenten, der Spiegel abruft und signierte Archive bereitstellt.',
        'agent_url' => 'Agent-URL',
        'agent_url_hint' => 'Basis-URL, unter der der eingehende Listener des Agenten erreichbar ist, z. B. http://10.0.0.9:8687. Wird für signierte Archiv-URLs und Erreichbarkeitsprüfungen verwendet.',
        'mirror_public_key' => 'Öffentlicher SSH-Schlüssel für die Spiegelung',
        'mirror_public_key_hint' => 'Der ph-mirror-Schlüssel der Storage-Box, den ihr install.sh ausgibt. Jeder Wings-Node erlaubt ihn mit: curl -fsSL :url | sudo bash',
        'agent_secret' => 'Agent-Secret (HMAC)',
        'signed_ttl' => 'Gültigkeit signierter URLs (Sekunden)',
        'signed_ttl_hint' => 'Lebensdauer signierter URLs für Archivabruf und Prüfungen, in Sekunden.',
        'agent_version' => 'Agent-Bundle-Version',
        'agent_version_hint' => 'Aktuell vom Panel bereitgestellte Auto-Update-Bundle-Version der Agenten.',

        // Secret-Handhabung (gemeinsame Texte).
        'secret_unchanged' => 'leer lassen, um beizubehalten',
        'secret_unset' => 'nicht gesetzt',
        'secret_set_help' => 'Ein Secret ist gespeichert. Nur zum Ersetzen einen neuen Wert eingeben; leer lassen behält den aktuellen bei.',
        'secret_unset_help' => 'Es ist noch kein Secret gespeichert. Geben Sie eines ein, um authentifizierten Agent-Verkehr zu aktivieren.',

        // Replikation.
        'section_replication' => 'Replikation',
        'section_replication_hint' => 'Takt und Aufbewahrung der Delta-Spiegel. Das Panel plant, der Agent setzt um.',
        'mirror_interval_min' => 'Spiegel-Intervall (Minuten)',
        'mirror_interval_min_hint' => 'Wie oft Delta-Spiegel laufen, in Minuten.',
        'mirror_snapshots' => 'Aufbewahrte Spiegel-Snapshots',
        'mirror_snapshots_hint' => 'Anzahl der rollierenden Spiegel-Snapshots, die behalten werden.',
        'min_free_percent' => 'Minimaler freier Speicher (%)',
        'min_free_percent_hint' => 'Replikation verweigern, wenn der freie Speicher unter diesen Prozentsatz fällt.',
        'daemon_base' => 'Daemon-Volume-Basis',
        'daemon_base_hint' => 'Standard-Volume-Basispfad von Wings; eine Pro-Node-Spalte überschreibt ihn, sofern vorhanden.',

        // Backup / S3.
        'section_backup' => 'Tägliches Backup',
        'section_backup_hint' => 'Tägliches, im Panel sichtbares S3-Backup über den Backup-Dienst gegen einen S3-Backup-Host.',
        'daily_backup_enabled' => 'Tägliches Backup aktivieren',
        'daily_backup_enabled_hint' => 'Das im Panel sichtbare tägliche S3-Backup ausführen.',
        'daily_backup_hour' => 'Stunde des täglichen Backups (0-23)',
        'daily_backup_hour_hint' => 'Tagesstunde, zu der das tägliche Backup läuft, in lokaler 24-Stunden-Zeit.',

        // Failover.
        'section_failover' => 'Failover',
        'section_failover_hint' => 'Wiederherstellung bei Node-Ausfall. Auto wechselt automatisch vom letzten Backup, sobald ein Node über das Erkennungsfenster hinaus offline ist.',
        'failover_mode' => 'Failover-Modus',
        'failover_mode_hint' => 'Aus deaktiviert die Wiederherstellung; Nur benachrichtigen warnt lediglich; Automatisch wechselt automatisch vom letzten Backup.',
        'failover_mode_off' => 'Aus',
        'failover_mode_notify' => 'Nur benachrichtigen',
        'failover_mode_auto' => 'Automatisch',
        'failover_after_failures' => 'Failover nach N Fehlversuchen',
        'failover_after_failures_hint' => 'Aufeinanderfolgende fehlgeschlagene Prüfungen, bevor ein Node als ausgefallen gilt.',
        'failover_max_per_run' => 'Max. Failover pro Lauf',
        'failover_max_per_run_hint' => 'Sicherheitsgrenze, wie viele Nodes in einem Scheduler-Lauf umschalten dürfen.',
    ],
];
