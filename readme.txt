=== Pro Ocean Van Planner ===
Contributors: proocean
Tags: booking, calendar, routing
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.9.3
License: GPLv2 or later

Route-first Ocean-Van-Buchung und kostenoptimierte Tourenplanung für WordPress.

== Description ==

Das Plugin stellt den Shortcode [pro_ocean_van_booking], routenoptimierte Terminempfehlungen, serverseitige Geokodierung, ein Team-Cockpit, E-Mail-Funktionen und Kalenderexporte bereit.

== Installation ==

1. Pluginordner nach wp-content/plugins/pro-ocean-van kopieren.
2. Plugin aktivieren.
3. Im WordPress-Menü Ocean Van HeiGIT für Routing und Geocoding wählen, API-Schlüssel speichern und die Verbindung testen.
4. Startpunkt, Kosten, Personal und Team-E-Mail-Adressen pflegen.
5. Shortcode in eine Seite einfügen.

== Changelog ==

= 0.9.3 =
* Der Kalender ist die einzige Oberfläche für Einzel- und Zeitraumwahl; ein kompakter Weiter-Button bestätigt die Auswahl.
* Kalenderkacheln zeigen nur noch das Datum: Routenvorschläge bleiben grün, anfragbare Wochenenden gelb und ausgewählte Zeiträume einheitlich grau.
* Redundante Hinweise, Farblegenden und der separate Wunschzeitraum wurden entfernt; Buttons und Statusflächen sind kompakter.

= 0.9.2 =
* Kalendertermine werden vor dem Wechsel ins Formular sichtbar markiert und ausdrücklich bestätigt.
* Ein zweiter Kalendertag bildet einen Zeitraum; alternativ lässt sich die vollständige Kalenderwoche markieren.
* Einzeltag, Zeitraum und Woche werden barrierearm sowie mobil optimiert hervorgehoben.

= 0.9.1 =
* Saisonale Bundeslandfreigaben steuern direkt, welche Einsatzorte in definierten Zeiträumen vorgeschlagen und angefragt werden können.
* Manuell angelegte Tourstopps erfassen Kontakt, Einsatzort und Planungsgrundlagen; öffentliche Events bleiben mit ihrer Anfrage verknüpft.
* Terminvorschläge, Kalenderhinweis und Wunschzeitraum wurden klarer formuliert; die beste Option ist nicht mehr irreführend gelb hervorgehoben.
* Öffentliche Events werden in Statistik und Kalenderexport ohne Dubletten verarbeitet; Kontaktdaten fließen kompakt in den Team-Kalenderexport ein.

= 0.9.0 =
* Klarerer, dauerhaft sichtbarer Terminworkflow mit zwei Routenvorschlägen, passender Woche und anfragbaren Wochenendtagen.
* Öffentliche Kalenderbegriffe sind vereinheitlicht; nicht buchbare Tage sind deutlicher markiert.
* Schultermine erfassen 45/60/90 Minuten, konfigurierbare Klassenstufen und zusätzliche Google-Maps-/HDMI-Angaben.
* Backend mit direkt auffindbarer Bearbeitung, einklappbarem Kommunikationsverlauf und ergänzenden Mailingvorlagen.
* Manuelle Teamtermine und Kalenderexporte für öffentliche Events wurden ergänzt.

= 0.8.4 =
* Anfragen mit genau einer erwachsenen Person werden auch bei Schulterminen akzeptiert.
* Der Außenstrom-Block erfasst Anschlussort und benötigte Kabellänge in klar sichtbaren Eingabefeldern.
* Größere Abstände und Eingabeflächen verbessern die Lesbarkeit der Vor-Ort-Fragen.

= 0.8.3 =
* Optionaler Passwortschutz für Buchungsseite, Kalender, Routenvorschläge und Anfrageversand; der Backend-Schalter verwendet das vereinbarte Passwort „Ocean“.
* Die Stellplatzart ist eine verpflichtende Auswahl, während der Google-Maps-Link freiwillig bleibt.
* Der Stromanschluss am Außenbereich wird als optionale Entfernung in Metern statt als ungenaue Ja/Nein-Angabe erfasst.

