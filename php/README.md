# PHP-Neuimplementierung

Dieser Ordner ist der Beginn einer eigenständigen, frameworkfreien PHP-Version.
Der Django-Bestand im Repository bleibt während der Entwicklung unverändert.
Die bisherige 2FA-Funktion wird nicht übernommen; sie war nur für Tests
eingerichtet.

## Datenbankschema

`database/schema.sql` legt das MariaDB-Schema für Benutzer, Schüler,
Erziehungsberechtigte, Gespräche, Auswahlkataloge, Gesprächs-Marketingkanäle
und Exportprotokolle an. Die Beziehungen und wesentlichen Wertebereiche
orientieren sich an `gespraeche/models.py`.

Das Schema enthält keine Test- oder Echtdaten und sollte nur in einer eigens
angelegten Entwicklungsdatenbank ausgeführt werden. Die PHP-Anwendung und
Verwaltungsmasken sind noch nicht implementiert.

## Voraussetzungen für den späteren Betrieb

- PHP mit `PDO` und `pdo_mysql`
- MariaDB mit InnoDB-Unterstützung
- HTTPS im Produktivbetrieb

Es werden vorerst weder Laravel noch Composer-Pakete vorausgesetzt.
