<?php

/*
    plugged.host Economy — Deutsch.

    Muss Schlüssel für Schlüssel zu en/strings.php passen: ein hier fehlender
    Schlüssel wird dem Nutzer als roher Pfad angezeigt.
*/

return [
    /*
        Die zwei Shop-Seiten. Guthaben kauft Ressourcen (ResourceStorePage, Slug `store`);
        Tokens kaufen Vorrang und bezahlte Standorte (TokenStorePage, Slug `token-store`).
        Die Währungen sind nicht austauschbar, deshalb hat jede ihre eigene Seite.
    */
    'resource_store' => [
        'title' => 'Ressourcen-Shop',
        'nav_label' => 'Ressourcen-Shop',
        'subheading' => 'Gib Guthaben für mehr CPU, Arbeitsspeicher und Speicherplatz aus. Was du kaufst, behältst du.',
        'what_credits_buy' => 'Guthaben kauft CPU, Arbeitsspeicher und Speicherplatz, dauerhaft.',
        'balance_hint' => 'Verdiene Guthaben, indem du Leute auf unseren Discord-Server einlädst oder ihn boostest.',
        'resources_heading' => 'Deine Ressourcen',
        'resources_hint' => 'Der helle Teil ist, was deine Server nutzen, der getönte Teil der Rest deines Limits, und der Balken reicht bis dorthin, wo die Preise steil werden. Die ersten Schritte sind günstig; jeder Schritt kostet etwas mehr als der vorige.',
        'of_limit' => 'von :limit',
        'meter_label' => ':used von :limit in Benutzung',
        'price_rising' => 'Bis :limit werden die Schritte teurer, dort kosten sie den vollen Preis.',
        'price_climbing' => 'Ab :limit kostet jeder Schritt mehr, ab :soft deutlich mehr.',
        'price_steep' => 'Ab :soft kostet jeder Schritt deutlich mehr als der vorige.',
        'over' => ':amount über deinem Limit. Verkleinere oder lösche einen Server, um Platz zu schaffen.',
        'history' => 'Guthaben-Verlauf',
    ],

    'plus_page' => [
        'title' => ':app Plus',
        'nav_label' => ':app Plus',
        'subheading' => 'Bessere Standorte, kein Warten in der Schlange und keine Werbung im Spiel. Einmal gekauft, gehört es dir.',
        'owned_heading' => 'Du hast :app Plus',
        'owned_body' => 'Plus-Standorte, Vorrang in der Start-Warteschlange und keine Werbung im Spiel gehören dir dauerhaft. PlusPlus legt noch etwas drauf.',
        'plus_pitch' => 'Alles, was die meisten Server brauchen, einmalig und ohne Verlängerung.',
        'plusplus_pitch' => 'Das Add-on für Server, die am meisten brauchen: Premium-Hardware, nirgends Werbung und mehr CPU.',
        'price_tokens' => ':cost Tokens',
        'plusplus_term' => 'für :tokens, oder Guthaben für :credits',
        'plusplus_term_tokens' => 'für :tokens',
        'duration_weeks' => '{1} eine Woche|{2} zwei Wochen|[3,*] :count Wochen',
        'duration_days' => '{1} einen Tag|[2,*] :count Tage',
        'credits' => 'Guthaben',
        'get_plus' => 'Plus holen',
        'extend_hint' => 'Ein erneuter Kauf vor dem Ende verlängert die Restzeit.',
        'compare_heading' => 'Was du bekommst',
        'col_free' => 'Kostenlos',
        'col_plus' => 'Plus',
        'col_plusplus' => 'PlusPlus',
        'row_locations' => 'Standorte',
        'row_queue' => 'Start-Warteschlange auf einem vollen Node',
        'row_game_ads' => 'Werbung im Spiel (Minecraft)',
        'row_panel_ads' => 'Werbung im Panel',
        'row_cpu' => 'CPU',
        'row_ai' => 'neo-Nutzung (KI)',
        'row_devbox' => 'VS Code auf deinem Server',
        'cell_locations_free' => 'Kostenlos',
        'cell_locations_plus' => 'Kostenlos und Plus',
        'cell_locations_plusplus' => 'Kostenlos, Plus und Premium',
        'cell_queue_free' => 'Wartet in der Schlange',
        'cell_queue_plus' => 'Vor kostenlos',
        'cell_queue_plusplus' => 'Ganz vorne',
        'cell_game_ads_free' => 'Eine kurze Nachricht pro Stunde',
        'cell_game_ads_plus' => 'Keine',
        'cell_game_ads_plusplus' => 'Keine',
        'cell_panel_ads_free' => 'Ja',
        'cell_panel_ads_plus' => 'Ja',
        'cell_panel_ads_plusplus' => 'Keine',
        'cell_cpu_free' => 'Dein Kontingent',
        'cell_cpu_plus' => 'Dein Kontingent',
        'cell_cpu_plusplus' => 'Dein Kontingent +:cpu%',
        'cell_ai_free' => 'Standard',
        'cell_ai_plus' => '+:plus%',
        'cell_ai_plusplus' => '+:plusplus%',
        'cell_devbox_free' => 'Nein',
        'cell_devbox_tier' => ':cpu vCPU, :memory RAM, :disk Speicher',
        'faq_heading' => 'Fragen',
        'faq_once_q' => 'Ist Plus ein Abo?',
        'faq_once_a' => 'Nein. Plus bezahlst du einmal und behältst es. Nur PlusPlus läuft eine feste Zeit, und nichts verlängert sich von selbst.',
        'faq_expiry_q' => 'Was passiert, wenn PlusPlus endet?',
        'faq_expiry_a' => 'Plus behältst du. Server auf Premium-Standorten ziehen an einen anderen Standort um, und wenn deine Server ohne PlusPlus mehr CPU nutzen als dein Kontingent, wird der Unterschied gleichmäßig abgezogen. Kauf PlusPlus vor dem Ende erneut, um beides zu vermeiden.',
        'faq_tokens_q' => 'Wie bekomme ich Tokens?',
        'faq_tokens_a' => 'Auf der Seite „Tokens“ in der Seitenleiste, über gesponserte Links.',
        'faq_credits_q' => 'Kann ich mit Guthaben bezahlen?',
        'faq_credits_a' => 'PlusPlus ja: Guthaben kauft jeweils :duration. Plus selbst bezahlst du mit Tokens.',
    ],

    'store' => [
        'balance_after' => 'danach :balance',
    ],

    'credits' => [
        'credits' => 'Guthaben',
        'credits_short' => 'Cr',
        'earn_discord_rewards' => 'Discord-Belohnungen verdienen',
        'confirm_purchase' => 'Möchtest du :item wirklich für :cost Guthaben kaufen?',
        'purchase_successful' => 'Kauf erfolgreich',
        'purchase_failed' => 'Kauf fehlgeschlagen',
        'insufficient_credits' => 'Du hast nicht genügend Guthaben für diesen Kauf.',
        'store_unavailable' => 'CPU, Arbeitsspeicher und Speicherplatz können gerade nicht gekauft werden. Versuche es in einer Minute erneut.',
        'invalid_amount' => 'Diese Menge kann nicht auf einmal gekauft werden.',
        'cpu_purchased' => 'Dein CPU-Limit wurde erfolgreich um +:amount% für :cost Guthaben erhöht.',
        'memory_purchased' => 'Dein RAM-Limit wurde erfolgreich um +:amount :unit für :cost Guthaben erhöht.',
        'disk_purchased' => 'Dein Speicherplatz wurde erfolgreich um +:amount :unit für :cost Guthaben erhöht.',
        'tx_cpu_purchase' => '+:amount% CPU-Limit Upgrade gekauft',
        'tx_memory_purchase' => '+:amount :unit RAM-Limit Upgrade gekauft',
        'tx_disk_purchase' => '+:amount :unit Speicherplatz Upgrade gekauft',
        'no_transactions' => 'Noch keine Guthaben-Transaktionen vorhanden.',
        'tx_economy_reset' => 'Guthaben-Wirtschaft zurückgesetzt',
        'tx_invite_clawback' => 'Invite-Belohnung zurückgenommen — eingeladenes Mitglied hat Discord verlassen',
        'tx_resource_reclaim' => 'Gekaufte Ressourcen zurückgeholt, um eine zurückgenommene Belohnung zu decken',
    ],

    'tokens' => [
        'tokens' => 'Tokens',
        'balance' => 'Token-Guthaben',
        'earn_tokens' => 'Tokens verdienen',
        'tx_daily' => 'Täglicher Token',
    ],

    /*
        Der Vorrangspass, verkauft hier und auf der Warteseite der Warteschlange.
        Preise und Laufzeiten stehen absichtlich nicht in dieser Datei — sie kommen
        aus der Konfiguration von plugged-host-queue.
    */
    /*
        plugged.host Plus und PlusPlus (MembershipService). Preise und Tage kommen aus der
        Konfiguration über den Service und stehen nie hier.
    */
    'membership' => [
        'heading' => ':app Plus',
        'description' => 'Plus gehört dir dauerhaft. PlusPlus baut darauf auf, jeweils für ein paar Tage, und wird erneut gekauft, um es zu behalten.',
        'plus_title' => ':app Plus',
        'plus_term' => 'Einmalig, dauerhaft',
        'perk_plus_locations' => 'Die Plus-Standorte',
        'perk_priority' => 'Vorrang in der Start-Warteschlange, wenn ein Node ausgelastet ist',
        'perk_no_game_ads' => 'Keine Werbung im Spiel auf deinen Minecraft-Servern',
        'plusplus_title' => ':app PlusPlus',
        'plusplus_term' => 'Braucht Plus',
        'perk_everything_in_plus' => 'Alles aus Plus',
        'perk_premium_locations' => 'Die Premium-Standorte',
        'perk_no_ads' => 'Nirgends Werbung: keine Banner, Popups, kein Warteschirm vor dem Start und keine Werbung im Spiel',
        'perk_front_of_queue' => 'Ganz vorne in der Start-Warteschlange, noch vor Plus',
        'perk_cpu' => '+:cpu% CPU für dein Konto',
        'perk_ai_plus' => ':percent% mehr neo-Nutzung (KI)',
        'perk_ai_plusplus' => 'Noch einmal :percent% mehr neo-Nutzung (KI) zusätzlich zu Plus',
        'perk_devbox' => 'VS Code auf deinem Server: :cpu vCPU, :memory RAM, :disk Speicher',
        'perk_devbox_plusplus' => 'Eine größere VS-Code-Devbox: :cpu vCPU, :memory RAM, :disk Speicher',
        'plusplus_expiry' => 'Läuft PlusPlus aus, ziehen Server auf Premium-Standorten an einen anderen Standort um, und CPU über deinem Kontingent wird gleichmäßig von deinen Servern abgezogen. Ein erneuter Kauf vorher verlängert die Restzeit.',
        'plusplus_active_heading' => 'PlusPlus ist aktiv',
        'plusplus_active_body' => 'Es läuft bis :until.',
        'owned' => 'Gekauft',
        'days' => ':days Tage',
        'tokens_short' => 'Tokens',
        'cta_tokens' => ':cost Tokens',
        'plus_confirm' => ':cost Tokens für :app Plus ausgeben? Es gehört dir dauerhaft.',
        'plusplus_confirm_tokens' => ':cost Tokens für :days Tage :app PlusPlus ausgeben?',
        'plusplus_confirm_credits' => ':cost Guthaben für :days Tage :app PlusPlus ausgeben?',
        'balance_tokens' => 'Du hast :balance Tokens.',
        'earn_link' => 'Mehr Tokens holen',
        'needs_plus' => 'PlusPlus braucht zuerst :app Plus.',
        'plus_bought' => 'Du hast :app Plus.',
        'plusplus_bought' => 'PlusPlus läuft bis :until.',
        'short_tokens' => 'Dafür brauchst du :cost Tokens.',
        'short_credits' => 'Dafür brauchst du :cost Guthaben.',
        'unavailable' => 'Plus ist gerade nicht erhältlich.',
        'failed' => 'Das hat nicht geklappt. Es wurde nichts abgebucht.',
        'tx_plus' => ':app Plus',
        'tx_plusplus' => ':app PlusPlus, :days Tage',
    ],

    /*
    | Der Economy-Tab auf der Benutzerseite im Adminbereich. Für den Support, nicht
    | für Endnutzer: die Formulierungen setzen voraus, dass klar ist, was ein Ledger
    | ist — und müssen deutlich machen, dass hier eins geschrieben wird.
    */
    'admin' => [
        'tab' => 'Economy',
        'credits_heading' => 'Credits',
        'credits_hint' => 'Credits kaufen CPU, RAM und Speicher im Shop. Verdient über Discord-Einladungen und Boosts.',
        'tokens_heading' => 'Tokens',
        'tokens_hint' => 'Tokens kaufen :app Plus und PlusPlus. Verdient über gesponserte Links.',
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
    'tokens_subheading' => 'Tokens verdienen und sehen, wohin sie gingen',
    'tokens_balance' => ':count Tokens',
    'tokens_explainer' => 'Verdiene Tokens über gesponserte Links. Sie bezahlen :app Plus, das Aufwecken eines schlafenden Servers und Subdomains.',
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

    'perks' => [
        'heading' => 'Hol mehr aus deinem Konto',
        // Die eingeklappte Einzeiler-Variante fürs Dashboard. Die Anzahl steht in der
        // Überschrift, weil eine geschlossene Leiste sagen muss, was dahinter steckt.
        'heading_compact' => 'Mehr Ressourcen freischalten (:count)',
        'description' => 'Upgrades, die du noch nicht eingelöst hast. Jedes verschwindet aus der Liste, sobald es dir gehört.',
        'discord_link_title' => 'Discord verknüpfen für :gain',
        'discord_link_body' => 'Verknüpfe dein Discord-Konto und tritt unseren Discord-Servern bei, um deine Limits kostenlos zu erhöhen.',
        'discord_link_cta' => 'Discord verknüpfen',
        'discord_join_title' => 'Tritt unseren Discord-Servern bei für :gain',
        'discord_join_body' => 'Jeder unserer Discord-Server, auf dem du bist, gibt deinem Konto RAM, und wenn du auf allen bist, noch mehr.',
        'discord_join_cta' => 'Server ansehen',
        'discord_earn_title' => ':credits Guthaben pro eingeladener Person',
        'discord_earn_body' => 'Lade mit deinem eigenen Invite-Link Leute in unseren Discord ein und gib das Guthaben für mehr Ressourcen aus.',
        'discord_earn_body_boost' => 'Lade mit deinem eigenen Invite-Link Leute ein oder booste den Server für :credits Guthaben — und gib alles für mehr Ressourcen aus.',
        'discord_earn_cta' => 'Invites ansehen',
        'store_title' => ':price Guthaben bringen :amount mehr RAM',
        'store_body' => 'Du hast :balance Guthaben. Gib es für CPU, RAM oder Speicherplatz aus.',
        'store_cta' => 'Shop öffnen',
        'gain_cpu' => '+:amount CPU',
        'gain_memory' => '+:amount RAM',
        'gain_disk' => '+:amount Speicherplatz',
        'gain_and' => 'und',
    ],
];
