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
    'priority_active_heading' => 'Priorität ist aktiv',
    'priority_active_body' => 'Deine Starts gehen für die nächsten :remaining allen ohne Priorität vor. Du stehst hier trotzdem, weil der Node für alle voll ist — aber du stehst vorne.',
    'priority_active_ad_free' => 'Werbung ist im ganzen Panel für die nächsten :remaining abgeschaltet.',
    'priority_heading' => 'Die Warteschlange für ein paar Tage überspringen',
    'priority_body' => 'Tokens kaufen ein Vorrangfenster für dein ganzes Konto: jeder Start geht allen ohne Vorrang vor, und der Umzug auf einen ruhigeren Node wird freigeschaltet. Ein erneuter Kauf verlängert das Fenster, statt es zu ersetzen.',
    'priority_balance' => 'Du hast :balance Tokens.',
    'priority_earn_link' => 'Mehr Tokens holen',
    'priority_cta' => ':cost Tokens',
    'priority_tier_standard' => 'Vorrang',
    'priority_tier_ad_free' => 'Vorrang, werbefrei',
    'priority_tier_days' => ':days Tage',
    'priority_badge_ads' => 'Mit Werbung',
    'priority_badge_no_ads' => 'Ohne Werbung',
    'priority_tier_ads_note' => 'Das längere Fenster. Werbung bleibt wie sie ist, auch auf dieser Seite.',
    'priority_tier_no_ads_note' => 'Kürzer, dafür wird das Panel still: keine Banner, keine Popups, keine Adblock-Prüfung und kein Wartebildschirm vor dem Start.',

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
    'tokens_tx_priority' => 'Warteschlangen-Priorität für :days Tage',
    'tokens_tx_priority_ad_free' => 'Werbefreie Warteschlangen-Priorität für :days Tage',
    'tokens_tx_priority_refund' => 'Rückerstattung — Priorität konnte nicht aktiviert werden',

    // Benachrichtigungen.
    'notify_conflict' => 'Dieser Server kann gerade nicht gestartet werden, der Warteschlangen-Eintrag wurde deshalb entfernt.',
    'notify_priority_bought' => 'Priorität ist für die nächsten :days Tage aktiv.',
    'notify_priority_bought_ad_free' => 'Priorität ist für :days Tage aktiv, und Werbung ist im ganzen Fenster abgeschaltet.',
    'notify_priority_short' => 'Für Priorität brauchst du :cost Tokens.',
    'notify_priority_unavailable' => 'Priorität ist derzeit nicht verfügbar.',
    'notify_priority_failed' => 'Beim Aktivieren der Priorität ist etwas schiefgelaufen. Es wurden keine Tokens abgebucht.',
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
    'section_general_desc' => 'Hält Starts zurück, solange ein Node keine Luft mehr hat, statt mit einem weiteren Container alle Server darauf schlechter zu machen.',
    'enabled' => 'Starts auf ausgelasteten Nodes einreihen',
    'enabled_helper' => 'Aus gehen Starts immer direkt durch. Nodes über der Grenze werden weiter gemessen, es passiert nur nichts damit.',
    'include_restart' => 'Neustarts ebenfalls einreihen',
    'include_restart_helper' => 'Ein Neustart fährt einen Container wieder hoch und kostet damit dieselbe Luft wie ein Start. Der Server läuft während des Wartens weiter.',
    'exempt_roles_skip' => 'Ausgenommene Rollen überspringen die Warteschlange',
    'exempt_roles_skip_helper' => 'Nutzt die Rollenliste aus dem Plugin plugged.host Ads statt einer zweiten eigenen. Root-Admins überspringen immer.',
    'pressure_dimensions' => 'Was als ausgelastet gilt',
    'pressure_dimensions_helper' => 'Kommagetrennt: memory, cpu, disk. Von disk ist abzuraten — eine volle Platte wird nicht leerer, während jemand wartet, die Reihe würde sich also nie bewegen. Die Grenzwerte selbst stehen im Plugin plugged.host Core.',
    'threshold_percent' => 'Auslastungsgrenze',
    'threshold_percent_helper' => 'Die eigene Grenze der Warteschlange, getrennt von der 95-%-Obergrenze des Allocators. Ein Node über diesem Wert auf den gemessenen Dimensionen schickt neue Starts durch die Warteschlange. Niedriger greift früher und lässt öfter warten; höher lässt einen Node voller werden, bevor jemand zurückgehalten wird.',
    'paced_on_unavailable' => 'Nicht messbare Nodes drosseln',
    'paced_on_unavailable_helper' => 'Lässt sich die Auslastung eines Nodes nicht messen, geht trotzdem nur ein Start pro Freigabeabstand durch statt die ganze Reihe auf einmal. Aus läuft ein nicht lesbarer Node frei — ein Ansturm auf einen ohnehin schon kämpfenden Daemon.',

    'section_timing' => 'Zeiten',
    'section_timing_desc' => 'Wie lange ein Start zurückgehalten werden darf, und wie schnell ein abgekühlter Node wieder volllaufen kann.',
    'min_wait' => 'Mindestwartezeit',
    'min_wait_helper' => 'Die Untergrenze. Ohne sie blitzt die Seite nur auf, wenn der Node zwischen Klick und erstem Poll abkühlt.',
    'max_wait' => 'Maximale Wartezeit',
    'max_wait_helper' => 'Die Obergrenze. Danach geht der Start raus, egal wie der Node aussieht — eine Warteschlange ohne Obergrenze ist ein Ausfall mit Fortschrittsbalken. Wird automatisch angehoben, wenn sie unter der Mindestwartezeit liegt.',
    'release_interval' => 'Abstand zwischen Freigaben',
    'release_interval_helper' => 'Pro Node. Ein Node, der gerade unter die Grenze gerutscht ist, hat nicht Platz für alles, was auf ihn wartet. Einträge an der Obergrenze ignorieren das.',
    'stale_after' => 'Stille Einträge verwerfen nach',
    'stale_after_helper' => 'Nur Einträge, die nie freigebbar wurden. Ein geschlossener Tab bekommt seinen Start trotzdem — der Sweep schickt ihn los — hierüber geht also keine Anfrage verloren.',
    'pass_minutes' => 'Beide Wartezeiten überspringen für (Minuten)',
    'pass_minutes_helper' => 'Nach einer Freigabe überspringen die nächsten Starts dieses Nutzers auf diesem Server sowohl die Warteschlange als auch das Werbe-Gate. Einmal warten und dann im anderen Wartebereich landen ist das Schlimmste, was diese Funktion tun kann.',

    'section_priority' => 'Priorität',
    'section_priority_desc' => 'Wofür Tokens da sind. Ein Kauf, kontoweit, für ein Zeitfenster in Tagen — kein einzelnes Überspringen. Verkauft auf der Warteseite und im Token-Shop.',
    'priority_token_cost' => 'Preis in Tokens',
    'priority_token_cost_helper' => 'Tokens werden vom Plugin plugged.host Ads ausgegeben. Dessen Verdienen-Seite liest diese Zahl, um den Preis zu erklären — hier geändert heißt dort geändert.',
    'priority_days' => 'Zeitfenster (Tage)',
    'priority_days_helper' => 'Ein erneuter Kauf bei laufendem Fenster verlängert es, statt es zu ersetzen.',
    'priority_ad_free_enabled' => 'Werbefreie Stufe zusätzlich verkaufen',
    'priority_ad_free_enabled_helper' => 'Eine zweite, teurere Stufe, die für ihr Fenster jede Werbefläche abschaltet: Banner, Social Bar, Popunder, Adblock-Prüfung und das Start-Gate. Aus wird sie nicht mehr verkauft; bereits gekaufte Fenster laufen zu Ende.',
    'priority_ad_free_cost' => 'Preis werbefrei (Tokens)',
    'priority_ad_free_cost_helper' => 'Sollte teurer sein als die normale Stufe. Sie verzichtet auf die Werbung, die die übersprungene Kapazität bezahlt.',
    'priority_ad_free_days' => 'Werbefreies Fenster (Tage)',
    'priority_ad_free_days_helper' => 'Absichtlich kürzer als das normale Fenster. Beide werden getrennt geführt: ein Kauf der normalen Stufe während eines werbefreien Fensters verlängert den Vorrang, nicht die Ruhe.',

    'section_transfer' => 'Weg von einem ausgelasteten Node',
    'section_transfer_desc' => 'Der andere Weg aus der Warteschlange: den Server auf einen Node umziehen, der nicht ausgelastet ist. Auf der Warteseite angeboten, immer vom Nutzer ausgelöst, nie automatisch.',
    'transfer_enabled' => 'Umzug anbieten',
    'transfer_enabled_helper' => 'Aus ist Warten der einzige Weg durch die Warteschlange.',
    'transfer_require_priority' => 'Priorität erforderlich',
    'transfer_require_priority_helper' => 'An gehört der Umzug zu dem, was die Tokens kaufen. Aus kann jeder umziehen, der in der Warteschlange steht.',
    'transfer_max_disk' => 'Angebot ausblenden ab',
    'transfer_max_disk_helper' => 'Ein Umzug kopiert das ganze Datenverzeichnis. Ab ein paar Gigabyte ist das nicht mehr der schnelle Weg. Servern mit unbegrenztem Speicher wird der Umzug nie angeboten.',
    'transfer_same_location' => 'Nur gleicher Standort',
    'transfer_same_location_helper' => 'An kommen nur Nodes in Frage, die sich ein Standort-Tag mit dem aktuellen teilen. Aus ist der Pool jeder brauchbare Node — was jemanden auf einen anderen Kontinent schieben kann.',
];
