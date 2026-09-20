<?php

/*
    plugged.host Economy — Deutsch.

    Muss Schlüssel für Schlüssel zu en/strings.php passen: ein hier fehlender
    Schlüssel wird dem Nutzer als roher Pfad angezeigt.
*/

return [
    /*
        Der Name der Seite, und die einzige Stelle, an der "Economy" definiert wird.
        Die Klasse heißt weiter ResourceStorePage und der Slug weiter `store` — die
        Umbenennung ist reine Beschriftung. "Ressourcen-Shop" bleibt als
        `credits.resource_store`, weil damit der Upgrade-Abschnitt gemeint ist.
    */
    'economy' => [
        'title' => 'Economy',
        'nav_label' => 'Economy',
        'subheading' => 'Deine Guthaben, alles was sie kaufen, und wie du mehr davon bekommst.',
        'earn_heading' => 'Tokens verdienen',
    ],

    'credits' => [
        'credits' => 'Guthaben',
        'credits_short' => 'Cr',
        'resource_store' => 'Ressourcen-Shop',
        'store_subheading' => 'Erweitere deine Serverressourcen und Limits mit Guthaben aus Discord-Belohnungen und Aktivitäten.',
        'store_description' => 'Limits im Shop upgraden',
        'current_balance' => 'Aktuelles Guthaben',
        'balance_hint' => 'Verdiene Guthaben durch Discord-Verknüpfung, Einladen von Mitgliedern oder Server-Boosts.',
        'earn_discord_rewards' => 'Discord-Belohnungen verdienen',
        'available_upgrades' => 'Ressourcen-Upgrades',
        'available_upgrades_description' => 'Erweitere dein persönliches Kontingent. Aufgerüstete Ressourcen bleiben dauerhaft auf deinem Konto.',
        'max_tier' => 'Maximalstufe',
        'limit' => 'Aktuell',
        'max' => 'Max. Limit',
        'current_limit' => 'Aktuelles Limit',
        'servers_count' => 'Server',
        'server_slot' => 'Server-Slot',
        'cpu_upgrade_desc' => 'Erhöhe dein CPU-Limit auf bis zu 600% für noch leistungsstärkere Server.',
        'memory_upgrade_desc' => 'Erhöhe dein RAM-Limit auf bis zu 24 GB für größere Modpacks und Spielerzahlen.',
        'disk_upgrade_desc' => 'Erhöhe deinen Speicherplatz auf bis zu 100 GB für Welten und Backups.',
        'server_slot_upgrade_desc' => 'Erhöhe die Anzahl der Server, die du gleichzeitig erstellen kannst.',
        'confirm_purchase' => 'Möchtest du :item wirklich für :cost Guthaben kaufen?',
        'purchase_successful' => 'Kauf erfolgreich',
        'purchase_failed' => 'Kauf fehlgeschlagen',
        'insufficient_credits' => 'Du hast nicht genügend Guthaben für diesen Kauf.',
        'max_cpu_reached' => 'Du hast das maximale CPU-Limit (600%) bereits erreicht.',
        'max_memory_reached' => 'Du hast das maximale RAM-Limit (24 GB) bereits erreicht.',
        'max_disk_reached' => 'Du hast das maximale Speicherplatz-Limit (100 GB) bereits erreicht.',
        'cpu_purchased' => 'Dein CPU-Limit wurde erfolgreich um +:amount% für :cost Guthaben erhöht.',
        'memory_purchased' => 'Dein RAM-Limit wurde erfolgreich um +:amount :unit für :cost Guthaben erhöht.',
        'disk_purchased' => 'Dein Speicherplatz wurde erfolgreich um +:amount :unit für :cost Guthaben erhöht.',
        'server_slot_purchased' => 'Es wurden erfolgreich :slots Server-Slot(s) für :cost Guthaben hinzugefügt.',
        'tx_cpu_purchase' => '+:amount% CPU-Limit Upgrade gekauft',
        'tx_memory_purchase' => '+:amount :unit RAM-Limit Upgrade gekauft',
        'tx_disk_purchase' => '+:amount :unit Speicherplatz Upgrade gekauft',
        'tx_server_slot_purchase' => '+:slots Server-Slot Upgrade gekauft',
        'recent_transactions' => 'Letzte Transaktionen',
        'no_transactions' => 'Noch keine Guthaben-Transaktionen vorhanden.',
        'date' => 'Datum',
        'description' => 'Beschreibung',
        'amount' => 'Betrag',
        'balance' => 'Kontostand',
        'need_more_upgrade_store' => 'Mehr nötig? Im Ressourcen-Shop upgraden',

        // Wird angezeigt, wenn bestehende Server mehr belegen als das aktuelle Limit
        // erlaubt — Rolle entfernt, gesenkt oder Ressourcen zurückgeholt.
        'over_quota' => 'Limit um :amount überschritten — Server verkleinern oder löschen',
        'over_quota_slots' => 'Server-Limit um :amount überschritten — Server löschen',
        'tx_economy_reset' => 'Guthaben-Wirtschaft zurückgesetzt',
        'tx_invite_clawback' => 'Invite-Belohnung zurückgenommen — eingeladenes Mitglied hat Discord verlassen',
        'tx_resource_reclaim' => 'Gekaufte Ressourcen zurückgeholt, um eine zurückgenommene Belohnung zu decken',
    ],

    'tokens' => [
        'tokens' => 'Tokens',
        'tokens_short' => 'Tk',
        'balance' => 'Token-Guthaben',
        'balance_hint' => 'Tokens kaufen Vorrang in der Start-Warteschlange. Verdiene sie über einen gesponserten Link.',
        'earn_tokens' => 'Tokens verdienen',
        'insufficient' => 'Du hast nicht genügend Tokens dafür.',
        'tx_daily' => 'Täglicher Token',
        'what_credits_buy' => 'CPU, RAM, Speicher und Server-Plätze — dauerhaft.',
        'what_tokens_buy' => 'Vorrang in der Start-Warteschlange, für einige Tage.',
    ],

    /*
        Der Vorrangspass, verkauft hier und auf der Warteseite der Warteschlange.
        Preise und Laufzeiten stehen absichtlich nicht in dieser Datei — sie kommen
        aus der Konfiguration von plugged-host-queue.
    */
    'priority' => [
        'heading' => 'Warteschlange überspringen',
        'description' => 'Wenn ein Node ausgelastet ist, werden Starts in eine Reihe gestellt. Vorrang setzt jeden Start deines Kontos vor alle ohne Vorrang — für mehrere Tage und auf allen deinen Servern, nicht nur einmal.',
        'active_heading' => 'Vorrang ist aktiv',
        'active_body' => 'Deine Starts gehen die nächsten :remaining vor allen ohne Vorrang.',
        'active_ad_free' => 'Werbung ist im ganzen Panel für die nächsten :remaining abgeschaltet.',
        'extend_hint' => 'Ein weiterer Kauf verlängert dein Fenster, statt es zu ersetzen.',
        'tier_standard' => 'Vorrang',
        'tier_ad_free' => 'Vorrang, werbefrei',
        'tier_days' => ':days Tage',
        'badge_ads' => 'Mit Werbung',
        'badge_no_ads' => 'Ohne Werbung',
        'perk_queue' => 'Jeder Start überspringt die Reihe',
        'perk_transfer' => 'Schaltet den Umzug auf ein ruhigeres Node frei',
        'perk_ads_stay' => 'Werbung bleibt wie sie ist',
        'perk_no_ads' => 'Keine Banner, keine Popups, keine Adblock-Prüfung und kein Wartebildschirm vor dem Start',
        'cta' => ':cost Tokens',
        'confirm' => ':cost Tokens für :days Tage Vorrang ausgeben?',
        'balance' => 'Du hast :balance Tokens.',
        'earn_link' => 'Mehr Tokens holen',
        'notify_bought' => 'Vorrang ist für die nächsten :days Tage aktiv.',
        'notify_bought_ad_free' => 'Vorrang ist für :days Tage aktiv, und Werbung ist im ganzen Fenster abgeschaltet.',
        'notify_short' => 'Dafür brauchst du :cost Tokens.',
        'notify_unavailable' => 'Vorrang ist momentan nicht verfügbar.',
        'notify_failed' => 'Beim Aktivieren des Vorrangs ist etwas schiefgegangen. Es wurden keine Tokens abgebucht.',
    ],

    /*
    | Der Economy-Tab auf der Benutzerseite im Adminbereich. Für den Support, nicht
    | für Endnutzer: die Formulierungen setzen voraus, dass klar ist, was ein Ledger
    | ist — und müssen deutlich machen, dass hier eins geschrieben wird.
    */
    'admin' => [
        'tab' => 'Economy',
        'credits_heading' => 'Credits',
        'credits_hint' => 'Credits kaufen CPU, RAM, Speicher und Serverplätze im Shop. Verdient über Discord-Einladungen und Boosts.',
        'tokens_heading' => 'Tokens',
        'tokens_hint' => 'Tokens kaufen Vorrang in der Startwarteschlange. Verdient über gesponserte Links.',
        'balance_current' => 'Guthaben: :amount',
        'balance_unavailable' => 'Kein Guthaben vorhanden',
        'set_credits' => 'Credits setzen',
        'set_tokens' => 'Tokens setzen',
        'modal_credits' => 'Credit-Guthaben setzen',
        'modal_tokens' => 'Token-Guthaben setzen',
        'modal_description' => 'Die Differenz wird als Korrektur ins Ledger geschrieben, damit Guthaben und Transaktionsverlauf nicht auseinanderlaufen können. Wird ein Guthaben auf seinen aktuellen Wert gesetzt, wird nichts geschrieben.',
        'balance_field' => 'Neues Guthaben',
        'balance_field_hint' => 'Aktuell :amount.',
        'note_field' => 'Notiz',
        'note_field_hint' => 'Optional. Wird neben deinem Namen im Ledger-Eintrag gespeichert — eine Ticketnummer genügt meist.',
        'tx_description' => 'Korrigiert von :admin',
        'tx_description_note' => 'Korrigiert von :admin — :note',
        'notify_set' => 'Guthaben auf :amount gesetzt.',
        'notify_unchanged' => 'Das Guthaben beträgt bereits :amount. Es wurde nichts geschrieben.',
        'notify_failed' => 'Die Korrektur ist nicht durchgelaufen. Es wurde nichts geändert.',
    ],
    // Tokens — earn page + ledger (moved here from plugged-host-ads, WP1 earn-half)
    // Tokens — the earn page
    'tokens_nav' => 'Tokens',
    'tokens_title' => 'Tokens',
    'tokens_subheading' => 'Tokens verdienen und vorrücken, wenn die Nodes ausgelastet sind',
    'tokens_balance' => ':count Tokens',
    'tokens_explainer' => 'Tokens kaufen Vorrang in der Start-Warteschlange: :cost davon setzen dich für :days Tage vor alle ohne Vorrang, auf allen deinen Servern. Eine teurere Stufe im Ressourcen-Shop kauft ein kürzeres Fenster, das zusätzlich jede Werbung abschaltet — auch die werbefinanzierte Wartezeit vor dem Start eines großen Servers.',
    'tokens_none' => 'Derzeit ist keine Möglichkeit zum Verdienen von Tokens eingerichtet.',
    'tokens_reward' => ':count Tokens pro Abschluss',
    'tokens_used' => ':used von :cap heute',
    'tokens_capped' => 'Komm morgen wieder',
    'tokens_link_unavailable' => 'Gerade nicht verfügbar',
    'tokens_history' => 'Letzte Vorgänge',
    'tokens_earn_with' => 'Mit :provider verdienen',

    /*
        Der Block, den dieses Plugin auf der Economy-Seite von plugged-host-economy
        unter dem Token-Guthaben rendert. Dieselben Angebote wie auf der Verdienen-Seite;
        die Schaltfläche verlinkt dorthin und ist nicht selbst der gesponserte Link —
        der bringt nur etwas, wenn linkvertise.js ihn umgeschrieben hat, und das
        passiert nur dort, wo das Skript geladen wird.
    */
    'tokens_store_heading' => 'Tokens verdienen',
    'tokens_store_hint' => 'Einen gesponserten Link abschließen — die Tokens sind auf deinem Guthaben, sobald du zurück bist.',

    // Tokens — ledger descriptions
    'start_provider_linkvertise' => 'Linkvertise',
    'tokens_tx_earn' => 'Mit :provider verdient',

    // Tokens — results
    'tokens_result_granted' => 'Hinzugefügt: +:amount Tokens',
    'tokens_result_settled' => 'Das wurde bereits ausgezahlt',
    'tokens_result_expired' => 'Das hat zu lange gedauert — bitte erneut versuchen',
    'tokens_result_no_token' => 'Der Anbieter hat dich ohne Abschluss-Code zurückgeschickt',
    'tokens_result_no_claim' => 'Kein offener Vorgang für dein Konto gefunden',
    'tokens_result_replay' => 'Dieser Abschluss-Code wurde schon verwendet',
    'tokens_result_capped' => 'Du hast das heutige Limit für diese Möglichkeit erreicht',
    'tokens_result_throttled' => 'Zu viele Versuche — bitte etwas langsamer',
    'tokens_result_invalid' => 'Das konnte nicht geprüft werden, es gab keine Tokens',
    'tokens_result_error' => 'Etwas ist schiefgelaufen. Es wurden keine Tokens hinzugefügt.',

];
