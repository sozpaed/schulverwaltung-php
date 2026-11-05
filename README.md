# PHP-Neuimplementierung

Das Repository-Hauptverzeichnis enthält die aktive, frameworkfreie
PHP-/MariaDB-Anwendung. Der frühere Django-Bestand liegt archiviert in
[`legacy/`](./legacy/) und wird nicht mehr als aktive Anwendung weiterentwickelt.

## Datenbankschema

`database/schema.sql` legt das MariaDB-Schema für Benutzer, Schüler,
Erziehungsberechtigte, Gespräche, Auswahlkataloge, Gesprächs-Marketingkanäle
und Exportprotokolle an. Die Beziehungen und wesentlichen Wertebereiche
orientieren sich am archivierten Django-Modell in
`legacy/gespraeche/models.py`.

Das Schema enthält keine Test- oder Echtdaten und sollte nur in einer eigens
angelegten Entwicklungsdatenbank ausgeführt werden.

## Voraussetzungen für den späteren Betrieb

- PHP mit `PDO`, `pdo_mysql` und `openssl`
- MariaDB mit InnoDB-Unterstützung
- HTTPS im Produktivbetrieb

Es werden vorerst weder Laravel noch Composer-Pakete vorausgesetzt.

## Gestaltung

Alle PHP-Seiten verwenden das gemeinsame Stylesheet `public/assets/app.css`.
Das responsive Layout verwendet die Hauptfarbe `#e31c79` mit grauen
Navigationsflächen, vereinheitlicht Formulare, Tabellen und Navigation und
berücksichtigt Tastaturfokus sowie reduzierte Animationen. In den
Schülerformularen, Kontaktformularen und Gesprächsformularen sind kurze
zusammengehörige Eingaben auf breiten Bildschirmen kompakt gruppiert; auf kleinen
Bildschirmen wechseln diese Gruppen in eine einzelne Spalte. Die Unterseiten
bieten außerdem ein einheitliches Home-Symbol zur Startseite. Der angemeldete
Benutzer wird als aufklappbares Profilmenü angezeigt. Es bietet Zugang zu den
Kontoeinstellungen und zur Abmeldung; auf der Startseite sind die drei
Hauptbereiche von den darunter angeordneten sekundären Funktionen getrennt.
Bearbeitungslinks in Übersichten und Detailseiten werden einheitlich als
zugängliche Stiftsymbole dargestellt.

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
SOURCE C:/dev/schulverwaltung-php/schulverwaltung-php/database/seed.sql;
```

Für den lokalen Start aus dem Repository-Hauptverzeichnis:

```powershell
.\start-server.ps1
```

Das Skript setzt die lokale Datenbankkonfiguration, fragt das MariaDB-Passwort
verdeckt ab, prüft `pdo_mysql` und `openssl` und startet PHP auf `127.0.0.1:8000`.
Das Terminal geöffnet lassen; mit `Strg+C` wird der Server beendet und die
Passwort-Umgebungsvariable wieder entfernt. Anschließend ist die Anmeldung unter
`http://127.0.0.1:8000/` erreichbar.
Die Entwicklungsumgebung darf nicht öffentlich erreichbar sein. Im Produktivbetrieb
muss HTTPS verwendet werden. Die Anmeldung prüft aktive Benutzer bei jedem
geschützten Aufruf erneut; Formulare sind mit CSRF-Tokens geschützt und
Session-Cookies mit `HttpOnly` und `SameSite=Lax` konfiguriert.

### Kontoeinstellungen und Zwei-Faktor-Anmeldung

Über das Profilmenü können Benutzer ihr Passwort ändern und optional eine
zeitbasierte Zwei-Faktor-Anmeldung (TOTP) einrichten. Für die Einrichtung wird
der angezeigte Base32-Schlüssel manuell in eine Authenticator-App eingetragen
und anschließend mit einem aktuellen App-Code bestätigt; ein QR-Code oder
zusätzliches PHP-Paket ist nicht erforderlich. Zum Ändern des Passworts ist das
bisherige Passwort nötig. Das neue Passwort muss mindestens 12 Zeichen lang
sein und darf höchstens 72 Bytes umfassen. Zum Deaktivieren von 2FA werden
Passwort und ein aktueller Authenticator-Code verlangt.

