<?php

/*
|--------------------------------------------------------------------------
| plugged.host Queue — deutsche Strings
|--------------------------------------------------------------------------
|
| Muss denselben Schlüsselsatz wie lang/en/strings.php haben. Ein Schlüssel,
| der hier fehlt, erscheint auf der Warteseite als roher Pfad — und zwar bei
| einem zahlenden Kunden.
|
| Durchgehend „du“, wie im restlichen Panel. Und keine beschönigenden
| Umschreibungen: wer nicht starten kann, ist ohnehin schon genervt.
|
*/

return [
    // Die Seite selbst.
    'page_title' => 'Warten auf den Start',
    'page_subheading' => 'Deine Startanfrage für :server ist in der Warteschlange.',

    'heading' => 'Dein Server wird vorbereitet',
    'body_start' => 'Dieser Node ist gerade ausgelastet. Dein Server startet automatisch, sobald Platz frei ist — du musst nichts drücken.',
    'body_restart' => 'Dieser Node ist gerade ausgelastet. Dein Neustart läuft automatisch, sobald Platz frei ist — bis dahin bleibt dein Server online.',

    // Platz in der Reihe.
    'position_label' => 'Dein Platz',
    'position_value' => 'Platz :position',
    'position_next' => 'Du bist als Nächstes dran',
    'position_priority' => 'Priorität',

    'waited' => 'Wartet seit :time',
    'cap_note' => 'Startet spätestens in :seconds Sekunden',
    'cap_reached' => 'Startet jetzt',

    // Warum gewartet wird — mit denselben Messwerten, auf die die Freigabe schaut.
    'reason_memory' => 'Der Arbeitsspeicher dieses Nodes liegt über :limit %. Ein weiterer Server jetzt würde alle Server darauf ausbremsen, auch deinen.',
    'reason_cpu' => 'Die CPU dieses Nodes liegt über :limit %. Ein weiterer Server jetzt würde alle Server darauf ausbremsen, auch deinen.',
    'reason_disk' => 'Der Speicherplatz dieses Nodes liegt über :limit %. Ein weiterer Server jetzt würde alle Server darauf ausbremsen, auch deinen.',
    'reason_cleared' => 'Der Node hat wieder Platz. Dein Server startet.',

    'usage_memory' => 'Arbeitsspeicher',
    'usage_cpu' => 'CPU',
    'usage_disk' => 'Speicherplatz',

    // Priorität — wofür Tokens da sind. Zwei Stufen; Preise und Laufzeiten stehen in
    // der Konfiguration, nicht hier.
    'priority_plus_heading' => 'Du hast :app Plus',
    'priority_plus_body' => 'Mit Plus stehen deine Server in dieser Schlange vor allen ohne Plus.',
    'priority_plusplus_heading' => 'Du hast :app PlusPlus',
    'priority_plusplus_body' => 'Mit PlusPlus stehen deine Server ganz vorne in dieser Schlange, noch vor Plus.',
    'plus_offer_heading' => 'Mit :app Plus nach vorne',
    'plus_offer_body' => 'Plus kostet einmalig :cost Tokens und gehört dir dauerhaft: Vorrang in dieser Schlange für alle deine Server, die Plus-Standorte und keine Werbung im Spiel.',
    'plus_offer_cta' => 'Plus holen',
    'tokens_tx_priority_replaced' => 'Rückerstattung — Prioritätspässe wurden durch :app Plus ersetzt',

    // Das Umzugsangebot.
    'transfer_heading' => 'Auf einen ruhigeren Node umziehen',
    'transfer_body' => 'Wir können diesen Server auf :node umziehen, der nicht ausgelastet ist. Deine Dateien kommen mit.',
    'transfer_note' => 'Während des Kopierens ist der Server offline, und seine Adresse ändert sich. Nach dem Umzug startet er von selbst.',
    'transfer_confirm' => "Diesen Server auf :node umziehen?\n\nDer Server bleibt offline, bis das Kopieren fertig ist, und seine IP-Adresse und sein Port ändern sich. Alles, was auf die alte Adresse zeigt — eine Domain, ein Lesezeichen, die Serverliste eines Spielers — muss angepasst werden.",
    'transfer_cta' => 'Server umziehen',
    'transfer_too_big' => 'Dieser Server ist zu groß für einen schnellen Umzug — hier zu warten geht schneller.',

    // Der bezahlte Link.
    'direct_heading' => 'Etwas für die Wartezeit',
    'direct_body' => 'Öffnet sich in einem neuen Tab. Dein Platz in der Reihe bleibt, und die Wartezeit läuft hier weiter.',
    'direct_cta' => 'Öffnen',

    // Verlassen.
    'leave_cta' => 'Abbrechen und zurück',
    'pass_note' => 'Nach dem Start überspringen die nächsten :minutes Minuten an Starts diese Seite.',

    // Einträge im Token-Verlauf. Die sieht der Nutzer in seiner eigenen Liste.

    // Benachrichtigungen.
    'notify_conflict' => 'Dieser Server kann gerade nicht gestartet werden, der Warteschlangen-Eintrag wurde deshalb entfernt.',
    'notify_transfer_started' => 'Der Umzug läuft. Dein Server ist wieder da, sobald die Dateien kopiert sind.',
    'notify_transfer_no_node' => 'Es gibt gerade keinen ruhigeren Node für diesen Server.',
    'notify_transfer_conflict' => 'Dieser Server ist gerade mit etwas anderem beschäftigt und kann nicht umgezogen werden.',
    'notify_transfer_unavailable' => 'Umzüge sind derzeit nicht verfügbar.',
    'notify_transfer_failed' => 'Der Umzug konnte nicht gestartet werden. Es hat sich nichts geändert.',

    /*
    |--------------------------------------------------------------------------
    | Einstellungen im Admin-Bereich
    |--------------------------------------------------------------------------
    |
    | Admin-Bereich → Plugins → plugged.host Queue. Die Hilfetexte sind für
    | jemanden geschrieben, der den Quellcode nicht gelesen hat: sie sagen, was
    | die Zahl mit einem echten Nutzer macht, nicht auf welches Feld sie zeigt.
    |
    */
    'section_general' => 'Start-Warteschlange',
    'section_general_desc' => 'Jeder Start eines nicht ausgenommenen Nutzers wartet kurz auf einer Seite und wird pro Node einzeln freigegeben, sobald der Node unter der Auslastungsgrenze ist — so macht ein weiterer Container nicht alle Server auf einem ausgelasteten Node schlechter.',
    'enabled' => 'Starts einreihen',
    'enabled_helper' => 'Jeder Start eines nicht ausgenommenen Nutzers ohne Pass wartet mindestens die Mindestwartezeit und geht dann pro Node im Freigabeabstand raus, solange der Node unter der Auslastungsgrenze ist. Aus gehen Starts immer direkt durch.',
    'include_restart' => 'Neustarts ebenfalls einreihen',
    'include_restart_helper' => 'Ein Neustart fährt einen Container wieder hoch und kostet damit dieselbe Luft wie ein Start. Der Server läuft während des Wartens weiter.',
    'exempt_roles_skip' => 'Ausgenommene Rollen überspringen die Warteschlange',
    'exempt_roles_skip_helper' => 'Nutzt die Rollenliste aus dem Plugin plugged.host Ads statt einer zweiten eigenen. Root-Admins überspringen immer.',
    'pressure_dimensions' => 'Was als ausgelastet gilt',
    'pressure_dimensions_helper' => 'Kommagetrennt: memory, cpu, disk. Ein Node, der auf einer davon über der Auslastungsgrenze liegt, gibt niemanden frei, bis er wieder Platz hat oder die maximale Wartezeit abläuft. Von disk ist abzuraten — eine volle Platte wird nicht leerer, während jemand wartet, die Reihe würde sich also nie bewegen.',
    'threshold_percent' => 'Auslastungsgrenze',
    'threshold_percent_helper' => 'Ein Node über diesem Wert auf den Dimensionen oben hält seine Reihe an, bis er wieder darunter liegt, und bekommt so lange keine neuen Server (die Deployment-Pause in plugged.host Servers, mit niedrigerer Grenze auf kleinen Nodes). Niedriger hält Starts früher zurück; höher lässt einen Node voller werden, bevor jemand länger als die Mindestwartezeit wartet.',

    'section_timing' => 'Zeiten',
    'section_timing_desc' => 'Wie lange ein Start zurückgehalten werden darf und wie schnell Starts auf einen Node gelassen werden.',
    'min_wait' => 'Mindestwartezeit',
    'min_wait_helper' => 'Jeder eingereihte Start wartet mindestens so lange, auch auf einem ruhigen Node, damit die Seite nicht nur kurz aufblitzt.',
    'max_wait' => 'Maximale Wartezeit',
    'max_wait_helper' => 'Die Obergrenze. Danach geht der Start raus, egal wie der Node aussieht — eine Warteschlange ohne Obergrenze ist ein Ausfall mit Fortschrittsbalken. Wird automatisch angehoben, wenn sie unter der Mindestwartezeit liegt.',
    'release_interval' => 'Abstand zwischen Freigaben',
    'release_interval_helper' => 'Pro Node: pro Abstand geht ein Start raus, damit eine Reihe von Starts nie auf einmal auf einem Node landet. Einträge an der Obergrenze ignorieren das.',
    'stale_after' => 'Stille Einträge verwerfen nach',
    'stale_after_helper' => 'Nur Einträge, die nie freigebbar wurden. Ein geschlossener Tab bekommt seinen Start trotzdem — der Sweep schickt ihn los — hierüber geht also keine Anfrage verloren.',
    'pass_minutes' => 'Beide Wartezeiten überspringen für (Minuten)',
    'pass_minutes_helper' => 'Nach einer Freigabe überspringen die nächsten Starts dieses Nutzers auf diesem Server sowohl die Warteschlange als auch das Werbe-Gate. Einmal warten und dann im anderen Wartebereich landen ist das Schlimmste, was diese Funktion tun kann.',


    'section_transfer' => 'Weg von einem ausgelasteten Node',
    'section_transfer_desc' => 'Der andere Weg aus der Warteschlange: den Server auf einen Node umziehen, der nicht ausgelastet ist. Auf der Warteseite angeboten, immer vom Nutzer ausgelöst, nie automatisch.',
    'transfer_enabled' => 'Umzug anbieten',
    'transfer_enabled_helper' => 'Aus ist Warten der einzige Weg durch die Warteschlange.',
    'transfer_require_priority' => ':app Plus erforderlich',
    'transfer_require_priority_helper' => 'An gehört der Umzug zu dem, was Plus kauft. Aus kann jeder umziehen, der in der Warteschlange steht.',
    'transfer_max_disk' => 'Angebot ausblenden ab',
    'transfer_max_disk_helper' => 'Ein Umzug kopiert das ganze Datenverzeichnis. Ab ein paar Gigabyte ist das nicht mehr der schnelle Weg. Servern mit unbegrenztem Speicher wird der Umzug nie angeboten.',
    'transfer_same_location' => 'Nur gleicher Standort',
    'transfer_same_location_helper' => 'An kommen nur Nodes in Frage, die sich ein Standort-Tag mit dem aktuellen teilen. Aus ist der Pool jeder brauchbare Node — was jemanden auf einen anderen Kontinent schieben kann.',
];
