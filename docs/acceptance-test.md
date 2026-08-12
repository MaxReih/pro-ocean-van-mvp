# Manuelle Abnahmetests

## Rollen und Zugriff

| Rolle | Van Operations | Anfragen | Touren | Kalender | Statistik | Ändern / Antworten | Kalenderexport | Einstellungen |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Administrator | ja | ja | ja | ja | ja | ja | ja | `wp-admin` |
| Ocean Van Team | ja | ja | ja | ja | ja | ja | ja | nein |
| Ocean Van Lesend | ja | nein | ja | ja | ja | nein | nein | nein |
| Abonnent / ohne Van-Rolle | nein | nein | nein | nein | nein | nein | nein | nein |

Jede Person verwendet im Teamportal ein eigenes WordPress-Konto. Die öffentliche Buchungsseite kann zusätzlich über den Backend-Schalter mit dem Präsentationspasswort `Ocean` geschützt werden.

## Portal und Sicherheit

1. `/van-operations/` abgemeldet öffnen: WordPress leitet zur Anmeldung weiter und setzt das Portal als Rücksprungziel.
2. Als Ocean Van Team anmelden: Das Portal öffnet sich außerhalb von `wp-admin` mit Anfragen, Tourplanung, Kalender und Statistik.
3. Einen bisherigen Link wie `wp-admin/admin.php?page=pov-routes` öffnen: Er führt zur entsprechenden Portalansicht.
4. Als Ocean Van Lesend anmelden: Nur Tourplanung, Kalender und Statistik sind sichtbar. Anfragen, Kontaktdaten, Kalenderexport und sämtliche Änderungsformulare fehlen.
5. Als Abonnent `/van-operations/` und eine operative REST-Route direkt öffnen: Der Zugriff wird mit `403` abgewiesen.
6. Als Teammitglied eine technische Einstellungsseite direkt öffnen: Der Zugriff wird verweigert beziehungsweise ins Portal zurückgeführt.
7. Als Administrator `Ocean Van > Einstellungen` öffnen: Nur die technischen Einstellungen liegen im WordPress-Backend; operative Unterseiten sind dort nicht registriert.
8. Antwort-, Adress-, Tour- und Kalenderaktionen ohne gültigen Nonce oder ohne passende Capability absenden: Die Aktion wird abgewiesen.
9. Portalantwort prüfen: `Cache-Control` verhindert privates Caching und `X-Robots-Tag` beziehungsweise das Robots-Meta-Tag stehen auf `noindex, nofollow`.
10. Neue Anfrage auslösen und Team-Mail prüfen: Der Link öffnet die Anfrage unter `/van-operations/`, nicht in `wp-admin`.
11. Unter `Ocean Van > Einstellungen` den Schalter „Buchungsseite schützen“ aktivieren: Buchungsseite und öffentliche REST-Endpunkte sind ohne Freigabe gesperrt.
12. Ein falsches Passwort eingeben: Es erscheint eine eindeutige Fehlermeldung, ohne dass das Formular geladen wird. Mit `Ocean` öffnen: Kalender und Routenvorschläge funktionieren innerhalb derselben Sitzung.

## Buchung und Betrieb

1. Plugin aktivieren und prüfen, dass keine Aktivierungsfehler erscheinen und die Seite `/van-operations/` angelegt ist.
2. Seite mit `[pro_ocean_van_booking]` öffnen: Der Kalender ist sofort sichtbar.
3. OSM-Testprofil prüfen: Nominatim, OSRM, Startpunkt Tübingen und `0,85 € / km` sind gesetzt.
4. Mit aktivem Bundesland und gültiger PLZ `72072` auf `Termine prüfen` klicken: Die Route wird über Nominatim und OSRM geprüft.
5. Wenn mehrere Werktage in einer guten Tourwoche liegen, `Diese Woche anfragen` wählen: Das Zeitraumformular öffnet sich mit Montag bis Freitag und übernimmt die PLZ.
6. Eine Anfrage mit Zeitraum, mehreren Klassen, verpflichtender Stellplatzart und optionaler Entfernung zum Außen-Stromanschluss absenden: Die Angaben erscheinen im Portal.
7. Prüfen, dass Mailpit eine Eingangsbestätigung erhält, sofern WordPress-Mail lokal auf Mailpit zeigt.
8. Für die Anfrage Vorschläge neu berechnen: Maximal fünf Vorschläge werden angezeigt.
9. Einen Vorschlag akzeptieren und die Vorschlagsmail senden: Die Mail enthält Schaltflächen zum Annehmen der vorgeschlagenen Termine.
10. Einen Annahme-Link in der Mail öffnen: Der Vorschlag wird angenommen, Alternativen laufen ab und die Anfrage steht auf `accepted`.
11. Termin bestätigen: Der Termin erscheint im Kalender und blockiert den Tag öffentlich.
12. Öffentliche Kalender-API für den Termin prüfen: Die Antwort enthält nur die Stadt, keine Adresse und keine Kontaktdaten.
13. ICS herunterladen und den Google-Kalender-Link öffnen: Kontaktdaten, E-Mail-Adresse und Telefonnummer sind enthalten.
14. Im Portal-Kalender einen Tag blockieren: Der Tag ist öffentlich nicht auswählbar.
15. Eine offene Anfrage für denselben Tag anlegen: Der öffentliche Kalender bleibt dadurch buchbar.
16. Ein Bundesland deaktivieren und das Buchungsformular absenden: Die Anfrage wird serverseitig abgelehnt.
17. Im Portal-Kalender einen Zeitraum speichern: Alle Tage im Zeitraum erhalten denselben Status.
18. Für denselben Zeitraum Startpunkt-PLZ und Ort eintragen: Der Startpunkt wird per konfiguriertem Geocoder ermittelt und für die Tage gespeichert.
19. Als Administrator den Datenschutzexport herunterladen und anschließend die Anfrage anonymisieren.

## Beispieldaten

- Standardstartpunkt: Tübingen
- Bestätigte Termine: Freiburg und Ulm
- Blockierter Tag und Tag nur auf Anfrage: zwei freie Tage derselben Woche
- Offene Anfragen: Reutlingen und Freiburg
- Anfrage mit fehlendem Strom
- Anfrage mit mehreren Klassen