Bei einer bestehenden Datenbank muss die Migration
`database/migrations/20261105_add_totp.sql` einmalig mit MariaDB ausgeführt
werden, bevor die neuen Einstellungen verwendet werden. Neue Datenbanken
erhalten die Spalten über `database/schema.sql`.

Für bestehende Datenbanken muss außerdem
`database/migrations/20261105_add_schueler_durchschnitt.sql` einmalig ausgeführt
werden. Der Notendurchschnitt ist eine gespeicherte MariaDB-Berechnung aus den
vorhandenen Fachnoten Deutsch, Mathematik und Sachkunde; leere Noten werden
ausgelassen. Arbeits- und Sozialverhalten zählen nicht in den Durchschnitt.

Der TOTP-Schlüssel wird verschlüsselt in der Datenbank gespeichert. Der
zufällige AES-Schlüssel liegt in `storage/totp.key`, außerhalb des Webroots;
dieses Verzeichnis wird durch `.gitignore` von Git ausgeschlossen. Die Datei
muss restriktive Dateiberechtigungen haben und in einer geschützten Sicherung
aufbewahrt werden. Geht sie verloren, können eingerichtete TOTP-Schlüssel nicht
mehr entschlüsselt werden. Wird sie kompromittiert, sollten Benutzer 2FA
zurücksetzen und neu einrichten.

### Testbenutzer anlegen

Das Kommandozeilenwerkzeug legt einen aktiven Benutzer an und speichert nur einen
von PHP erzeugten Passwort-Hash. Unter PowerShell das Passwort verdeckt abfragen
und dem PHP-Prozess nur vorübergehend als Umgebungsvariable übergeben:

```powershell
$secure = Read-Host 'Passwort für den neuen Benutzer' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:APP_USER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    php bin/create-user.php testadmin
} finally {
    Remove-Item Env:APP_USER_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}
```

Das Passwort muss mindestens 12 Zeichen lang sein; der angegebene Benutzername
muss noch nicht existieren. Das Werkzeug vergibt keine Superuser- oder
Exportrechte.

### Ersten Administrator einrichten

Nachdem die Entwicklungsdatenbank und das Schema aus `database/schema.sql`
bereits eingerichtet wurden, kann das erste Administratorkonto aus dem
Repository-Hauptverzeichnis erstellt oder ein bestehendes aktives Konto zum
Administrator hochgestuft werden:

```powershell
.\setup-admin.ps1
```

Das Skript fragt das MariaDB-Passwort verdeckt ab und bietet zwei Möglichkeiten:

1. Ein neues Administratorkonto anlegen. Das Passwort wird zweimal verdeckt
   eingegeben und mit `password_hash()` gespeichert.
2. Ein bestehendes aktives Konto zum Administrator machen. Das Passwort bleibt
   unverändert.

In beiden Fällen werden `is_superuser` und `can_export` gesetzt. Datenbank- und
Administratorkennwörter werden nur vorübergehend an den PHP-Prozess übergeben
und nach dem Setup aus den Umgebungsvariablen entfernt. Das Skript führt das
Schema nicht aus und erstellt weder Datenbank noch MariaDB-Benutzer; diese
Schritte müssen zuvor separat erledigt sein. Es darf nur von einer
vertrauenswürdigen Person in der lokalen Entwicklungsumgebung ausgeführt werden.

### Benutzer verwalten

Die **Benutzerverwaltung** ist nur für aktive Administratoren erreichbar.
Nach dem ersten Administrator-Setup ab- und wieder anmelden. Der Link
**Benutzerverwaltung** erscheint auf der Startseite. Dort können Konten angelegt, bearbeitet, aktiviert,
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
Der Schülerexport enthält zusätzlich die zugeordneten Namen der Erziehungsberechtigten
in einer kommagetrennten Spalte. Der Export der Erziehungsberechtigten und der
Gespräche enthält jeweils auch den vollständigen Namen der zugehörigen Schülerin
oder des zugehörigen Schülers; im Gesprächsexport stehen außerdem die Namen der
zugeordneten Erziehungsberechtigten kommagetrennt in einer Spalte.

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

Die PHP-Verbindung und Anmeldung verwenden `app_users`. Das archivierte Django-Passwortformat
ist nicht mit PHPs `password_verify()` kompatibel; Benutzerkonten und Passwörter
müssen beim späteren Umstieg daher kontrolliert neu angelegt oder migriert werden.
