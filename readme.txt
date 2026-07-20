=== Pro Ocean Van Planner ===
Contributors: proocean
Tags: booking, calendar, routing
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.5.2
License: GPLv2 or later

Route-first Ocean-Van-Buchung und kostenoptimierte Tourenplanung für WordPress.

== Description ==

Das Plugin stellt den Shortcode [pro_ocean_van_booking], routenoptimierte Terminempfehlungen, serverseitige Geokodierung, ein Team-Cockpit, E-Mail-Funktionen und Kalenderexporte bereit.

== Installation ==

1. Pluginordner nach wp-content/plugins/pro-ocean-van kopieren.
2. Plugin aktivieren.
3. Unter Ocean Van → Einstellungen HeiGIT für Routing und Geocoding wählen, API-Schlüssel speichern und die Verbindung testen.
4. Startpunkt, Kosten, Personal und Team-E-Mail-Adressen pflegen.
5. Shortcode in eine Seite einfügen.

== Changelog ==

= 0.5.2 =
* Fehlende lokale Kalender-Assets werden vollständig mit dem Plugin ausgeliefert.
* Die öffentliche MVP-Demo löst PLZ und Routen ohne langsame externe Geo-Aufrufe auf.
* Buchungsanfragen brechen nicht erreichbare Routendienste nach spätestens zwölf Sekunden ab.
* Das kompakte Release-Archiv und der entfallene Frontend-Login verkürzen den Demo-Start.

= 0.5.1 =
* Kalenderexport direkt im Backend-Kalender sichtbar gemacht.

= 0.5.0 =
* Empfehlungen kombinieren Routenoptimierung, Wochenfüllung und regionale Bündelung nach Bundesland.
* Bei Anfragen aus mehreren Bundesländern werden passende Regionalwochen bevorzugt.
* Kilometer- und Ersparnisangaben wurden aus den öffentlichen Terminkarten entfernt.
* Google-Kalender-Link und ICS-Export bleiben für bestätigte Termine verfügbar.

= 0.4.2 =
* Vergangene Kalendertage sind gesperrt und grau dargestellt.
* Öffentliche Routentermine beginnen frühestens 14 Tage nach der Anfrage.
* PLZ werden vor der HeiGIT-Geoanalyse zuverlässig einem Ort zugeordnet.

= 0.4.1 =
* Direkte Adresskorrektur und erneute Geoanalyse für nicht planbare Stopps.
* Detaillierter Verbindungstest für Adressprüfung und Straßenrouting.
* HeiGIT/Pelias-Ländersuche korrigiert und lokale SSL-Entwicklung abgesichert.

= 0.4.0 =
* KW-Touren als Tagesroute mit Fahrzeiten und Übernachtungsregionen.
* Statistik nach Woche, Monat und Jahr inklusive pflegbarer Personalkosten.
* Verbindungstest für die produktive HeiGIT-Geoanalyse.
* Vorbefüllte, pflegbare Antwortvorlagen.

= 0.3.0 =
Zwei routenoptimierte Frontend-Termine, vereinfachte Team-Inbox, textbasierte Tourabschnitte, Team-Benachrichtigungen, Antwortworkflow und HeiGIT-Live-Provider.

= 0.2.0 =
Neue Buchungsoberfläche, serverseitige Geokodierung, kostenoptimierte Tourwochen, Einfügekosten-Vorschläge und erweitertes Operations-Cockpit.

= 0.1.0 =
Initialer MVP.
