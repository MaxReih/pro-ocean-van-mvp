# Umgebung

Stand: 2026-06-26

| Komponente | Erkannter Stand |
| --- | --- |
| WordPress | `7.0` aus `app/public/wp-includes/version.php` |
| PHP | In dieser Shell nicht auf dem PATH verfügbar (`php` nicht gefunden) |
| MySQL/MariaDB | Lokale WordPress-Konfiguration nutzt `DB_HOST=localhost`, `DB_NAME=local`, `DB_USER=root`; genaue Serverversion per Shell nicht ermittelbar |
| Node.js | In dieser Shell nicht auf dem PATH verfügbar (`node` nicht gefunden) |
| npm | In dieser Shell nicht auf dem PATH verfügbar (`npm` nicht gefunden) |
| Elementor | Kein installiertes Elementor-Plugin unter `wp-content/plugins` gefunden |

Hinweis: Die lokale Umgebung wirkt wie eine Local-WP-Installation mit `app`, `conf` und `logs`. PHP/MySQL koennen innerhalb der Local-App verfügbar sein, waren aber nicht im Codex-PowerShell-PATH.
