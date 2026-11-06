# PHP-Neuimplementierung

Das Repository-Hauptverzeichnis enthält die aktive, frameworkfreie
PHP-/MariaDB-Anwendung.

## Datenbankschema

`database/schema.sql` legt das MariaDB-Schema für Benutzer, Schülerinnen,
Erziehungsberechtigte, Gespräche, Auswahlkataloge, Gesprächs-Marketingkanäle
und Exportprotokolle an.

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
Schülerinnenformularen, Kontaktformularen und Gesprächsformularen sind kurze
zusammengehörige Eingaben auf breiten Bildschirmen kompakt gruppiert; auf kleinen
Bildschirmen wechseln diese Gruppen in eine einzelne Spalte. Die Unterseiten
bieten außerdem ein einheitliches Home-Symbol zur Startseite. Der angemeldete
Benutzer wird als aufklappbares Profilmenü angezeigt. Es bietet Zugang zu den
Kontoeinstellungen und zur Abmeldung; auf der Startseite sind die drei
Hauptbereiche von den darunter angeordneten sekundären Funktionen getrennt.
Bearbeitungslinks in Übersichten und Detailseiten werden einheitlich als
zugängliche Stiftsymbole dargestellt. In der Schülerinnen- und Gesprächsübersicht
führt zusätzlich ein Zahnradsymbol zu den Daten der jeweiligen Schülerin. Dort erscheinen die Links zum Erfassen
eines Gesprächs und zum Hinzufügen einer Kontaktperson als Schaltflächen.

## Technisches Grundgerüst

`src/Database.php` stellt eine PDO-Verbindung zur MariaDB her. Die Verbindung
liest ihre Einstellungen zuerst aus Umgebungsvariablen des PHP-Prozesses und
verwendet andernfalls die nicht versionierte `config.php` im Projekt-Hauptverzeichnis:

| Variable | Erforderlich | Beschreibung |
|---|---|---|
| `DB_HOST` | Ja | Hostname oder IP-Adresse des MariaDB-Servers |
| `DB_PORT` | Nein | Port des Servers, standardmäßig `3306` |
| `DB_NAME` | Ja | Name der Entwicklungsdatenbank |
| `DB_USER` | Ja | Datenbankbenutzer |
| `DB_PASSWORD` | Ja | Passwort des Datenbankbenutzers; darf leer sein |

Die Zugangsdaten gehören nicht ins Repository. Für eine frische Installation
musst du keine Konfigurationsdatei kopieren: Rufe `/setup.php` auf und gib die
Datenbank- und Administratordaten dort ein. Der Assistent legt `config.php`
automatisch im Projekt-Hauptverzeichnis an, außerhalb von `public/`. `config.php`
wird von Git ignoriert. Stelle bei Host Europe `public/` als öffentliches
Webverzeichnis ein.

> **Wichtig: Datenbankbenutzer.** Verwende für die Datenbankverbindung **niemals
> `root` oder ein anderes Administratorkonto des Datenbankservers**, sondern einen
> eigenen Benutzer, der nur Rechte auf die Schulverwaltungs-Datenbank hat.
> Der Installer braucht dafür einmalig die Rechte `CREATE`, `ALTER`, `INDEX`,
> `REFERENCES`, `SELECT`, `INSERT`, `UPDATE` und `DELETE` auf diese Datenbank. Der
> Benutzer muss vorher in der Datenbank bzw. im Kundenbereich des Hosters angelegt
> sein; `setup.php` legt keine Benutzer an. Bei Host Europe ist der dort angelegte
> Datenbankbenutzer dafür geeignet.
>
> Wer einen eigenen Server betreibt, richtet nach der Installation (und für
> Migrationen mit einem separaten Administratorkonto) besser einen zweiten
> Benutzer nur mit `SELECT`, `INSERT`, `UPDATE`, `DELETE` ein und trägt diesen
> in `config.php` ein.

`config.example.php` ist nur eine Vorlage für eine manuelle Konfiguration, etwa
für die lokale Entwicklung. Wenn du sie nach `config.php` kopierst, wird der
Installationsassistent übersprungen; die Datenbank und das Schema müssen dann
selbst eingerichtet werden. Der Assistent prüft Voraussetzungen,
Datenbankverbindung und MariaDB-Version, richtet das Schema ein und legt den
ersten Administrator an.
Ungültige oder fehlende
Verbindungswerte sowie Verbindungsfehler werden als Fehler gemeldet und nicht
durch eine Ersatzkonfiguration verdeckt. Die Verbindung verwendet `utf8mb4`,
Exceptions bei Datenbankfehlern und native vorbereitete Anweisungen.