= 0.8.2 =
* Vor-Ort-Angaben einschließlich Stellplatz und Google-Maps-Link sind optional; die Stellplatz-Ja/Nein-Frage entfällt.
* Schulische Zeitangaben heißen nun „Hinweise / Wünsche zur zeitlichen Planung“.
* Tourwochen öffnen ihre Stopps als gemeinsame Google-Maps-POI-Suche statt als Navigationsroute; die Demo zeigt die geplanten Tourräume von Oktober 2026 bis September 2027.

= 0.8.1 =
* Website der Institution bleibt optional; Vor-Ort-Angaben wurden auf eindeutige Ja/Nein-Antworten und Google-Maps-Links für Stellplätze reduziert.
* Innen- und Außenflächen, Schlechtwetteroption, Stromversorgung, Umkleide, Dusche, Gewässernähe, WLAN und technische Ausstattung werden strukturiert erfasst.
* Die Tourplanung öffnet je Tourwoche eine gemeinsame Google-Maps-Route mit Start, allen Stopps und Rückkehr zum Ausgangspunkt.

= 0.8.0 =
* Öffentliche Ansprache auf „Hol das Meer zu dir“ vereinheitlicht und Kalenderstatus in „Noch verfügbar“ sowie „Öffentliches Event“ umbenannt.
* Detailformular schaltet Zusatzfragen für Schule, Veranstaltung und Sonstiges dynamisch und erfasst Funktion, Website, Altersangaben, Klassenstruktur und besondere Anforderungen.
* Zeitslot, Schulstunden und Pausen wurden in die Detailfragen verschoben; der notwendige Vorbereitungszugang wird direkt erläutert.
* Vor-Ort-Planung um Innen- und Außenflächen, Schlechtwetteroption, eindeutige Ja/Nein-Angaben sowie detaillierte Van-Stellplatzdaten erweitert.
* Tourstopps können ohne Kartenansicht direkt in Google Maps geöffnet werden.

= 0.7.2 =
* Redundante freie Erfassung von Übernachtungskosten aus der Tourplanung entfernt.
* Kostenstatistik in kompakte, responsive Zeitraumkarten mit klarer Kostenaufteilung überführt.

= 0.7.1 =
* HeiGIT-Ausfälle werden nach kurzem Timeout automatisch über die konfigurierten OSRM- und Nominatim-Dienste abgefangen.
* Eine fünfminütige Ausfallsperre verhindert wiederholte langsame HeiGIT-Aufrufe während einer Störung.
* PLZ-Prüfungen bleiben auch dann nutzbar, wenn das externe PLZ-Verzeichnis keinen Datensatz liefert.

= 0.7.0 =
* Walk-in-Events mit eigener Kalenderfarbe, öffentlichem Detaildialog und pflegbaren Teilnehmerzahlen ergänzt.
* Kalender bleibt im Frontend geöffnet und zeigt nach der PLZ-Prüfung ausschließlich geo-geeignete Tage als buchbar.
* Anfragebearbeitung um sichere Anhänge, automatisch protokollierte ausgehende Nachrichten, manuell dokumentierbare eingehende Rückmeldungen sowie kontrollierte Verschiebung, Stornierung und Reaktivierung erweitert.
* Tourplanung um frei erfassbare Übernachtungskosten und Statistik um Veranstaltungsarten sowie erreichte Kinder und Erwachsene ergänzt.
* Serverseitige Routensignatur, konkurrenzsichere Terminvergabe und Datenmigrationen für Bestandsanfragen ergänzt.

= 0.6.1 =
* Portalbreite im eigenständigen Frontend ohne horizontales Überlaufen korrigiert.
* Geschützte Portal-Seite auch aus dynamischen öffentlichen Seitenlisten entfernt.
* Deterministisches Demo-Routing zeigt keine irreführende Anbieterwarnung mehr.

= 0.6.0 =
* Operative Planung in ein eigenes, login-geschütztes Van-Operations-Portal verschoben.
* Persönliche Team- und Leserrollen mit getrennten Berechtigungen für Anfragen, Touren, Kalender, Statistik und Export ergänzt.
* WordPress-Backend auf Benutzerverwaltung und technische Einstellungen reduziert.
* Portal gegen Caching, Suchmaschinenindexierung und unberechtigten Zugriff abgesichert.
* Benachrichtigungslinks und alle operativen Rückwege führen direkt ins Portal.

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
