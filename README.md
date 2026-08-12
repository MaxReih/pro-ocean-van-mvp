# Pro Ocean Van Planner

Installierbares WordPress-Plugin für die öffentliche Ocean-Van-Buchung und die operative Tourenplanung im Team.

## MVP-Demo

Das Repository enthält eine öffentliche, temporäre WordPress-Demo für Teampräsentationen. GitHub Pages stellt die Startseite bereit; WordPress Playground startet daraus eine frische WordPress-Instanz direkt im Browser.

**[Öffentliche Team-Demo starten](https://maxreih.github.io/pro-ocean-van-mvp/)**

- Öffentliche Buchungsoberfläche und Team-Cockpit
- Dynamisch datierte Beispielanfragen und bestätigte Termine
- Keine realen Kontaktdaten
- Mailversand in der Demo deaktiviert
- Keine Installation und kein manuelles Login für Testende erforderlich
- Netzwerkunabhängige PLZ- und Routenprüfung in der Präsentationsdemo

Der einmalige WordPress-Start kann je nach Gerät und Verbindung etwas dauern; die anschließende PLZ- und Routensuche antwortet ohne externe Geo-Wartezeit. Jede Person erhält eine eigene temporäre Demo-Instanz im Browser.

## Funktionsumfang

- Route-first Buchungsoberfläche mit PLZ-Prüfung, Tourwochen und freien Alternativterminen
- Dynamische Detailfragen für Schule, Veranstaltung und Sonstiges inklusive Zeitfenster, Teilnehmenden und Anforderungen vor Ort
- Serverseitige Adressgeokodierung; Koordinaten und Kosten aus dem Browser werden nicht übernommen
- Kostenoptimierte Wochenrouten über OSRM-Distanzmatrizen, Nearest Neighbour und 2-opt
- Terminvorschläge aus Routenoptimierung, Wochenfüllung und regionaler Bündelung nach Bundesland
- Login-geschütztes Van-Operations-Portal für Anfrage-Workflow, Tourplanung, Kalender und Statistik
- Persönliche Rollen „Ocean Van Team“ und „Ocean Van Lesend“ mit getrennten Berechtigungen
- ICS-Download und vorausgefüllter Google-Kalender-Link für bestätigte Termine
- Direkte Google-Maps-Links für Tourstopps im Team-Portal
- Zuschaltbarer Passwortschutz für die öffentliche Buchung inklusive ihrer API-Aufrufe

## Installation

1. Ordner `pro-ocean-van` nach `wp-content/plugins/` kopieren.
2. Plugin `Pro Ocean Van Planner` in WordPress aktivieren.
3. Unter `Ocean Van` mindestens aktive Bundesländer, Kilometersatz und Grenzwerte pflegen.
4. Unter `Benutzer` die Rolle `Ocean Van Team` oder `Ocean Van Lesend` zuweisen.
5. Das Team arbeitet unter `/van-operations/`; technische Einstellungen bleiben in WordPress.
6. Shortcode `[pro_ocean_van_booking]` auf einer Seite einfügen.

Optional kann unter `Ocean Van > Einstellungen` die Buchungsseite mit dem Passwort `Ocean` geschützt werden.

## Hybrides Betriebsmodell

- `/van-operations/`: Anfragen, Tourplanung, Kalender und Statistik
- WordPress-Backend: Benutzer, Rollen und technische Konfiguration
- `Ocean Van Team`: operative Bearbeitung und Kalenderexport
- `Ocean Van Lesend`: Touren, Kalender und Statistik ohne Kontakt- oder Exportdaten

Das Portal nutzt die persönlichen WordPress-Logins. Es wird weder öffentlich gecacht noch von Suchmaschinen indexiert.

## Entwicklung

Das Plugin ist bewusst ohne externe CDN-Abhängigkeiten umgesetzt. Die produktiven Assets liegen in `assets/dist`.

```bash
npm run build:zip
```

Der Build-Befehl erzeugt `pro-ocean-van.zip` aus dem Pluginordner.

## Routing

Für die lokale Testversion aktiviert das Plugin bei fehlender Konfiguration automatisch ein OSM-Testprofil:

- Geocoding: Nominatim/OpenStreetMap
- Routing: öffentlicher OSRM-Demodienst
- Startpunkt: Tübingen
- Citroën-Jumper-Testkilometersatz: 0,85 €/km

Das ist für Tests voll funktionsfähig, aber nicht als Produktivbetrieb gedacht. Für echte Nutzung eigene Nominatim- und OSRM-kompatible Dienste eintragen.

Die Basis-URLs werden im WordPress-Menü `Ocean Van` gepflegt. Dort kann das OSM-Testprofil auch erneut aktiviert werden.

## Mailpit

Das Plugin nutzt ausschließlich `wp_mail()`. Mailpit funktioniert über die lokale WordPress-Mailkonfiguration, ohne Sonderlogik im Plugin.

## Tests

Die PHPUnit-Beispieltests liegen unter `tests/`. Zusätzlich gibt es einen lokalen statischen Testlauf, der in dieser PowerShell-Umgebung funktioniert:

```powershell
npm run test
```

Ohne npm direkt:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run-static.ps1
```

Der deterministische Optimierer-Test kann ohne WordPress-Bootstrap ausgeführt werden:

```powershell
php tests/run-route-optimizer.php
```