## Beispiel: Bereitstellung bei einem Hoster (Host Europe)

Die Verzeichnisstruktur beim Hoster kann abweichen. Entscheidend ist: Nur der
Inhalt von `public/` gehört in das Webverzeichnis. Alles andere liegt eine Ebene
darüber und ist nicht per Browser erreichbar. Alle Pfade werden relativ zu
`public/` bestimmt (`dirname(__DIR__)`), daher funktioniert `setup.php` auch dort.

Beispiel für die Domain `verwaltung.meine-domain.de`, deren
Webverzeichnis `/www/de.meine-domain.verwaltung/web` ist:

| Lokal | Auf dem Server |
|---|---|
| Inhalt von `public/` (inkl. `.htaccess`, `assets/`, `setup.php`) | `/www/de.meine-domain.verwaltung/web/` |
| `src/`, `database/` | `/www/de.meine-domain.verwaltung/` |
| `config.php` (vom Installer erzeugt) | `/www/de.meine-domain.verwaltung/` |
| `storage/` (TOTP-Schlüssel, wird automatisch angelegt) | `/www/de.meine-domain.verwaltung/storage/` |

Hinweise:

- Den Ordner `public` nicht als Ganzes hochladen, sondern nur seinen Inhalt
  nach `web/`. Sonst entsteht `web/public/` und die Pfade stimmen nicht.
- Nicht hochladen: `.git`, `start-server.ps1`, eine lokale `config.php`.
- Das Projektverzeichnis (hier `/www/de.meine-domain.verwaltung/`)
  muss für PHP beschreibbar sein, damit `setup.php` `config.php` anlegen kann.
- Nach der Installation `setup.php` aus `web/` löschen.
- Die Domain muss per HTTPS erreichbar sein, PHP 8.3 und MariaDB werden benötigt.

## Lokaler Start

Der aktuelle Stand enthält einen einfachen Anmeldeablauf. Zum Start werden eine
MariaDB-Datenbank mit dem Schema aus `database/schema.sql` und mindestens ein
aktiver Benutzer in `app_users` benötigt. Benutzerpasswörter müssen mit
PHP-`password_hash()` gehasht sein; Klartextpasswörter dürfen nicht in der
Datenbank gespeichert werden.

Das Schema enthält zunächst keine Katalogdaten. Für die Erfassung einer Schülerin muss
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

Bei einer frischen Hosting-Installation wird eine eigens im Hosting-Panel
angelegte, leere MariaDB-Datenbank in `public/setup.php` konfiguriert. Der
Assistent verlangt PHP 8.1 oder neuer und MariaDB 10.5 oder neuer. Er verändert
oder löscht keine vorhandenen Tabellen und setzt deshalb eine leere Datenbank
voraus. Nach erfolgreicher Einrichtung führt
`setup.php` zurück zur Anmeldung und sperrt sich über die vorhandene
`config.php`; ändere anschließend die Schreibrechte der Konfigurationsdatei nach
Möglichkeit auf nur lesbar für den PHP-Prozess. Die Zugangsdaten werden bei der
Übertragung ausschließlich über HTTPS geschützt, daher muss HTTPS vor der
Installation aktiv sein.

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
entzogen werden. Zusätzlich kann das Recht zum Löschen von Auswahllisteneinträgen
vergeben werden; es ist zunächst für alle Benutzer, einschließlich Administratoren,
deaktiviert. Bei der Bearbeitung bleibt das Passwort unverändert, wenn das
Passwortfeld leer gelassen wird; ein neu gesetztes Passwort muss mindestens 12
Zeichen und darf höchstens 72 Bytes umfassen. Aus Sicherheitsgründen kann ein Administrator weder das
eigene Konto deaktivieren noch sich selbst die Administratorrechte entziehen
oder löschen. Der letzte aktive Administrator kann ebenfalls nicht deaktiviert,
herabgestuft oder gelöscht werden. Beim Löschen eines Benutzerkontos bleiben
Gesprächsprotokolle und Exportprotokolle erhalten; die jeweilige
Benutzerzuordnung wird entfernt.

