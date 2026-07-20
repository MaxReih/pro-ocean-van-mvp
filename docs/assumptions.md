# Annahmen

- Das MVP wird als eigenständiges Plugin unter `wp-content/plugins/pro-ocean-van` installiert.
- Die gelieferten SVG-Dateien `05_Pro_Ocean_Logo_Blau.svg` und `07_Pro_Ocean_Symbol_Blau.svg` sind unverändert unter `assets/brand/` eingebunden.
- Bundesland `BW` ist initial aktiv, alle weiteren Bundesländer sind initial deaktiviert und im Backend aktivierbar.
- Der Standardstartpunkt ist `Tübingen`, Koordinaten werden nicht geraten und müssen im Backend gesetzt oder über einen Geocoding-Provider ermittelt werden.
- Ohne Kilometersatz und Kosten- oder Distanzgrenze werden keine konkreten öffentlichen Routenvorschläge angezeigt.
- Ohne Routing- oder Geocoding-Provider bleibt die Buchungsanfrage als Zeitraum-Anfrage möglich.
- Für lokale Tests aktiviert das Plugin bei leerer Konfiguration Nominatim/OpenStreetMap, den öffentlichen OSRM-Demodienst und einen Citroën-Jumper-Testkilometersatz von `0.85` €/km. Diese öffentlichen Endpunkte sind nicht für den Live-Betrieb vorgesehen.
- Im Live-Betrieb werden HeiGIT/openrouteservice und HeiGIT/Pelias mit einem ausschließlich serverseitig gespeicherten API-Schlüssel genutzt. Ein Verbindungstest prüft Geocoding, Distanz und Fahrzeitmatrix.
- Der Testkilometersatz ist bewusst höher als reine Kraftstoffkosten, weil er Verschleiß, Wartung, Reifen, Versicherung und organisatorische Betriebskosten pauschal mit abbildet.
- Öffentliche Routenvorschläge werden nur für Wochentage erzeugt; Samstage und Sonntage werden bewusst nicht angeboten.
- Ein Tag gilt für die Testversion als sinnvoll machbar, wenn die konfigurierte Kosten- und Distanzgrenze eingehalten wird. Standardmäßig sind das maximal `350` km Rundstrecke und maximal `300` € Fahrtkosten.
- Bestätigte Termine in derselben Kalenderwoche dienen als Touranker: bis `90` km Entfernung ist ein weiterer Ort sehr gut, bis `150` km gut und bis `220` km noch vertretbar. Weiter entfernte Orte werden für diese Tourwoche nicht vorgeschlagen.
- Bundeslandgrenzen sind kein hartes Kriterium für Vorschläge. Entscheidend sind Entfernung, Kosten und vorhandene Touranker, damit nahe Orte über Landesgrenzen hinweg möglich bleiben und weit entfernte Orte ausgeschlossen werden.
- Im MVP wird ein Einsatz pro Tag durch einen eindeutigen Index auf `pov_appointments.appointment_date` und Anwendungslogik abgesichert.
- Wochencluster werden per Straßenmatrix, Nearest-Neighbour und 2-opt optimiert. Bestätigte Termine behalten ihre chronologische Reihenfolge; flexible Anfragen werden kostenoptimiert eingefügt.
- Übernachtungsvorschläge nennen bewusst Regionen bzw. Orte, keine konkreten Hotels. Sie entstehen zwischen getrennten Einsatztagen und vor Rückfahrten ab drei Stunden.
- Die Statistik wertet bestätigte Einsätze aus. Personalkosten basieren auf pflegbarem Stundensatz, Teamgröße, Einsatzdauer und anteiliger Fahrzeit.
