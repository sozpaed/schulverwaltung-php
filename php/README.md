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
angelegten Entwicklungsdatenbank ausgeführt werden. Die PHP-Version bietet
derzeit Anmeldung sowie eine Schülerübersicht zum Suchen, Anlegen und Bearbeiten;
weitere Verwaltungsfunktionen folgen schrittweise.

## Voraussetzungen für den späteren Betrieb

- PHP mit `PDO` und `pdo_mysql`
- MariaDB mit InnoDB-Unterstützung
- HTTPS im Produktivbetrieb

Es werden vorerst weder Laravel noch Composer-Pakete vorausgesetzt.

## Technisches Grundgerüst

`src/Database.php` stellt eine PDO-Verbindung zur MariaDB her. Die Verbindung
liest ihre Einstellungen ausschließlich aus Umgebungsvariablen des PHP-Prozesses:

| Variable | Erforderlich | Beschreibung |
|---|---|---|
| `DB_HOST` | Ja | Hostname oder IP-Adresse des MariaDB-Servers |
| `DB_PORT` | Nein | Port des Servers, standardmäßig `3306` |
| `DB_NAME` | Ja | Name der Entwicklungsdatenbank |
| `DB_USER` | Ja | Datenbankbenutzer |
| `DB_PASSWORD` | Ja | Passwort des Datenbankbenutzers; darf leer sein |

Die Zugangsdaten gehören nicht ins Repository. Ungültige oder fehlende
Verbindungswerte sowie Verbindungsfehler werden als Fehler gemeldet und nicht
durch eine Ersatzkonfiguration verdeckt. Die Verbindung verwendet `utf8mb4`,
Exceptions bei Datenbankfehlern und native vorbereitete Anweisungen.

## Lokaler Start

Der aktuelle Stand enthält einen einfachen Anmeldeablauf. Zum Start werden eine
MariaDB-Datenbank mit dem Schema aus `database/schema.sql` und mindestens ein
aktiver Benutzer in `app_users` benötigt. Benutzerpasswörter müssen mit
PHP-`password_hash()` gehasht sein; Klartextpasswörter dürfen nicht in der
Datenbank gespeichert werden.

Das Schema enthält zunächst keine Katalogdaten. Für die Schülererfassung muss
mindestens die Staatsangehörigkeit „Deutschland“ vorhanden sein. Die minimale,
wiederholt ausführbare Entwicklungsbefüllung kann in der MariaDB-Konsole mit
dem Anwendungsbenutzer eingespielt werden:

```sql
USE schulverwaltung_dev;
SOURCE C:/dev/schulverwaltung-php/schulverwaltung-php/php/database/seed.sql;
```

Für den lokalen Start aus dem Repository-Hauptverzeichnis:

```powershell
.\php\start-server.ps1
```

Das Skript setzt die lokale Datenbankkonfiguration, fragt das MariaDB-Passwort
verdeckt ab, prüft `pdo_mysql` und startet PHP auf `127.0.0.1:8000`. Das
Terminal geöffnet lassen; mit `Strg+C` wird der Server beendet und die
Passwort-Umgebungsvariable wieder entfernt. Anschließend ist die Anmeldung unter
`http://127.0.0.1:8000/` erreichbar.
Die Entwicklungsumgebung darf nicht öffentlich erreichbar sein. Im Produktivbetrieb
muss HTTPS verwendet werden. Die Anmeldung prüft aktive Benutzer bei jedem
geschützten Aufruf erneut; Formulare sind mit CSRF-Tokens geschützt und
Session-Cookies mit `HttpOnly` und `SameSite=Lax` konfiguriert.

### Testbenutzer anlegen

Das Kommandozeilenwerkzeug legt einen aktiven Benutzer an und speichert nur einen
von PHP erzeugten Passwort-Hash. Unter PowerShell das Passwort verdeckt abfragen
und dem PHP-Prozess nur vorübergehend als Umgebungsvariable übergeben:

```powershell
$secure = Read-Host 'Passwort für den neuen Benutzer' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:APP_USER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    php php/bin/create-user.php testadmin
} finally {
    Remove-Item Env:APP_USER_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}
```

Das Passwort muss mindestens 12 Zeichen lang sein; der angegebene Benutzername
muss noch nicht existieren. Das Werkzeug vergibt keine Superuser- oder
Exportrechte.

### Benutzer verwalten

