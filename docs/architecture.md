# Architektur

Das Plugin nutzt eigene WordPress-Tabellen für operative Daten und die WordPress Options API für Einstellungen.

## Hauptbestandteile

- `pro-ocean-van.php`: Bootstrap, Autoloading, Hooks.
- `src/Activation.php`: Tabellenanlage, Standardoptionen, Bundesländer, Cron für Cachebereinigung.
- `src/Repository`: SQL-Zugriff auf Anfragen, Klassen, Kalender, Termine, Vorschläge, Bundesländer und Routingcache.
- `src/Service`: Verfügbarkeit, Empfehlungen, interne Vorschläge, Wochencluster, Mail, ICS, Datenschutz.
- `src/Routing`: Providerinterfaces, Nominatim-, OSRM- und Null-Provider.
- `src/Rest`: Versionierte REST-Endpunkte unter `/wp-json/pro-ocean-van/v1/`.
- `src/Admin`: WordPress-Backend unter `Ocean Van`.
- `src/Frontend`: Shortcode und Asset-Registrierung.

Öffentliche Antworten enthalten keine personenbezogenen Daten. Bestätigte Termine werden öffentlich nur als Stadt ausgegeben.
