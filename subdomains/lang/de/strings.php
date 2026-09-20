<?php

return [
    'no_domains' => 'Keine Domains',
    'domain' => 'Domain|Domains',

    'no_subdomains' => 'Keine Subdomains',
    'subdomain' => 'Subdomain|Subdomains',
    'limit' => 'Limit',
    'change_limit' => 'Limit anpassen',
    'limit_changed' => 'Limit angepasst',
    'limit_reached' => 'Subdomain Limit erreicht',
    'create_subdomain' => 'Subdomain erstellen',

    'name' => 'Name',
    'record_type' => 'Record Typ',
    'is_synced' => 'Ist synchronisiert?',
    'srv_target' => 'SRV Ziel',
    'no_srv_target' => 'Kein SRV Ziel',

    'sync' => 'Synchronisieren',

    'api_token' => 'Cloudflare API Token',
    'api_token_help' => 'Der Token benötigt Leseberechtigung für Zone.Zone und Schreibberechtigung für Zone.Dns. Für eine verbesserte Sicherheit können mit "Zone Resources" bestimmte Domains ausgeschlossen werden und die Panel-IP zum "Client IP Adress Filtering" hinzugefügt werden.',
    'api_token_set_help' => 'Es ist aktuell ein Cloudflare API Token hinterlegt. Lasse das Feld leer, um ihn beizubehalten, oder gib einen neuen Token ein, um ihn zu ersetzen.',
    'api_token_unset_help' => 'Es ist noch kein Cloudflare API Token hinterlegt. Gib einen ein, um die Cloudflare-DNS-Synchronisierung zu aktivieren.',
    'secret_unchanged' => '(unverändert)',
    'secret_unset' => 'Nicht gesetzt',

    'dual_records' => 'A- und AAAA-Records erstellen',
    'dual_records_help' => 'Erstellt sowohl A- als auch AAAA-DNS-Records für Subdomains, wenn der Node sowohl IPv4- als auch IPv6-Adressen bereitstellt.',

    'srv_service_section' => 'SRV-Diensterkennung',
    'srv_service_section_help' => 'Ordne Egg-IDs Diensttypen zu, damit die passenden SRV-Records (z.B. Minecraft oder FiveM) automatisch erstellt werden.',
    'minecraft_eggs' => 'Minecraft Egg-IDs',
    'minecraft_eggs_help' => 'Kommagetrennte Liste von Egg-IDs, die für die SRV-Record-Erstellung als Minecraft-Server behandelt werden (Standard: 12).',
    'fivem_eggs' => 'FiveM Egg-IDs',
    'fivem_eggs_help' => 'Kommagetrennte Liste von Egg-IDs, die für die SRV-Record-Erstellung als FiveM-Server behandelt werden (Standard: 11).',

    'subdomain_blacklist' => 'Subdomain Blacklist',
    'subdomain_blacklist_help' => "Mit diesen Einträgen können Benutzer keine Subdomains erstellen. Es werden Pattern mit '*' unterstützt, z.B. 'test*' blockiert alle Subdomains, die mit 'test' beginnen.",

    'notifications' => [
        'synced' => 'Domain wurde erfolgreich mit Cloudflare synchronisiert',
        'not_synced' => 'Domain konnte nicht mit Cloudflare synchronisiert werden',
    ],

    'validation' => [
        'on_blacklist' => ':attribute ist nicht erlaubt.',
    ],
];