Die **Benutzerverwaltung** ist nur für aktive Administratoren erreichbar. Beim
ersten Einrichten muss ein Administrator einmalig über die MariaDB-Konsole
festgelegt werden. Verwende dafür ein bereits angelegtes Konto:

```sql
UPDATE app_users
SET is_superuser = 1
WHERE username = 'DEIN_BENUTZERNAME';
```

Danach ab- und wieder anmelden. Der Link **Benutzerverwaltung** erscheint auf
der Startseite. Dort können Konten angelegt, bearbeitet, aktiviert,
deaktiviert und gelöscht sowie Administrator- und Exportrechte vergeben oder
entzogen werden. Bei der Bearbeitung bleibt das Passwort unverändert, wenn das
Passwortfeld leer gelassen wird; ein neu gesetztes Passwort muss mindestens 12
Zeichen und darf höchstens 72 Bytes umfassen. Aus Sicherheitsgründen kann ein Administrator weder das
eigene Konto deaktivieren noch sich selbst die Administratorrechte entziehen
oder löschen. Der letzte aktive Administrator kann ebenfalls nicht deaktiviert,
herabgestuft oder gelöscht werden. Beim Löschen eines Benutzerkontos bleiben
Gesprächsprotokolle und Exportprotokolle erhalten; die jeweilige
Benutzerzuordnung wird entfernt.

Nach der Anmeldung führen separate Links zu den geschützten, durchsuchbaren und
paginierte Übersichten für Schülerinnen und Schüler, Aufnahmegespräche sowie
Erziehungsberechtigte. Suchbegriffe werden
parametrisiert an die Datenbank übergeben und alle angezeigten Daten HTML-escaped.
Über **Schülerin oder Schüler anlegen** lassen sich neue Datensätze mit
serverseitiger Prüfung, Katalogauswahl und CSRF-Schutz erfassen. Vorhandene
Datensätze lassen sich aus der Übersicht heraus bearbeiten; dieselben
Validierungen gelten beim Speichern. Ein Klick auf den Schülernamen öffnet die
Detailansicht mit Stammdaten und Kontaktpersonen; Erziehungsberechtigte können
dort hinzugefügt und bearbeitet werden. Optionale Übernahmefelder synchronisieren
Adresse, Religion oder Staatsangehörigkeit aus dem Schülerdatensatz.
Aufnahmegespräche lassen sich ebenfalls in der Schülerakte erfassen und
bearbeiten sowie über die zentrale Gesprächsliste suchen und bearbeiten.
Marketingkanäle können mehreren Gesprächen zugeordnet werden.

### Daten exportieren

Angemeldete Benutzer mit dem Recht `can_export` können unter **Daten exportieren**
alle Schülerinnen und Schüler, Erziehungsberechtigten oder Gespräche jeweils als
separate CSV-Datei exportieren. Der Export enthält alle Datensätze des gewählten
Bereichs, unabhängig von Suchfiltern der Listen. Die Dateien sind UTF-8-kodiert,
verwenden Semikolons als Trennzeichen und können in Tabellenkalkulationen
geöffnet werden. Textwerte, die als Tabellenkalkulationsformeln interpretiert
werden könnten, werden beim Export neutralisiert. Jeder Export wird mit Benutzer,
Bereich, Datensatzanzahl, Format und Zeitpunkt in `export_protokolle` protokolliert.

Exportrechte sind standardmäßig deaktiviert und können in der Benutzerverwaltung
gezielt erteilt oder entzogen werden. Exportdateien enthalten personenbezogene
und teils sensible Angaben und müssen entsprechend geschützt gespeichert und
weitergegeben werden.

### Auswahllisten pflegen

Unter **Auswahllisten pflegen** können Staatsangehörigkeiten, Religionen,
Grundschulen und Marketingkanäle jeweils auf einer eigenen Seite angesehen,
hinzugefügt und bearbeitet werden. Die Einträge lassen sich zusätzlich direkt aus den Schüler- und Erziehungsberechtigtenformularen
sowie die Marketingkanäle aus dem Gesprächsformular ergänzen. Neue Einträge werden
sofort in der zugehörigen Auswahl übernommen. Katalogeinträge können derzeit
nicht gelöscht werden, damit bestehende Zuordnungen erhalten bleiben.

Die PHP-Verbindung und Anmeldung verwenden `app_users`. Das Django-Passwortformat
ist nicht mit PHPs `password_verify()` kompatibel; Benutzerkonten und Passwörter
müssen beim späteren Umstieg daher kontrolliert neu angelegt oder migriert werden.
