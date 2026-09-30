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
        'subheading' => 'Gib Guthaben für mehr CPU, Arbeitsspeicher, Speicherplatz und Server aus. Was du kaufst, behältst du.',
        'what_credits_buy' => 'Guthaben kauft CPU, Arbeitsspeicher und Speicherplatz, dauerhaft.',
        'balance_hint' => 'Verdiene Guthaben, indem du Leute auf unseren Discord-Server einlädst oder ihn boostest.',
        'resources_heading' => 'Deine Ressourcen',
        'resources_hint' => 'Der helle Teil ist, was deine Server nutzen, der getönte Teil der Rest deines Limits, und der Balken reicht bis zum Höchstmaß, das du kaufen kannst.',
        'of_limit' => 'von :limit',
        'meter_label' => ':used von :limit in Benutzung',
        'up_to' => 'Du kannst bis zu :cap kaufen.',
        'at_cap' => 'Du hast das Höchstmaß, das es zu kaufen gibt.',
        'over' => ':amount über deinem Limit. Verkleinere oder lösche einen Server, um Platz zu schaffen.',
        'history' => 'Guthaben-Verlauf',
    ],

    'token_store' => [
        'title' => 'Token-Shop',
        'nav_label' => 'Token-Shop',
        'subheading' => 'Gib Tokens für Vorrang in der Start-Warteschlange und für die Plus- und Premium-Standorte aus.',
        'what_tokens_buy' => 'Tokens kaufen Vorrang in der Start-Warteschlange und die Plus- und Premium-Standorte. Subdomain-Pfand und das Aufwecken eines schlafenden Servers bezahlst du mit Tokens direkt am Server.',
        'balance_hint' => 'Verdiene Tokens über einen gesponserten Link.',
        'premium_heading' => 'Plus- und Premium-Standorte',
        'premium_title' => 'Alle bezahlten Standorte freischalten',
        'premium_body' => 'Bessere Hardware, die nie mit der kostenlosen Stufe geteilt wird. Ein Kauf schaltet alle Plus- und Premium-Standorte dauerhaft frei.',
        'premium_owned' => 'Freigeschaltet',
        'premium_confirm' => ':cost Tokens ausgeben, um die Plus- und Premium-Standorte freizuschalten?',
        'premium_unlocked' => 'Plus- und Premium-Standorte freigeschaltet.',
        'premium_short' => 'Du brauchst :cost Tokens, um die Plus- und Premium-Standorte freizuschalten.',
        'premium_failed' => 'Die Plus- und Premium-Standorte konnten nicht freigeschaltet werden. Es wurden keine Tokens ausgegeben.',
        'history' => 'Token-Verlauf',
        'no_history' => 'Noch keine Token-Aktivität.',
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
        'max_cpu_reached' => 'Du hast bereits das Höchstmaß an CPU, das es zu kaufen gibt.',
        'max_memory_reached' => 'Du hast bereits das Höchstmaß an Arbeitsspeicher, das es zu kaufen gibt.',
        'max_disk_reached' => 'Du hast bereits das Höchstmaß an Speicherplatz, das es zu kaufen gibt.',
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
        'credits_hint' => 'Credits kaufen CPU, RAM und Speicher im Shop. Verdient über Discord-Einladungen und Boosts.',
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
    'tokens_explainer' => 'Tokens kaufen Vorrang in der Start-Warteschlange: :cost davon setzen dich für :days Tage vor alle ohne Vorrang, auf allen deinen Servern. Eine teurere Stufe im Token-Shop kauft ein kürzeres Fenster, das zusätzlich jede Werbung abschaltet — auch die werbefinanzierte Wartezeit vor dem Start eines großen Servers.',
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
