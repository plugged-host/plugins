<?php

return [
    'role_resource_limits' => 'Rollen-Ressourcenlimit|Rollen-Ressourcenlimits',
    'role' => 'Rolle|Rollen',
    'user' => 'Benutzer|Benutzer',
    'cpu' => 'CPU',
    'memory' => 'RAM',
    'disk' => 'Speicherplatz',
    'server_limit' => 'Server Limit',
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

    'onboarding' => [
        'nav_label' => 'Einrichten',
        'title' => 'Server einrichten',
        'subheading' => 'Ein paar Fragen, und dein Server ist so eingerichtet, wie du ihn haben willst. Alles davon lässt sich später ändern.',
        'callout_heading' => 'Dein Server ist noch nicht eingerichtet',
        'callout_body' => 'Wähle deine Server-Software, ein Modpack und wer beitreten darf - die Dateien schreiben wir für dich.',
        'callout_action' => 'Einrichtung starten',
        'finish' => 'Einrichtung abschließen',
        'gate_title' => 'Richte deinen Server zuerst fertig ein',
        'gate_body' => 'Dieser Server wurde gerade erstellt und braucht noch seine Server-Software. Der Rest des Panels steht dir offen, sobald du hier fertig bist.',

        'step_software' => 'Server-Software',
        'step_software_hint' => 'Welcher Server das ist und welche Minecraft-Version er ausführt.',
        'version_hint' => 'Bleibt das auf Neueste, folgt dein Server bei der nächsten Neuinstallation automatisch neuen Minecraft-Versionen.',

        'step_modpack' => 'Modpack',
        'step_modpack_hint' => 'Optional. Installiere ein fertiges Modpack, statt alles selbst zusammenzustellen.',
        'modpack' => 'Modpack',
        'modpack_none' => 'Kein Modpack - nur der reine Server',
        'modpack_search' => 'Modrinth nach einem Modpack durchsuchen',
        'modpack_version' => 'Modpack-Version',
        'modpack_version_hint' => 'Die neueste Veröffentlichung ist vorausgewählt. Ältere Versionen gibt es für Packs, die noch aktualisiert werden.',
        'modpack_hint' => 'Ein Modpack bringt seinen eigenen Loader und seine eigene Minecraft-Version mit und überschreibt damit die beiden Antworten aus dem vorherigen Schritt. Packs, die genau einen bestimmten Forge- oder NeoForge-Build verlangen, können danach noch Handarbeit nötig machen.',

        'step_accounts' => 'Spieler-Konten',
        'step_accounts_hint' => 'Ob Spieler ein gekauftes Minecraft-Konto brauchen, um beitreten zu können.',
        'premium' => 'Nur Premium-Konten',
        'premium_description' => 'Jeder Spieler wird bei Mojang überprüft. Skins, Capes und Namen sind garantiert echt. Das ist die sichere Standardeinstellung.',
        'cracked' => 'Cracked-Clients erlauben',
        'cracked_description' => 'Jeder kann mit jedem Namen beitreten, ohne Minecraft zu besitzen. Wähle das nur, wenn du es wirklich brauchst.',
        'cracked_warning' => 'Achtung: Mit erlaubten Cracked-Clients kann sich jeder mit deinem Namen verbinden - auch mit dem eines Operators. Lass die Whitelist an, sonst wird dein Server übernommen.',

        'step_access' => 'Für wen ist er',
        'step_access_hint' => 'Ein privater Server für Freunde, oder offen für alle.',
        'private' => 'Privat - nur Freunde',
        'private_description' => 'Nur die Spieler auf deiner Liste können beitreten. Schaltet die Whitelist ein und erzwingt sie, sodass Entfernte auch getrennt werden.',
        'public' => 'Öffentlich - alle dürfen rein',
        'public_description' => 'Keine Whitelist. Jeder, der die Adresse kennt, kann sich verbinden.',
        'public_warning' => 'Ein öffentlicher Server wird innerhalb von Stunden von Scannern gefunden. Rechne mit ungebetenen Gästen und halte Backups bereit.',

        'step_players' => 'Spieler',
        'step_players_hint' => 'Wer beitreten darf und wer den Server verwalten darf.',
        'players' => 'Erlaubte Spieler',
        'players_placeholder' => 'Namen eingeben und Enter drücken',
        'players_hint' => 'Minecraft-Namen, keine E-Mail-Adressen. Weitere kannst du jederzeit über die Seite Spieler hinzufügen.',
        'operators' => 'Operatoren',
        'operators_placeholder' => 'Namen eingeben und Enter drücken',
        'operators_hint' => 'Fang mit deinem eigenen Minecraft-Namen an, damit du im Spiel Befehle ausführen kannst.',
        'operators_warning' => 'Operatoren können alles am Server ändern, sich selbst Gegenstände geben und andere Spieler bannen. Füge nur Leute hinzu, denen du vertraust.',

        'done' => 'Dein Server ist eingerichtet',
        'done_body' => 'Die Einstellungen wurden in die Server-Dateien geschrieben.',
        'done_restart' => 'Die Einstellungen wurden in die Server-Dateien geschrieben. Starte den Server neu, damit er sie übernimmt.',
        'done_installing' => 'Der Server wird gerade installiert. Deine Whitelist und die Einstellungen werden automatisch übernommen, sobald er fertig ist - das kann einige Minuten dauern.',
        'reinstall_failed' => 'Der Server konnte nicht neu installiert werden',
        'reinstall_failed_body' => 'Deine Antworten wurden gespeichert, aber der Software-Wechsel konnte nicht gestartet werden. Versuche es noch einmal über den Tab Version.',
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

    /*
     * Was vom Guthaben-Block übrig ist, nachdem die Wirtschaft nach
     * plugged-host-economy gezogen ist.
     *
     * Diese fünf werden weiterhin von Core-Code gerendert — UserResourceLimitsOverview ist
     * ein Core-Widget — und bleiben deshalb hier, statt zu einem Plugin-übergreifenden
     * Lookup zu werden, der beim Deinstallieren des Economy-Plugins nur noch den rohen
     * Übersetzungsschlüssel anzeigt. Alles andere liegt jetzt unter
     * plugged-host-economy::strings.credits.
     */
    'credits' => [
        'credits' => 'Guthaben',
        'store_description' => 'Limits im Shop upgraden',
        'need_more_upgrade_store' => 'Mehr nötig? Im Ressourcen-Shop upgraden',

        // Wird angezeigt, wenn bestehende Server mehr belegen als das aktuelle Limit
        // erlaubt — Rolle entfernt, gesenkt oder Ressourcen zurückgeholt.
        'over_quota' => 'Limit um :amount überschritten — Server verkleinern oder löschen',
        'over_quota_slots' => 'Server-Limit um :amount überschritten — Server löschen',
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

    // Speist alle Entdeckungs-Oberflächen über PluggedHost\Core\Services\PerkDiscovery.
    // Die Beträge kommen zur Laufzeit aus den Rollen-Limits und den Shop-Preisen, hier steht
    // also keine Zahl doppelt — ändert sich ein Rollen-Limit, ändert sich dieser Text mit.
    'perks' => [
        'heading' => 'Hol mehr aus deinem Konto',
        // Die eingeklappte Einzeiler-Variante fürs Dashboard. Die Anzahl steht in der
        // Überschrift, weil eine geschlossene Leiste sagen muss, was dahinter steckt.
        'heading_compact' => 'Mehr Ressourcen freischalten (:count)',
        'description' => 'Upgrades, die du noch nicht eingelöst hast. Jedes verschwindet aus der Liste, sobald es dir gehört.',
        'discord_link_title' => 'Discord verknüpfen für :gain',
        'discord_link_body' => 'Verknüpfe dein Discord-Konto und tritt unserem Server bei, um deine Limits kostenlos zu erhöhen.',
        'discord_link_cta' => 'Discord verknüpfen',
        'discord_join_title' => 'Tritt unserem Discord bei für :gain',
        'discord_join_body' => 'Dein Konto ist verknüpft, das Upgrade gibt es aber erst als Mitglied des Servers.',
        'discord_join_cta' => 'Server beitreten',
        'discord_earn_title' => ':credits Guthaben pro eingeladener Person',
        'discord_earn_body' => 'Lade mit deinem eigenen Invite-Link Leute in unseren Discord ein und gib das Guthaben für mehr Ressourcen aus.',
        'discord_earn_body_boost' => 'Lade mit deinem eigenen Invite-Link Leute ein oder booste den Server für :credits Guthaben — und gib alles für mehr Ressourcen aus.',
        'discord_earn_cta' => 'Invites ansehen',
        'store_title' => ':price Guthaben bringen :amount mehr RAM',
        'store_body' => 'Du hast :balance Guthaben. Gib es für CPU, RAM, Speicherplatz oder einen weiteren Server-Slot aus.',
        'store_cta' => 'Shop öffnen',
        'gain_unlock' => 'eigene Server',
        'gain_cpu' => '+:amount CPU',
        'gain_memory' => '+:amount RAM',
        'gain_disk' => '+:amount Speicherplatz',
        'gain_servers' => ':amount weitere Server',
        'gain_unlimited_cpu' => 'unbegrenzte CPU',
        'gain_unlimited_memory' => 'unbegrenztes RAM',
        'gain_unlimited_disk' => 'unbegrenzten Speicherplatz',
        'gain_unlimited_servers' => 'unbegrenzte Server',
        'gain_and' => 'und',
    ],

    // Die Topbar-Chips (PluggedHost\Core\Livewire\AccountChips). Überwiegend Tooltips: die
    // Zahlen werden in der Komponente formatiert, damit sie in beiden Sprachen identisch
    // sind — und ein Chip ohne Platz für ein Label muss sich beim Hovern selbst erklären.
    //
    // `store` ist die Ausnahme: ein echtes, sichtbares Label — und nur ein Fallback. Die
    // Schaltfläche liest ihren Namen von der Seite, die sie öffnet
    // (ResourceStorePage::getNavigationLabel()), damit Topbar und Seitenleiste dieselbe
    // Seite nicht unterschiedlich benennen können.
    'chips' => [
        'tokens_title' => 'Tokens für die Startwarteschlange',
        'credits_title' => 'Guthaben — Economy-Seite öffnen',
        'quota_title' => "CPU :cpu\nRAM :memory\nSpeicherplatz :disk\nServer :servers",
        'store' => 'Economy',
    ],

    // Willkommenstour beim ersten Login (PluggedHost\Core\Filament\App\Pages\WelcomeTourPage).
    // Jeder Schritt nennt ein echtes Bedienelement so, wie es in der Oberfläche wirklich heißt --
    // genau darum geht es: wer "geh in die Einstellungen" hört, soll die Einstellungen finden.
    //
    // `bullets` ist ein Array und die View rendert so viele, wie da sind; der Intro-Schritt hat
    // absichtlich keine, denn eine Textwand ist das, was übersprungen wird.
    'tour' => [
        'title' => 'Willkommen',
        'progress' => 'Schritt :step von :total',
        'acknowledge' => 'Verstanden',
        'next' => 'Weiter',
        'back' => 'Zurück',
        'skip' => 'Rest überspringen',
        'finish' => 'Zu meinen Servern',
        'menu' => 'Willkommenstour erneut ansehen',
        'steps' => [
            'intro' => [
                'heading' => 'Willkommen bei plugged.host',
                'body' => 'Das ist dein Panel für Gameserver. Alles, was du bei uns mietest, findest du hier: Server starten und stoppen, Dateien bearbeiten und ändern, was darauf läuft — alles an einer Stelle. Die nächsten Schritte zeigen dir, wo das jeweils liegt. Das dauert etwa eine Minute.',
            ],
            'servers' => [
                'heading' => 'Deine Server',
                'body' => 'Die Serverliste ist deine Startseite. Jeder Server, den du hast, ist dort eine Zeile.',
                'bullets' => [
                    'Klicke auf den Namen eines Servers, um ihn zu öffnen.',
                    'Server erstellen liegt in der Leiste über der Liste, links neben dem Suchfeld.',
                    'Der farbige Punkt in jeder Zeile zeigt, ob dieser Server läuft.',
                ],
            ],
            'console' => [
                'heading' => 'Die Konsole',
                'body' => 'Wenn du einen Server öffnest, landest du auf seiner Konsole — der laufenden Ausgabe des Servers selbst und dem schnellsten Weg zu sehen, was er tut.',
                'bullets' => [
                    'Starten, Stoppen und Neustarten sind die Schaltflächen oben auf dieser Seite.',
                    'Befehle kannst du direkt in das Feld unter dem Log eingeben.',
                    'Ein neuer Minecraft-Server zeigt hier eine Einrichtungshilfe — folge ihr, danach ist der Server spielbereit.',
                ],
            ],
            'inside' => [
                'heading' => 'Alles Weitere zu einem Server',
                'body' => 'Solange ein Server geöffnet ist, gehört die Seitenleiste links zu genau diesem Server. Die Einträge, die du am häufigsten brauchst:',
                'bullets' => [
                    'Dateien — alles auf dem Server hochladen, bearbeiten und löschen.',
                    'Backups — vor einer Änderung eines anlegen und zurückspielen, wenn etwas kaputtgeht.',
                    'Spieler — Whitelist, Operatoren und Bans, ohne eine Konfigurationsdatei zu bearbeiten.',
                    'Datenbanken — eine MySQL-Datenbank für ein Plugin oder eine Mod anlegen, die eine braucht.',
                    'Netzwerk — die Ports und Adressen, über die sich andere verbinden.',
                ],
            ],
            'settings' => [
                'heading' => 'Einstellungen und dein Guthaben',
                'body' => 'Die Einstellungen sind eine Seite pro Server und enthalten alles zu diesem Server: umbenennen, mehr RAM geben, die Version wechseln oder neu installieren.',
                'bullets' => [
                    'Die Münze in der Topbar sind deine Tokens — damit überspringt ein Server die Startwarteschlange.',
                    'Das $ daneben ist dein Guthaben. Ein Klick öffnet den Shop für mehr Ressourcen.',
                    'Diese Tour kannst du jederzeit über das Menü bei deinem Avatar erneut öffnen.',
                ],
            ],
        ],
    ],

    // Erzwungenes Passwort für Konten, die per OAuth-Login angelegt wurden
    // (PluggedHost\Core\Filament\App\Pages\SetPasswordPage).
    'set_password' => [
        'title' => 'Passwort festlegen',
        'heading' => 'Konto fertig einrichten',
        'subheading' => 'Du hast dich mit Discord registriert, deshalb hat dein Konto noch kein Passwort. Lege jetzt eines fest — damit kommst du wieder hinein, falls du den Zugang zu deinem Discord-Konto verlierst.',
        'password' => 'Passwort',
        'password_helper' => 'Mindestens 8 Zeichen. Lang ist besser als kompliziert.',
        'confirmation' => 'Passwort bestätigen',
        'submit' => 'Passwort speichern',
        'logout' => 'Abmelden',
        'saved' => 'Passwort festgelegt',
        'saved_body' => 'Du kannst dich ab jetzt mit deiner E-Mail-Adresse und diesem Passwort anmelden — und weiterhin mit Discord.',
        'gate_title' => 'Nur noch ein Schritt',
        'gate_body' => 'Lege ein Passwort fest, bevor du weitermachst.',
    ],

    // Admin-Einstellungsformular (PluggedHostCorePlugin, über EnvironmentWriterTrait). Die
    // Abschnitte Rechtliches, Registrierung und Seitenleiste tragen ihre eigenen Labels; diese
    // Schlüssel decken die zusätzlich hinzugefügten Willkommenstour-Optionen ab.
    'settings' => [
        'section_tour' => 'Willkommenstour',
        'section_tour_hint' => 'Die Einführung beim ersten Login, durch die neue Benutzer geführt werden. Wird sie ausgeschaltet, entfällt die Weiterleitung für alle sofort.',
        'tour_enabled' => 'Willkommenstour anzeigen',
        'tour_enabled_hint' => 'Benutzer beim ersten Anmelden zur geführten Tour weiterleiten.',
        'tour_version' => 'Tour-Version',
        'tour_version_hint' => 'Erhöhe diesen Wert, um die Tour allen erneut zu zeigen, die sie bereits gesehen haben. Bei reinen Textkorrekturen unverändert lassen.',
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
    ],

    'noprofit' => [
        'manage_account' => 'Auf noprofit.space verwalten',
        'managed_hint' => 'Anmeldedaten, Passkeys, Zwei-Faktor-Codes, deine Discord-Verknüpfung und das Löschen deines Kontos werden auf noprofit.space verwaltet.',
        'reset_moved' => 'Konto zu noprofit.space umgezogen? Dein Passwort liegt jetzt dort.',
        'reset_moved_link' => 'accounts.noprofit.space öffnen',
    ],
];
