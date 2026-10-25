<?php

return [
    'plugin_name' => 'Minecraft Performance Analyser',

    'navigation_label' => 'Performance',
    'title' => 'Performance',

    'tabs' => [
        'overview' => 'Übersicht',
        'lag' => 'Lag-Analyse',
        'memory' => 'Speicher',
        'threads' => 'Threads',
    ],

    // Tabs, die mit einer späteren Version kommen.
    'later' => [
        'lag' => 'Die Lag-Analyse kommt mit einer späteren Version: ein Profil über 10, 30 oder 60 Sekunden, der Anteil der Tick-Zeit pro Mod, die Top-Methoden und ein Flamegraph.',
        'memory' => 'Der Tab Speicher kommt mit einer späteren Version: ein Klassen-Histogramm nach Mod gruppiert, aufklappbar bis zur Klasse.',
        'threads' => 'Der Tab Threads kommt mit einer späteren Version: ein Thread-Dump mit Filter nach Zustand, Deadlocks und der CPU-Zeit pro Thread.',
    ],

    // Von Pelican im Formular für Subuser gezeigt, die Schlüssel sind <gruppe>_title, <gruppe>_desc und <gruppe>_<recht>.
    'permissions' => [
        'performance_title' => 'Performance',
        'performance_desc' => 'Rechte für die Seite Performance (Minecraft Performance Analyser).',
        'performance_read' => 'Erlaubt, die Seite Performance zu öffnen und die Übersicht, die Diagnose und frühere Lag-Analysen zu sehen.',
        'performance_profile' => 'Erlaubt, eine Lag-Analyse (Profil) zu starten.',
        'performance_memory' => 'Erlaubt, ein Speicher-Histogramm zu erstellen. Das hält den Server kurz an.',
        'performance_threads' => 'Erlaubt, einen Thread-Dump zu erstellen.',
    ],

    // Tab auf der Bearbeiten-Seite einer Node im Adminbereich.
    'node' => [
        'tab' => 'MPA Bridge',
        'description' => 'Die MPA Bridge läuft auf der Node und liefert der Seite Performance eines Minecraft-Servers ihre Daten. Mit ihr spricht immer das Panel selbst, mit dem Token der Node, nie der Browser.',
        'url' => 'Bridge-Adresse',
        'url_helper' => 'Adresse, unter der das Panel die Bridge erreicht, z. B. http://10.0.0.5:8090 im internen Netz, wenn ein Proxy vor der Node nur den Wings-Port weiterleitet. Leer heißt: FQDN der Node mit Port 8090. Das Zertifikat wird nur bei https-Adressen geprüft.',
        'test' => 'Verbindung testen',
        'test_ok' => 'Die Bridge antwortet',
        'test_ok_body' => 'MPA Bridge :version unter :url, sie nimmt das Token dieser Node an. Speichere die Node, um die Adresse zu übernehmen.',
        'test_failed' => 'Die Bridge antwortet nicht',
    ],

    'errors' => [
        'unreachable' => 'Die MPA Bridge der Node ist unter :url nicht erreichbar. Ist sie installiert und gestartet, und lässt die Firewall das Panel durch?',
        'unauthorized' => 'Die MPA Bridge hat das Token der Node abgelehnt. Sie liest das Token aus der Wings-Config, starte die Bridge neu, nachdem sich das Token geändert hat.',
        'wrong_node' => 'Unter :url antwortet etwas anderes als die MPA Bridge dieser Node. Prüfe die Bridge-Adresse der Node im Adminbereich.',
        'invalid_response' => 'Die MPA Bridge hat eine Antwort geschickt, die das Plugin nicht versteht. Passen die Versionen von Plugin und Bridge zusammen?',
        'bridge_error' => 'Fehler in der MPA Bridge (HTTP :status), siehe ihr Log auf der Node (journalctl -u mpa-bridge).',
        'unexpected' => 'Etwas ist schiefgelaufen, siehe das Log des Panels.',

        // Fehlercodes der Bridge, siehe docs/API.md der Bridge. Bei unbekannten Codes steht die Meldung der Bridge da.
        'bridge' => [
            'server_not_running' => 'Der Server läuft nicht.',
            'no_java_process' => 'Im Container des Servers läuft kein Java-Prozess.',
            'profile_running' => 'Für diesen Server läuft schon eine Lag-Analyse.',
        ],
    ],
];