Nach der Anmeldung führen separate Links zu den geschützten, durchsuchbaren und
paginierte Übersichten für Schülerinnen, Aufnahmegespräche sowie
Erziehungsberechtigte. Suchbegriffe werden
parametrisiert an die Datenbank übergeben und alle angezeigten Daten HTML-escaped.
Über **Schülerin anlegen** lassen sich neue Datensätze mit
serverseitiger Prüfung, Katalogauswahl und CSRF-Schutz erfassen. Vorhandene
Datensätze lassen sich aus der Übersicht heraus bearbeiten; dieselben
Validierungen gelten beim Speichern. Das Zahnradsymbol öffnet die
Detailansicht mit Stammdaten und Kontaktpersonen; Erziehungsberechtigte können
dort hinzugefügt und bearbeitet werden. Optionale Übernahmefelder synchronisieren
Adresse, Religion oder Staatsangehörigkeit aus dem Datensatz der Schülerin.
Aufnahmegespräche lassen sich ebenfalls in der Schülerinnenakte erfassen und
bearbeiten sowie über die zentrale Gesprächsliste suchen und bearbeiten.
Marketingkanäle können mehreren Gesprächen zugeordnet werden.

### Daten exportieren

Angemeldete Benutzer mit dem Recht `can_export` können unter **Daten exportieren**
alle Schülerinnen, Erziehungsberechtigten oder Gespräche jeweils als
separate CSV-Datei exportieren. Der Export enthält alle Datensätze des gewählten
Bereichs, unabhängig von Suchfiltern der Listen. Die Dateien sind UTF-8-kodiert,
verwenden Semikolons als Trennzeichen und können in Tabellenkalkulationen
geöffnet werden. Textwerte, die als Tabellenkalkulationsformeln interpretiert
werden könnten, werden beim Export neutralisiert. Jeder Export wird mit Benutzer,
Bereich, Datensatzanzahl, Format und Zeitpunkt in `export_protokolle` protokolliert.
Der Schülerinnenexport enthält zusätzlich die zugeordneten Namen der Erziehungsberechtigten
in einer kommagetrennten Spalte. Der Export der Erziehungsberechtigten und der
Gespräche enthält jeweils auch den vollständigen Namen der zugehörigen Schülerin;
im Gesprächsexport stehen außerdem die Namen der
zugeordneten Erziehungsberechtigten kommagetrennt in einer Spalte. Die
CSV-Spaltenüberschriften verwenden die weibliche Form; bestehende Auswertungen,
die die bisherigen Überschriften erwarten, müssen entsprechend angepasst werden.

Exportrechte sind standardmäßig deaktiviert und können in der Benutzerverwaltung
gezielt erteilt oder entzogen werden. Exportdateien enthalten personenbezogene
und teils sensible Angaben und müssen entsprechend geschützt gespeichert und
weitergegeben werden.

### Auswahllisten pflegen

Unter **Auswahllisten pflegen** können Staatsangehörigkeiten, Religionen,
Grundschulen und Marketingkanäle jeweils auf einer eigenen Seite angesehen,
hinzugefügt und bearbeitet werden. Die Einträge lassen sich zusätzlich direkt aus den Schülerinnen- und Erziehungsberechtigtenformularen
sowie die Marketingkanäle aus dem Gesprächsformular ergänzen. Neue Einträge werden
sofort in der zugehörigen Auswahl übernommen. Benutzer mit dem ausdrücklich
vergebenen Löschrecht können nicht verwendete Einträge über das Mülleimer-Symbol
und eine anschließende Bestätigung löschen. Einträge, die noch Schülerinnen,
Kontaktpersonen oder Gesprächen zugeordnet sind, können nicht gelöscht werden.

Für bestehende Datenbanken muss vor dem Einsatz dieser Version die Migration
`database/migrations/20261106_catalog_delete_permission.sql` einmalig ausgeführt
werden. Sie ändert Fremdschlüssel zum Schutz bestehender Zuordnungen und ergänzt
das neue Recht standardmäßig deaktiviert. Vor der Migration eine Datenbanksicherung
erstellen. MariaDB führt Schemaänderungen unmittelbar aus; bei einem Fehler muss
der bereits ausgeführte Teil vor einem erneuten Versuch geprüft werden. Bei
Neuinstallationen sind die Änderungen bereits im Schema enthalten.

Die PHP-Verbindung und Anmeldung verwenden `app_users`. Das archivierte Django-Passwortformat
ist nicht mit PHPs `password_verify()` kompatibel; Benutzerkonten und Passwörter
müssen beim späteren Umstieg daher kontrolliert neu angelegt oder migriert werden.
