<?php

return [
    'title' => 'Server im Ruhezustand',
    'restore' => 'Server wiederherstellen',
    'confirm' => 'Die Wiederherstellung kostet :cost Tokens und verschiebt deinen Server zurück auf einen aktiven Node.',
    'restored' => 'Dein Server wird wiederhergestellt.',
    'insufficient' => 'Du benötigst :cost Tokens, um diesen Server wiederherzustellen.',
    'failed' => 'Wiederherstellung fehlgeschlagen. Es wurden keine Tokens abgebucht.',
    'body' => 'Dieser Server war längere Zeit inaktiv und wurde in den Ruhezustand versetzt, um Kapazität freizugeben. Stelle ihn wieder her, um ihn wieder online zu bringen.',

    'entered' => ':time in den Ruhezustand versetzt',
    'cost_label' => 'Wiederherstellungskosten',
    'balance_label' => 'Dein Guthaben',
    'tokens' => ':count Tokens',

    'settings' => [
        'section_general' => 'Allgemein',
        'section_general_hint' => 'Hauptschalter für den Ruhezustand-Durchlauf. Solange deaktiviert, bleibt der Durchlauf wirkungslos und das Plugin bleibt inaktiv.',
        'enabled' => 'Ruhezustand aktivieren',
        'enabled_hint' => 'Wenn aktiviert, werden inaktive Server gemäß dem Zeitplan unten gestoppt und auf den Limbo-Node verschoben.',

        'section_schedule' => 'Ruhezustand-Zeitplan',
        'section_schedule_hint' => 'Schwellenwerte in Stunden seit der letzten Panel-Aktivität eines Servers sowie Ziel und Tempo der Verschiebung.',
        'stop_hours' => 'Stoppen nach (Stunden)',
        'stop_hours_hint' => 'Stunden ohne Panel-Aktivität, bevor der Server gestoppt wird.',
        'transfer_hours' => 'Verschieben nach (Stunden)',
        'transfer_hours_hint' => 'Stunden ohne Panel-Aktivität, bevor ein gestoppter Server auf den Limbo-Node verschoben wird.',
        'node_tag' => 'Limbo-Node-Tag',
        'node_tag_hint' => 'Tag, das den Limbo-Node kennzeichnet. Darf kein Ländercode sein und muss von den UCS-Deployment-Tags ausgeschlossen werden.',
        'max_per_run' => 'Max. Verschiebungen pro Durchlauf',
        'max_per_run_hint' => 'Obergrenze, wie viele Server ein einzelner Durchlauf verschieben darf, damit ein Lauf nach einem Ausfall nicht Dutzende Transfers gleichzeitig startet.',

        'section_ransom' => 'Wiederherstellungskosten',
        'section_ransom_hint' => 'Token-Lösegeld, das der Besitzer zahlt, um einen im Ruhezustand befindlichen Server auf einen aktiven Node zurückzuholen.',
        'cost_early' => 'Kosten für frühe Wiederherstellung (Tokens)',
        'cost_early_hint' => 'Tokens, die berechnet werden, wenn ein Server innerhalb des Kostenfensters nach Eintritt in den Limbo wiederhergestellt wird.',
        'cost_late' => 'Kosten für späte Wiederherstellung (Tokens)',
        'cost_late_hint' => 'Tokens, die berechnet werden, wenn ein Server nach Ablauf des Kostenfensters wiederhergestellt wird.',
        'cost_window_hours' => 'Kostenfenster (Stunden)',
        'cost_window_hours_hint' => 'Stunden nach Eintritt in den Limbo, in denen die frühen Kosten gelten; danach gelten die späten Kosten.',
    ],
];
