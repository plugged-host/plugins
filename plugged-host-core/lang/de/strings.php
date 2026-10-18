<?php

return [
    // The server sidebar's collapsed group (PluggedHost\Core\Filament\ServerNavigation).
    'nav' => [
        'more' => 'Mehr',
    ],

    // A node with backups turned off (Support\NodeBackups, NodeAwareInitiateBackupService).
    'backups' => [
        'disabled' => 'Backups sind für Server auf dieser Node deaktiviert.',
    ],

    'role' => 'Rolle|Rollen',
    'user' => 'Benutzer|Benutzer',
    'cpu' => 'CPU',
    'memory' => 'RAM',
    'disk' => 'Speicherplatz',
    'no_limit' => 'Kein Limit',
    'unlimited' => 'Unbegrenzt',
    'hint_unlimited' => '0 bedeutet Unbegrenzt',
    'name' => 'Server Name',
    'egg' => 'Egg',
    'left' => 'übrig',
    'variables' => 'Startup Variablen',

    'create_server' => 'Server erstellen',
    'create_network_ports' => 'Nächsten Netzwerkport erstellen',

    'choose_game_description' => 'Wähle ein Spiel aus. Im nächsten Schritt kannst du es konfigurieren.',
    'no_games' => 'Keine Spiele verfügbar',
    'no_games_description' => 'Es gibt derzeit keine Spiele, die du erstellen kannst. Bitte wende dich an einen Panel Admin.',

    'configure_server_description' => 'Wähle deine Konfiguration und starte den Server.',
    'deploy_server' => 'Server erstellen',

    'choose_type' => 'Server Software',
    'choose_type_description' => 'Welche Variante des Spielservers möchtest du nutzen?',
    'choose_type_onboarding' => 'Das wählst du in der Einrichtungshilfe, sobald der Server existiert - zusammen mit der Minecraft-Version, damit beides zueinander passt. Die Hilfe öffnet sich von selbst, wenn du deinen neuen Server das erste Mal aufrufst.',

    'choose_location' => 'Standort',
    'choose_location_description' => 'Wo soll dein Server gehostet werden?',
    'location_unavailable' => 'Keine Kapazität für die gewählten Ressourcen',
    'no_locations' => 'Es sind keine Standorte konfiguriert. Bitte wende dich an einen Panel Admin.',

    'choose_plan' => 'Ressourcen',
    'choose_plan_description' => 'Wähle ein Paket oder wechsle zu Erweitert für eigene Werte.',
    'plan_unavailable' => 'Übersteigt dein verbleibendes Limit',
    'advanced_resources' => 'Erweitert',
    'advanced_resources_hint' => 'CPU, RAM und Speicherplatz manuell festlegen statt ein Paket zu nutzen.',

    'server_details' => 'Details',
    'server_details_description' => 'Benenne deinen Server und passe die Startup Variablen an.',
    'eula_agree' => 'Ich stimme der Minecraft-Endbenutzer-Lizenzvereinbarung (EULA) zu',
    'eula_description' => 'Mit dem Erstellen dieses Servers stimmst du der Minecraft-EULA zu (https://aka.ms/MinecraftEULA). Die EULA wird automatisch auf dem Server akzeptiert, damit er direkt startet.',
    'resize_plan_description' => 'Wähle ein Paket oder wechsle zu Erweitert für eigene Werte.',
    'plan_current' => 'Aktuelles Paket',

    'change_version_description' => 'Wechsle zu einer anderen Minecraft Version oder Server Software.',
    'change_version_section' => 'Version und Software',
    'change_version_section_description' => 'Wähle Software und Version zur Installation. Beim Anwenden wird der Server neu installiert.',
    'change_version_apply' => 'Ändern und neu installieren',
    'change_version_confirm' => 'Version ändern und neu installieren?',
    'change_version_warning' => 'Der Server wird gestoppt und mit der gewählten Software und Version neu installiert. Welt- und Konfigurationsdateien bleiben erhalten, Jar-Dateien und Mod-Bibliotheken werden ersetzt. Mods und Plugins für die alte Version funktionieren möglicherweise nicht mehr.',
    'server_software' => 'Server Software',
    'minecraft_version' => 'Minecraft Version',
    'minecraft_version_hint' => 'Es werden nur Versionen angezeigt, die für die gewählte Software verfügbar sind.',
    'version_latest' => 'Neueste (empfohlen)',

    'server_settings' => [
        'sections' => [
            'general' => 'Allgemein',
            'resources' => 'Ressourcen',
            'startup' => 'Startvorgang',
            'startup_command' => 'Startbefehl',
            'version' => 'Version',
        ],
        'jump_to' => 'Springe zu',
        'save_resources' => 'Ressourcen speichern',
    ],

    'content' => [
        'nav_plugins' => 'Plugins',
        'nav_mods' => 'Mods & Modpacks',
        'subheading_plugins' => 'Durchsuche Modrinth und installiere Plugins direkt auf diesem Server. Es werden nur Plugins für deine Server Software und Minecraft Version angezeigt.',
        'subheading_mods' => 'Durchsuche Modrinth und installiere Mods und Modpacks direkt auf diesem Server. Es werden nur Inhalte für deinen Loader und deine Minecraft Version angezeigt.',
        'search' => 'Modrinth durchsuchen...',
        'col_project' => 'Projekt',
        'col_author' => 'Autor',
        'col_downloads' => 'Downloads',
        'col_categories' => 'Kategorien',
        'filter_kind' => 'Typ',
        'filter_version' => 'Minecraft Version',
        'kind_mod' => 'Mods',
        'kind_modpack' => 'Modpacks',
        'kind_plugin' => 'Plugins',
        'empty_heading' => 'Nichts gefunden',
        'empty_body' => 'Versuche einen anderen Suchbegriff oder entferne den Minecraft Version Filter - nicht jeder Autor listet alle unterstützten Versionen.',
        'installed_badge' => 'Installiert',
        'quick_install' => '1-Klick-Installation',
        'versions' => 'Versionen',
        'install' => 'Installieren',
        'install_heading' => ':name installieren',
        'install_submit' => 'Installieren',
        'version' => 'Version',
        'version_loose' => 'Kein Build nennt Minecraft :version, daher werden alle Builds angezeigt. Nimm den neuesten und teste ihn - meist funktioniert er trotzdem.',
        'version_gone' => 'Diese Version ist auf Modrinth nicht mehr verfügbar.',
        'view' => 'Auf Modrinth ansehen',
        'modpack_warning' => 'Der Server wird auf den Loader und die Minecraft Version umgestellt, die dieses Pack benötigt, neu installiert, und danach werden die Pack Dateien abgelegt. Deine Welt bleibt erhalten. Der exakte Loader Build des Packs kann nicht festgelegt werden, daher kann ein Pack, das genau einen Build verlangt, weiterhin Nacharbeit brauchen.',
        'client_only_warning' => 'Der Autor markiert dieses Projekt als rein clientseitig, auf einem Server tut es daher möglicherweise nichts.',
        'modpack_queued' => 'Modpack Installation gestartet',
        'modpack_queued_body' => ':name wird installiert. Der Server wird zuerst neu installiert, danach werden die Pack Dateien abgelegt - das dauert einige Minuten. Du wirst benachrichtigt, sobald es fertig ist.',
        'no_file' => 'Diese Version hat keine herunterladbare Datei.',
        'pull_failed' => 'Der Server konnte die Datei nicht herunterladen. Bitte versuche es erneut.',
        'installed' => 'Installation gestartet',
        'installed_body' => ':file wird nach :directory/ heruntergeladen. Starte den Server neu, um es zu laden.',
        'modpack_done' => 'Modpack installiert',
        'modpack_done_body' => ':name ist installiert und :count Dateien wurden auf den Server heruntergeladen. Starte ihn und beobachte die Konsole.',
        'modpack_failed' => 'Modpack Installation fehlgeschlagen',
        'modpack_failed_body' => ':name konnte nicht installiert werden. Prüfe die Konsole und die Dateiliste, bevor du den Server startest.',
        'modpack_reinstall_failed' => 'Der Server konnte für dieses Modpack nicht neu installiert werden, daher wurden keine Pack Dateien abgelegt.',
        'modpack_broken' => 'Diese Modpack Datei konnte nicht gelesen werden. Möglicherweise nutzt sie ein Format, das dieses Panel nicht unterstützt.',
        'modpack_unsupported' => 'Dieses Modpack benötigt einen Mod Loader, den dieser Server nicht installieren kann.',
        'modpack_download_failed' => 'Das Modpack konnte nicht von Modrinth heruntergeladen werden. Bitte versuche es erneut.',
        'modpack_overrides_skipped' => 'Die im Pack enthaltenen Konfigurationsdateien waren zu groß, um sie automatisch anzuwenden - lade das Pack von Modrinth herunter und lade den Ordner overrides über den Dateimanager hoch.',
    ],

    // Der typisierte Editor für die Konfigurationsdateien, die ein Minecraft Server in
    // seinem eigenen Datenverzeichnis hält. Welche Dateien erscheinen, hängt von der
    // Server Software und davon ab, was sie tatsächlich geschrieben hat - eine fehlende
    // Datei ist also der normale Fall und kein Fehler.
    'game_config' => [
        'nav_label' => 'Spieleinstellungen',
        'title' => 'Spieleinstellungen',
        'subheading' => 'Bearbeite die Konfigurationsdateien auf diesem Server. Gespeicherte Werte greifen beim nächsten Start.',
        'empty_heading' => 'Noch keine Konfigurationsdateien',
        'empty_body' => 'Keine der Dateien, die diese Seite bearbeiten kann, liegt auf dem Server. Starte ihn einmal, damit er sie schreibt, und komm dann zurück.',
        'unreachable' => 'Der Server war nicht erreichbar, daher konnten seine Konfigurationsdateien nicht gelesen werden. Bitte versuche es in einem Moment erneut.',
        'general' => 'Allgemein',
        'properties_notice' => 'Kommentare, Reihenfolge und alle hier nicht gezeigten Schlüssel bleiben unverändert. Ports und das RCON Passwort verwaltet das Panel, sie sind nicht bearbeitbar.',
        'yaml_notice' => 'Beim Speichern gehen die Kommentare in dieser Datei verloren - das Original wird als :file behalten. Listen und Abschnitte, die dieses Formular nicht zeigt, bleiben erhalten.',
        'save' => 'Speichern',
        'raw_edit' => 'Als Text bearbeiten',
        'raw_description' => 'Schreibt die Datei genau so, wie du sie hier hinterlässt. Es wird nichts geprüft, ein Fehler kann den Start des Servers verhindern.',
        'saved' => ':file gespeichert',
        'saved_body' => 'Starte den Server neu, um die neuen Werte zu übernehmen.',
        'no_changes' => 'Nichts zu speichern',
        'no_changes_body' => 'Diese Datei enthält bereits die angezeigten Werte.',
        'failed' => 'Etwas ist schiefgelaufen',
        'read_failed' => 'Die Datei konnte nicht vom Server gelesen werden. Bitte versuche es erneut.',
        'save_failed' => 'Die Datei konnte nicht auf den Server geschrieben werden, es wurde nichts geändert.',
        'backup_failed' => 'Eine Kopie des Originals konnte nicht als :file gespeichert werden, daher blieb die Datei unverändert.',
        'invalid_number' => ':key muss eine Zahl sein.',
        'invalid_choice' => '":value" ist kein gültiger Wert für :key.',
        'restart' => 'Server neu starten',
        'restart_confirm' => 'Server jetzt neu starten?',
        'restart_warning' => 'Der Server wird gestoppt und wieder gestartet, damit er die gespeicherte Konfiguration liest. Alle Spielenden werden währenddessen getrennt.',
        'restarting' => 'Server startet neu',
        'restart_failed' => 'Der Server konnte nicht neu gestartet werden. Versuche es über die Konsole erneut.',
    ],

    // Whitelist und Operatoren. Eine Änderung erreicht einen laufenden Server über dessen
    // Konsole und einen gestoppten über dessen Dateien, und der Unterschied muss sichtbar
    // bleiben: eine Dateiänderung wird erst beim nächsten Start gelesen.
    'players' => [
        'nav_label' => 'Spieler',
        'subheading' => 'Lege fest, wer diesen Server betreten darf und wer Operator-Rechte hat.',
        'unreachable_heading' => 'Spielerlisten nicht verfügbar',
        'unreachable' => 'Der Server war nicht erreichbar, daher konnten Whitelist und Operatorliste nicht gelesen werden. Bitte versuche es in einem Moment erneut.',
        'enforcement' => 'Whitelist',
        'enforcement_description' => 'Solange die Whitelist aktiv ist, können nur die unten aufgeführten Spieler beitreten.',
        'enforced' => 'Nur Spieler auf der Whitelist zulassen',
        'offline_mode' => 'Dieser Server prüft keine Konten bei Mojang, daher kann jeder unter jedem Namen spielen. Die Whitelist gilt nur für den Namen selbst.',
        'col_player' => 'Spieler',
        'col_whitelisted' => 'Auf Whitelist',
        'col_operator' => 'Operator',
        'op_level' => 'Stufe :level',
        'search' => 'Spieler suchen',
        'empty_heading' => 'Noch keine Spieler',
        'empty_body' => 'Auf diesem Server steht niemand auf der Whitelist und niemand ist Operator. Füge einen Spieler hinzu, um zu beginnen.',
        'whitelist' => 'Auf Whitelist setzen',
        'unwhitelist' => 'Von Whitelist entfernen',
        'unwhitelist_confirm' => ':name von der Whitelist entfernen?',
        'unwhitelist_warning' => 'Die Person kann den Server nicht mehr betreten und wird sofort getrennt, falls sie gerade spielt.',
        'op' => 'Zum Operator machen',
        'deop' => 'Operator entfernen',
        'op_confirm' => ':name zum Operator machen?',
        'deop_confirm' => 'Operator-Rechte von :name entfernen?',
        'op_warning' => 'Operatoren können Befehle ausführen, das Spiel ändern und die Welt bearbeiten. Gib das nur Personen, denen du vertraust.',
        'deop_warning' => 'Der Zugang zum Server bleibt bestehen, alle Operator-Befehle gehen verloren.',
        'add' => 'Spieler hinzufügen',
        'username' => 'Minecraft-Benutzername',
        'username_hint' => 'Der Name muss zu einem bestehenden Minecraft-Konto gehören.',
        'username_hint_offline' => 'Dieser Server prüft keine Konten, der Name wird genau so übernommen, wie du ihn eingibst.',
        'access' => 'Vergeben wird',
        'access_whitelist' => 'Zugang zum Server',
        'access_operator' => 'Operator-Rechte',
        'access_both' => 'Zugang und Operator-Rechte',
        'refresh' => 'Neu laden',
        'whitelisted' => ':name steht auf der Whitelist',
        'unwhitelisted' => ':name wurde von der Whitelist entfernt',
        'opped' => ':name ist jetzt Operator',
        'deopped' => ':name ist nicht mehr Operator',
        'enforced_on' => 'Whitelist aktiviert',
        'enforced_off' => 'Whitelist deaktiviert',
        'restart_note' => 'In den Serverdateien gespeichert - wirksam beim nächsten Start des Servers.',
        'failed' => 'Etwas ist schiefgelaufen',
        'unknown_user' => 'Es gibt kein Minecraft-Konto mit dem Namen ":name", daher wurde nichts geändert.',
        'write_failed' => ':file konnte nicht auf den Server geschrieben werden, daher wurde nichts geändert.',
        'properties_missing' => 'server.properties konnte nicht gelesen werden, daher bleibt die Whitelist-Einstellung unverändert. Starte den Server einmal, damit er die Datei schreibt.',
    ],

    'plans' => [
        'starter' => 'Starter',
        'standard' => 'Standard',
        'pro' => 'Pro',
        'ultimate' => 'Ultimate',
        'extreme' => 'Extrem',
        'master' => 'Master',
    ],

    'game_types' => [
        'purpur' => [
            'label' => 'Vanilla / Paper / Spigot / Purpur',
            'description' => 'Der Standard Server. Unterstützt Bukkit-, Spigot- und Paper-Plugins.',
        ],
        'fabric' => [
            'label' => 'Fabric',
            'description' => 'Schlanker Mod Loader. Für Fabric Mods.',
        ],
        'neoforge' => [
            'label' => 'NeoForge',
            'description' => 'Moderner Forge Fork. Für NeoForge Mods.',
        ],
        'forge' => [
            'label' => 'Forge',
            'description' => 'Der klassische Mod Loader. Für Forge Mods und ältere Modpacks.',
        ],
    ],

    'validation' => [
        'allocation_limit' => 'Du kannst diesem Server noch :count Port(s) hinzufügen.',
        'no_primary_allocation' => 'Dieser Server hat keine primäre Netzwerkzuweisung.',
        'no_available_port' => 'Nach dem aktuellen primären Port wurde kein verfügbarer Port gefunden.',
    ],

    'danger_zone' => 'Gefahrenzone',

    'admin_delete_user' => [
        'label' => 'Benutzer & Server löschen',
        'submit' => 'Benutzer & Server löschen',
        'heading' => ':username und dessen Server löschen',
        'description' => 'Dieser Benutzer besitzt :count Server: :servers. Beim Löschen des Kontos werden sie und alles darauf zerstört, einschließlich Welten, Dateien, Datenbanken und Backups. Das kann nicht rückgängig gemacht werden.',
        'acknowledge' => 'Ja, diese :count Server und alles darauf dauerhaft löschen',
        'confirm_username' => 'Tippe den Benutzernamen (:username) zur Bestätigung',
        'force' => 'Auch löschen, wenn eine Node nicht antwortet',
        'force_helper' => 'Das Panel vergisst jeden Server auch dann, wenn seine Node die Löschung nicht bestätigt. Nutze das nur für eine Node, die endgültig weg ist: der Container läuft möglicherweise weiter.',
    ],

    'modals' => [
        'delete_server_confirm' => 'Bist du sicher, dass du diesen Server löschen willst?',
        'delete_server_warning' => 'Dieser Vorgang kann nicht rückgängig gemacht werden und alle Daten gehen unwiderruflich verloren.',
        'delete_server' => 'Server löschen',
    ],

    'notifications' => [
        'server_resources_updated' => 'Server Ressourcen Limits aktualisiert',
        'might_need_restart' => 'Um die neuen Ressourcen Limits vollständig zu nutzen, ist möglicherweise ein Neustart des Servers erforderlich.',
        'manual_restart_needed' => 'Bitte starte deinen Server manuell neu, um die neuen Ressourcen Limits zu übernehmen.',
        'server_resources_not_updated' => 'Ressourcen Limits nicht aktualisiert',

        'server_deleted' => 'Server gelöscht',
        'server_deleted_success' => 'Der Server wurde erfolgreich gelöscht.',
        'server_delete_error' => 'Konnte Server nicht löschen',

        'change_version_started' => 'Server wird neu installiert',
        'change_version_started_body' => 'Die gewählte Software und Version werden installiert. Das kann einige Minuten dauern.',
        'change_version_failed' => 'Version konnte nicht geändert werden',
        'invalid_loader' => 'Diese Server Software ist für dieses Spiel nicht verfügbar.',
        'unsupported_version' => 'Minecraft :version ist für :loader nicht verfügbar.',

        'account_delete_failed' => 'Konto konnte nicht gelöscht werden',
        'account_delete_has_servers' => 'Ein Konto mit aktiven Servern kann nicht gelöscht werden. Bitte lösche zuerst deine Server.',
        'account_delete_last_admin' => 'Der letzte Root-Administrator kann nicht gelöscht werden.',
        'account_delete_server_failed' => 'Der Server ":server" konnte nicht gelöscht werden (:error), daher wurde das Konto nicht angetastet.',
        'account_delete_server_unreachable' => 'seine Node hat nicht geantwortet',
        'account_delete_partial' => ':count Server waren zu diesem Zeitpunkt bereits entfernt.',
        'account_delete_servers_not_acknowledged' => 'Bitte bestätige, dass die Server gelöscht werden dürfen.',
        'account_delete_username_mismatch' => 'Das stimmt nicht mit dem Benutzernamen überein.',
        'account_delete_unknown_error' => 'Unbekannter Fehler. Bitte wende dich an einen Panel Admin.',
        'user_deleted' => 'Benutzer gelöscht',
        'user_deleted_body' => ':username und :count Server wurden gelöscht.',

        'server_creation_failed' => 'Konnte Server nicht erstellen',
        'no_viable_node_found' => 'Es wurde keine verfügbare Node gefunden. Bitte wende dich an einen Panel Admin.',
        'no_viable_allocation_found' => 'Es wurde keine verfügbare Allocation gefunden. Bitte wende dich an einen Panel Admin.',
        'unknown_server_creation_error' => 'Unbekannter Fehler. Bitte wende dich an einen Panel Admin.',
        'resource_limit_exceeded' => 'Das würde dein Ressourcen- oder Serverlimit übersteigen.',
        'invalid_location' => 'Bitte wähle einen gültigen Standort.',
        'no_plan_selected' => 'Bitte wähle ein Paket oder wechsle zu Erweitert für eigene Werte.',

        'ports_created' => 'Netzwerkports erstellt',
        'ports_created_body' => 'Port :address wurde hinzugefügt.',
        'ports_create_failed' => 'Netzwerkports konnten nicht erstellt werden',
        'ports_conflict' => 'Ein oder mehrere Ports sind inzwischen nicht mehr verfügbar. Bitte versuche es erneut.',
        'ports_unknown_error' => 'Die Ports konnten nicht erstellt werden. Bitte wende dich an einen Panel Admin.',

        'primary_allocation_required' => 'Primäre Allocation kann nicht entfernt werden',
        'primary_allocation_required_body' => 'Lege zuerst eine andere Netzwerkzuweisung als primär fest.',
        'locked_allocation_required' => 'Gesperrte Allocation kann nicht entfernt werden',
        'locked_allocation_required_body' => 'Diese Netzwerkzuweisung ist gesperrt und kann nicht entfernt werden.',
    ],

    // Die Warteseite, auf der ein Server bis zum Ende der Installation bleibt. Absichtlich
    // ohne Prozentanzeige: wings meldet keinen Fortschrittsanteil, ein Balken wäre erfunden.
    'installing' => [
        'nav_label' => 'Einrichtung',
        'title' => 'Server wird eingerichtet',
        'title_reinstall' => 'Server wird neu installiert',
        'title_failed' => 'Installation fehlgeschlagen',
        'subheading' => ':server wird installiert. Diese Seite aktualisiert sich selbst und öffnet das Panel, sobald der Server bereit ist.',
        'subheading_failed' => 'Die Installation wurde nicht abgeschlossen. Deine Dateien sind unverändert, der Server kann aber noch nicht starten.',
        'status_body' => 'Die meisten Installationen sind in wenigen Minuten fertig, größere Modpacks brauchen länger. Du kannst diesen Tab schließen und später zurückkommen.',
        'failed_body' => 'Bei der Installation ist etwas schiefgelaufen. Meist steckt ein fehlgeschlagener Download oder eine nicht mehr verfügbare Version dahinter — melde dich, wir kümmern uns darum.',
        'elapsed' => 'Läuft seit',
        'get_help' => 'Hilfe holen',
        'steps_heading' => 'Fortschritt',
        'step_allocated' => 'Server erstellt und Ressourcen reserviert',
        'step_installing' => ':egg wird heruntergeladen und installiert',
        'step_installing_generic' => 'Serverdateien werden heruntergeladen und installiert',
        'step_ready' => 'Erster Start',
        'details_heading' => 'Was eingerichtet wird',
        'detail_type' => 'Typ',
        'while_you_wait' => 'Für die Wartezeit',
        'ready_title' => 'Dein Server ist bereit',
        'ready_body' => ':server ist fertig installiert und kann jetzt gestartet werden.',
    ],

    // Speist alle Entdeckungs-Oberflächen über PluggedHost\Economy\Services\PerkDiscovery.
    // Die Beträge kommen zur Laufzeit aus den noprofit.space-Stufen und den Shop-Preisen, hier
    // steht also keine Zahl doppelt — ändert sich eine Stufe, ändert sich dieser Text mit.
    // Die Willkommenstour (PluggedHost\Core\Livewire\PanelTourOverlay, Schritte in
    // Support\PanelTourSteps). Sie liegt über dem echten Panel, also steht jeder Schritt neben
    // dem, was er erklärt, und nennt es so, wie es auf dem Bildschirm heißt -- genau darum geht
    // es: wer "geh in die Einstellungen" hört, soll die Einstellungen finden.
    //
    // `panel` ist der erste Teil auf der Serverliste, `server` der zweite auf der Konsole eines
    // Servers. `bullets` ist optional; Schritte kurz halten, eine Textwand wird übersprungen.
    'tour' => [
        'title' => 'Willkommen',
        'progress' => 'Schritt :step von :total',
        'acknowledge' => 'Zeig mir alles',
        'next' => 'Weiter',
        'back' => 'Zurück',
        'skip' => 'Tour überspringen',
        'finish' => 'Fertig',
        'done_here' => 'Später',
        'open_server' => ':name zeigen',
        'create_server' => 'Ersten Server erstellen',
        'menu' => 'Willkommenstour erneut ansehen',
        'panel' => [
            'welcome' => [
                'heading' => 'Willkommen bei :app',
                'body' => 'Das ist dein Panel für Gameserver: Alles, was du bei uns mietest, findest du hier. Wir heben jeden Bereich hervor und sagen dir, wofür er da ist. Das dauert etwa eine Minute.',
            ],
            'navigation' => [
                'heading' => 'Die Seitenleiste',
                'body' => 'Jede Seite deines Kontos ist hier einen Klick entfernt.',
                // Nach Zeile der Seitenleiste geschlüsselt: PanelTourSteps lässt fehlende Zeilen weg.
                'bullets' => [
                    'home' => 'Startseite — deine Server. Hier bist du gerade.',
                    'store' => 'Ressourcen-Shop — mehr CPU, Arbeitsspeicher und Speicherplatz für dein Guthaben.',
                    'plus' => ':app Plus — bessere Standorte, Vorrang in der Startwarteschlange und keine Werbung im Spiel.',
                    'tokens' => 'Tokens — Tokens verdienen und sehen, wohin sie gingen.',
                    'backups' => 'Backups — ein Backup jedes deiner Server herunterladen.',
                    'profile' => 'Profileinstellungen — Sprache, Zeitzone und dein Konto.',
                ],
            ],
            'balance' => [
                'heading' => 'Verbrauch und Guthaben',
                'body' => 'Die Balken zeigen, wie viel deines Tarifs deine Server zusammen belegen. Darunter stehen deine Tokens und dein Guthaben.',
                'bullets' => [
                    'Guthaben kauft mehr Ressourcen im Ressourcen-Shop.',
                    'Tokens bezahlen :app Plus; verdienen kannst du sie auf der Seite „Tokens“.',
                    'Ein Klick auf die Balken oder das Guthaben öffnet den Ressourcen-Shop, ein Klick auf die Tokens die Seite „Tokens“.',
                ],
            ],
            'account' => [
                'heading' => 'Dein Konto',
                'body' => 'Dein Name öffnet das Kontomenü: dein Profil, Abmelden und diese Tour, falls du sie noch einmal sehen willst.',
            ],
            'create' => [
                'heading' => 'Server erstellen',
                'body' => 'Hier erstellst du einen neuen Server. Wähle ein Spiel, einen Standort und eine Größe, eine Minute später ist er bereit.',
            ],
            'servers' => [
                'heading' => 'Deine Server',
                'body' => 'Jeder deiner Server ist eine Karte wie diese. Der Balken links zeigt, ob er läuft, die Zahlen, was er gerade verbraucht. Ein Klick auf eine Karte öffnet den Server — lass uns :name zusammen öffnen.',
            ],
            'empty' => [
                'heading' => 'Deine Server',
                'body' => 'Hier erscheinen deine Server, je eine Karte, mit Status und Verbrauch. Du hast noch keinen.',
            ],
            'create_first' => [
                'heading' => 'Erstelle deinen ersten Server',
                'body' => 'Fang hier an: Wähle ein Spiel, einen Standort und eine Größe. Wenn du deinen neuen Server zum ersten Mal öffnest, zeigt dir die Tour, was darin steckt.',
            ],
        ],
        'server' => [
            'console' => [
                'heading' => 'Die Konsole',
                'body' => 'Die laufende Ausgabe deines Servers: Alles, was er schreibt, erscheint hier sofort. Tippe einen Befehl in die Zeile darunter und drücke Enter, um ihn auszuführen.',
            ],
            'power' => [
                'heading' => 'Starten, Neustarten, Stoppen',
                'body' => 'Die Schaltflächen zum Ein- und Ausschalten dieses Servers. Stoppen lässt das Spiel speichern und sauber herunterfahren; hängt es dabei, wird aus Stoppen „Stopp erzwingen“.',
            ],
            'address' => [
                'heading' => 'Name, Status und Adresse',
                'body' => 'Die Adresse geben Spieler zum Beitreten ein. Klick darauf, um sie zu kopieren, und schick sie deinen Freunden. CPU, Arbeitsspeicher und Speicher sind Live-Werte.',
            ],
            'setup' => [
                'heading' => 'Einrichtung abschließen',
                'body' => 'Für diesen Server wartet noch die Einrichtungshilfe: Sie wählt Minecraft-Version und Software und macht den Server spielbereit.',
            ],
            'sidebar' => [
                'heading' => 'Alles zu diesem Server',
                'body' => 'Solange ein Server geöffnet ist, gehört die Seitenleiste zu ihm.',
                'bullets' => [
                    'Dateien — alles auf dem Server hochladen, bearbeiten und löschen.',
                    'Backups — vor einer Änderung eines anlegen und zurückspielen, wenn etwas kaputtgeht.',
                    'Mehr — die Seiten, die du seltener brauchst: Subdomains, Netzwerk-Ports, Datenbanken, Zeitpläne, Benutzer und das Aktivitätsprotokoll.',
                ],
            ],
            'sidebar_minecraft' => [
                'heading' => 'Alles zu diesem Server',
                'body' => 'Solange ein Server geöffnet ist, gehört die Seitenleiste zu ihm.',
                'bullets' => [
                    'Dateien — alles auf dem Server hochladen, bearbeiten und löschen.',
                    'Plugins oder Mods — installieren und aktualisieren aus Modrinth, CurseForge und SpigotMC.',
                    'Welten und Spieler — Welten wechseln, Whitelist, Operatoren und Bans verwalten.',
                    'Spieleinstellungen — die server.properties als Formular.',
                    'Backups — vor einer Änderung eines anlegen und zurückspielen, wenn etwas kaputtgeht.',
                    'Mehr — die Seiten, die du seltener brauchst: Subdomains, Netzwerk-Ports, Datenbanken und mehr.',
                ],
            ],
            'settings' => [
                'heading' => 'Einstellungen',
                'body' => 'Eine Seite für alles zu diesem Server: umbenennen, mehr Arbeitsspeicher geben, die Version wechseln oder neu installieren.',
            ],
            'home' => [
                'heading' => 'Zurück zu deinen Servern',
                'body' => 'Das Logo bringt dich zurück zur Serverliste. Das war die Tour — du kannst sie jederzeit über das Menü unter deinem Namen erneut ansehen.',
            ],
        ],
    ],

    // Admin-Einstellungsformular (PluggedHostCorePlugin, über EnvironmentWriterTrait). Der
    // Abschnitt Seitenleiste trägt seine eigenen Labels; diese Schlüssel decken die
    // Willkommenstour-Optionen ab.
    'settings' => [
        'section_tour' => 'Willkommenstour',
        'section_tour_hint' => 'Die Einführung beim ersten Login, durch die neue Benutzer geführt werden. Wird sie ausgeschaltet, entfällt die Weiterleitung für alle sofort.',
        'tour_enabled' => 'Willkommenstour anzeigen',
        'tour_enabled_hint' => 'Benutzer beim ersten Anmelden zur geführten Tour weiterleiten.',
        'tour_version' => 'Tour-Version',
        'tour_version_hint' => 'Erhöhe diesen Wert, um die Tour allen erneut zu zeigen, die sie bereits gesehen haben. Bei reinen Textkorrekturen unverändert lassen.',
    ],

    'suspended' => [
        'title' => 'Server gesperrt',
        'body' => 'Dieser Server ist gesperrt. Er kann nicht gestartet oder verwaltet werden, bis die Sperre aufgehoben ist.',
        'by_abuse_scan' => ':time von unserem automatischen Missbrauchs-Scan gesperrt.',
        'by_admin' => ':time von einem Administrator gesperrt.',
        'by_system' => ':time gesperrt.',
        'deletes_at' => 'Er wird :time samt seinen Dateien gelöscht, sofern die Sperre bis dahin nicht aufgehoben wird.',
        'note_label' => 'Grund',
        'findings_label' => 'Was der Scan gefunden hat',
        'more' => 'und :count weitere',
        'help' => 'Wenn du denkst, dass das ein Fehler ist, frag uns auf Discord und nenne den Namen des Servers.',
        'help_admin' => 'Wenn du denkst, dass das ein Fehler ist, wende dich an die Administratoren dieses Panels und nenne den Namen des Servers.',
        'help_action' => 'Hilfe auf Discord',
        'reason_field' => 'Grund',
        'reason_hint' => 'Wird dem Besitzer des Servers auf seiner Sperrseite angezeigt.',
    ],

    'maintenance' => [
        'title' => 'Geplante Wartungsarbeiten',
        'badge' => 'Wartungsmodus',
        'heading' => 'Wir sind gleich zurück',
        'subheading' => 'Wir führen derzeit geplante Wartungsarbeiten und System-Upgrades durch, um die Leistung und Zuverlässigkeit zu verbessern. Die Dienste sind in Kürze wieder verfügbar.',
        'status_active' => 'Wartungsarbeiten aktiv',
        'refresh_now' => 'Erneut prüfen',
        'refresh_auto' => 'Automatische Prüfung in :seconds',
        'discord' => 'Discord Support',
        'status_page' => 'Öffentlicher Status',
        'service_unavailable' => '503 • Dienst vorübergehend nicht verfügbar',
        'checking' => 'Wird geprüft…',
    ],

    'errors' => [
        'home' => 'Zurück zum Panel',
        'status' => 'Öffentlicher Status',
        'code' => 'Fehler :code',
        'titles' => [
            '400' => 'Ungültige Anfrage',
            '401' => 'Anmeldung erforderlich',
            '403' => 'Kein Zugriff',
            '404' => 'Seite nicht gefunden',
            '419' => 'Seite abgelaufen',
            '429' => 'Zu viele Anfragen',
            '500' => 'Etwas ist schiefgelaufen',
        ],
        'messages' => [
            '400' => 'Diese Anfrage konnte nicht verarbeitet werden. Geh zurück und versuch es noch einmal.',
            '401' => 'Melde dich an, um diese Seite zu sehen.',
            '403' => 'Du hast keinen Zugriff auf diese Seite.',
            '404' => 'Diese Seite gibt es nicht oder sie wurde verschoben.',
            '419' => 'Diese Seite war zu lange offen. Lade sie neu und versuch es noch einmal.',
            '429' => 'Zu viele Anfragen in kurzer Zeit. Warte einen Moment und versuch es dann noch einmal.',
            '500' => 'Bei uns ist ein Problem aufgetreten. Versuch es gleich noch einmal.',
            'default' => 'Bei dieser Anfrage ist etwas schiefgelaufen.',
        ],
    ],

    'admin_nav' => [
        'servers' => 'Server',
        'users' => 'Benutzer',
        'community' => 'Ankündigungen & Status',
        'subdomains' => 'Subdomains',
        'panel' => 'Panel',
        'advanced' => 'Erweitert',
    ],
];
