# Manuelle Abnahmetests

1. Plugin aktivieren und prüfen, dass keine Aktivierungsfehler erscheinen.
2. Seite mit `[pro_ocean_van_booking]` öffnen: Kalender ist sofort sichtbar.
3. OSM-Testprofil prüfen: Nominatim, OSRM, Startpunkt Tübingen und `0.85` €/km sind gesetzt.
4. Mit aktivem Bundesland und gültiger PLZ `72072` auf `Termine prüfen` klicken: Die Route wird über Nominatim/OSRM geprüft.
5. Wenn mehrere Werktage in einer guten Tourwoche liegen, `Diese Woche anfragen` wählen: Das Zeitraumformular öffnet sich mit Montag bis Freitag und übernimmt die PLZ aus Schritt 1.
6. Eine Anfrage mit Zeitraum, mehreren Klassen und `Strom = Nein` absenden: Anfrage erscheint im Backend mit Warnchip.
7. Prüfen, dass Mailpit eine Eingangsbestätigung erhält, sofern WordPress-Mail lokal auf Mailpit zeigt.
8. Für die Anfrage Vorschläge neu berechnen: maximal fünf Vorschläge werden angezeigt.
9. Einen Vorschlag akzeptieren und Vorschlagsmail senden: Die Mail enthält Buttons zum Annehmen der vorgeschlagenen Termine.
10. Einen Annahme-Button in der Mail öffnen: Der Vorschlag wird als angenommen markiert, Alternativen laufen ab und die Anfrage steht auf `accepted`.
11. Termin bestätigen: Termin erscheint in der Übersicht und blockiert den Tag öffentlich.
12. Öffentliche Kalender-API für den Termin prüfen: Antwort enthält nur Stadt, keine Adresse und keine Kontaktdaten.
13. ICS herunterladen und Google-Kalender-Link öffnen: Kontaktdaten, E-Mail-Adresse und Telefonnummer sind enthalten.
14. Unter Kalender einen Tag blockieren: Tag ist öffentlich nicht auswählbar.
15. Offene Anfrage für denselben Tag anlegen: öffentlicher Kalender bleibt dadurch nicht blockiert.
16. Bundesland deaktivieren und Formular absenden: Anfrage wird serverseitig abgelehnt.
17. In Ocean Van > Kalender einen Zeitraum mit Datum von und Datum bis speichern: Alle Tage im Zeitraum erhalten denselben Status.
18. Für denselben Zeitraum Startpunkt PLZ und Startpunkt Ort eintragen: Der Startpunkt wird per OSM/Nominatim geokodiert und für die Tage gespeichert.
19. Datenschutzexport herunterladen und anschließend Anfrage anonymisieren.

## Beispieldaten

- Standardstartpunkt: Tübingen
- Bestätigter Termin: Freiburg
- Bestätigter Termin: Ulm
- Blockierter Tag: ein freier Tag derselben Woche
- Tag nur auf Anfrage: ein weiterer freier Tag derselben Woche
- Offene Anfrage: Reutlingen
- Offene Anfrage: Freiburg
- Anfrage mit fehlendem Strom
- Anfrage mit mehreren Klassen
