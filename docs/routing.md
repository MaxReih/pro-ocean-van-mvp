# Routing

Das Plugin kapselt externe Dienste über Providerklassen.

## Provider

- `NullRoutingProvider`: Standardfallback ohne externe Requests.
- `NominatimGeocodingProvider`: Nominatim-kompatibles Geocoding.
- `OsrmRoutingProvider`: OSRM-kompatibles Routing inklusive Distanzmatrix über `/table/v1/driving`.

## Konfiguration

In `Ocean Van > Einstellungen`:

- `Geocoding Provider`: `nominatim`
- `Geocoding Basis-URL`: eigene Nominatim-kompatible URL
- `Routing Provider`: `osrm`
- `Routing Basis-URL`: eigene OSRM-kompatible URL

## Testprofil

Für eine voll funktionsfähige Testversion setzt das Plugin bei leerer Konfiguration automatisch:

- Geocoding: `https://nominatim.openstreetmap.org/`
- Routing: `https://router.project-osrm.org/`
- Startpunkt: Tübingen, `48.5216364`, `9.0576448`
- Kilometersatz: `0.85` €/km
- Max. Empfehlungskosten: `300` €
- Max. Empfehlungsdistanz: `350` km

Das Backend kennzeichnet diese Konfiguration als OSM-Testmodus. Die öffentlichen Dienste sind für Entwicklung und Tests gedacht, nicht für verlässlichen Produktivbetrieb.

## Citroën-Jumper-Kostenannahme

Der Testwert `0.85` €/km ist ein konservativer operativer Satz für einen Citroën Jumper Van. Er deckt im Testmodell nicht nur Diesel, sondern auch Verschleiß, Wartung, Reifen, Versicherung und organisatorische Betriebskosten ab. Der Wert ist im Backend editierbar.

## Fallback

Anfrageadressen werden ausschließlich serverseitig geokodiert. Vom Browser übertragene Koordinaten oder Kostenwerte werden verworfen. Schlägt das Routing fehl, bleibt die Anfrage möglich und wird im Cockpit als „Route offen“ markiert.

## Kostenoptimierung

Für eine Tourwoche wird eine gerichtete OSRM-Distanzmatrix aus Depot und Stopps geladen. Daraus erzeugt der Planer deterministisch eine Nearest-Neighbour-Reihenfolge und verbessert sie anschließend mit 2-opt. Angezeigt werden gebündelte Strecke und Kosten, die Summe einzelner Rundfahrten sowie das geschätzte Sparpotenzial.

Terminvorschläge für eine Anfrage werden nach den tatsächlichen zusätzlichen Kilometern beim Einfügen in eine vorhandene Tour bewertet. Ist keine Straßenmatrix verfügbar, nutzt der Optimierer Haversine-Distanzen mit einem dokumentierten Straßenfaktor. Die Oberfläche kennzeichnet diese Werte als Näherung.
