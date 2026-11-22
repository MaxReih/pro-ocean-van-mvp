# Installation

1. Pluginordner `pro-ocean-van` in `wp-content/plugins/` ablegen.
2. In WordPress `Pro Ocean Van Planner` aktivieren.
3. Seite erstellen und Shortcode einfügen:

```text
[pro_ocean_van_booking]
```

4. Unter `Ocean Van > Einstellungen` konfigurieren:

- aktive Bundesländer,
- Kilometersatz,
- maximale öffentliche Empfehlungskosten oder maximale Distanz,
- optional Routing- und Geocoding-Basis-URLs,
- E-Mail-Absender und Textvorlagen,
- Datenschutz-URL.

5. Im Team-Cockpit unter **Kalender** blockierte oder eingeschränkte Tage pflegen.

## Einstellungen für das Ocean-Van-Team

- Persönliche WordPress-Konten öffnen das Team-Cockpit unter `/van-operations/`. Administratoren verwenden `/wp-admin/` für **Ocean Van → Einstellungen**.
- Unter **E-Mail & Datenschutz** stehen Absenderadresse, Empfänger und Mailvorlagen. Eine neue Absenderadresse erst hinterlegen, wenn sie für den eingesetzten Maildienst freigegeben ist. `{Buchungsübersicht}` platziert die Übersicht in der Eingangsbestätigung.
- Alle Bundesländer sind gespeichert. Unter **Einstellungen → Bundesländer** steuert das Team die allgemeine Freigabe. Unter **Kalender → Saisonale Bundesländer** werden geplante Tourregionen je Zeitraum hinterlegt. Der öffentliche Kontaktlink ist unter **E-Mail & Datenschutz** einstellbar.
