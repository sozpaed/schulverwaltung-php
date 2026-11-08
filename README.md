# PHP-Neuimplementierung

Das Repository-Hauptverzeichnis enthält die aktive, frameworkfreie
PHP-Anwendung mit MariaDB- oder MySQL-Datenbank.

## Lizenz

Der eigene Projektcode steht unter der **GNU Affero General Public License,
Version 3.0 (AGPL-3.0-only)**. Der vollständige Lizenztext befindet sich in
[LICENSE](LICENSE). Kommerzielle Nutzung ist im Rahmen dieser Lizenz erlaubt.

Mitgelieferte Drittanbieterbestandteile behalten ihre jeweiligen Lizenzen und
Lizenzhinweise:

- FPDF: [src/vendor/fpdf/LICENSE.txt](src/vendor/fpdf/LICENSE.txt)
- qrcodejs: [public/assets/vendor/qrcodejs/LICENSE](public/assets/vendor/qrcodejs/LICENSE)

## Datenbankschema

`database/schema.sql` legt das Datenbankschema für Benutzer, Schülerinnen,
Erziehungsberechtigte, Gespräche, Auswahlkataloge, Gesprächs-Marketingkanäle
und Exportprotokolle an.

Das Schema enthält keine Test- oder Echtdaten und sollte nur in einer eigens
angelegten Entwicklungsdatenbank ausgeführt werden.

## Voraussetzungen für den späteren Betrieb

- PHP mit `PDO`, `pdo_mysql` und `openssl`
- MariaDB ab 10.5 oder MySQL 8.4 (einschließlich Percona Server for MySQL 8.4)
  mit InnoDB-Unterstützung
- HTTPS im Produktivbetrieb

Es werden vorerst weder Laravel noch Composer-Pakete vorausgesetzt.

## Gestaltung

Alle PHP-Seiten verwenden das gemeinsame Stylesheet `public/assets/app.css`.
Im Seitenkopf steht der in der Administration hinterlegte Schulname vor
„Schulverwaltung“. Solange kein Schulname hinterlegt ist, wird nur
„Schulverwaltung“ angezeigt.
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

`src/Database.php` stellt eine PDO-Verbindung zur Datenbank her. Die Verbindung
liest ihre Einstellungen zuerst aus Umgebungsvariablen des PHP-Prozesses und
verwendet andernfalls die nicht versionierte `config.php` im Projekt-Hauptverzeichnis:

| Variable | Erforderlich | Beschreibung |
|---|---|---|
| `DB_HOST` | Ja | Hostname oder IP-Adresse des Datenbankservers |
| `DB_PORT` | Nein | Port des Servers, standardmäßig `3306` |
| `DB_NAME` | Ja | Datenbankname; Buchstaben, Ziffern, Unterstriche und Bindestriche sind erlaubt |
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
> sein; `setup.php` legt keine Benutzer an. Ob ein Hoster-Benutzer diese Rechte
> besitzt und auf die betreffende Datenbank beschränkt ist, muss geprüft werden.
>
> Wer einen eigenen Server betreibt, richtet nach der Installation (und für
> Migrationen mit einem separaten Administratorkonto) besser einen zweiten
> Benutzer nur mit `SELECT`, `INSERT`, `UPDATE`, `DELETE` ein und trägt diesen
> in `config.php` ein.

`config.example.php` ist nur eine Vorlage für eine manuelle Konfiguration, etwa
für die lokale Entwicklung. Wenn du sie nach `config.php` kopierst, wird der
Installationsassistent übersprungen; die Datenbank und das Schema müssen dann
selbst eingerichtet werden. Der Assistent prüft Voraussetzungen,
Datenbankverbindung und Serverversion, richtet das Schema ein und legt den
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
| `src/`, `database/`, `bin/` | `/www/de.meine-domain.verwaltung/` |
| `config.php` (vom Installer erzeugt) | `/www/de.meine-domain.verwaltung/` |
| `storage/` (TOTP-Schlüssel, wird automatisch angelegt) | `/www/de.meine-domain.verwaltung/storage/` |

Hinweise:

- Den Ordner `public` nicht als Ganzes hochladen, sondern nur seinen Inhalt
  nach `web/`. Sonst entsteht `web/public/` und die Pfade stimmen nicht.
- Nicht hochladen: `.git` und eine lokale `config.php`.
- Das Projektverzeichnis (hier `/www/de.meine-domain.verwaltung/`)
  muss für PHP beschreibbar sein, damit `setup.php` `config.php` anlegen kann.
- Nach der Installation `setup.php` aus `web/` löschen.
- Die Domain muss per HTTPS erreichbar sein; benötigt werden PHP 8.1 oder neuer
  sowie ein unterstützter MariaDB- oder MySQL-Server.

## Datensicherung

Vor dem Produktivbetrieb muss mit der IT-Firma ein Sicherungs- und
Wiederherstellungskonzept vereinbart werden. Hoster-Backups, ihre Häufigkeit,
Aufbewahrung und Wiederherstellungsmöglichkeiten sind beim Hoster zu prüfen.
Eine Sicherung auf demselben Hosting-Account schützt nicht gegen dessen Ausfall
oder Kompromittierung. Verschlüsselte Kopien müssen zusätzlich an einem getrennten
Ort aufbewahrt und ihre Wiederherstellung regelmäßig getestet werden.

### Verschlüsselte Datenbanksicherung

`bin/backup.php` ist ausschließlich über die Kommandozeile ausführbar. Das Skript
liest dieselbe Datenbankkonfiguration wie die Anwendung, überträgt den
Datenbank-Dump unmittelbar an GnuPG und legt nur die verschlüsselte SQL-Datei im
Verzeichnis `backup/` neben `public/` ab. Dort erzeugt es kurzzeitig auch eine
Datei mit den Datenbankzugangsdaten und entfernt sie anschließend. Bei einem
abgebrochenen Prozess können temporäre Dateien zurückbleiben; diese müssen
geschützt und nach Prüfung entfernt werden. Das Backup-Verzeichnis wird von Git
ignoriert und muss außerhalb **jedes** öffentlichen Webverzeichnisses liegen.
Unter Unix darf es nur für seinen Eigentümer zugänglich sein (`chmod 700`);
unter Windows müssen passende Zugriffsrechte separat gesetzt werden.

Voraussetzungen, die insbesondere beim Hoster vorab zu prüfen sind:

- PHP-CLI mit `pdo_mysql` und aktivierter Funktion `proc_open`, GnuPG und dem
  zum Server passenden Dump-Programm (`mariadb-dump` für MariaDB, `mysqldump`
  für MySQL 8.4);
  gegebenenfalls Cron oder eine andere Aufgabenplanung. Auf Windows können die
  ausführbaren Programme über die unten genannten Variablen angegeben werden.
- Der Datenbankbenutzer benötigt Leserechte für die zu sichernden Daten und
  gegebenenfalls Rechte wie `SHOW VIEW` und `TRIGGER`. Ein Test muss bestätigen,
  dass alle benötigten Objekte gesichert werden.
- Ein GnuPG-Schlüsselpaar: Nur der **öffentliche** Schlüssel wird im Schlüsselbund
  des ausführenden Betriebssystemkontos importiert. Den vollständigen
  Fingerabdruck aus einer vertrauenswürdigen Quelle prüfen. Den privaten Schlüssel
  und dessen Passphrase getrennt und sicher aufbewahren; ohne ihn ist eine
  Wiederherstellung nicht möglich. Das Skript vertraut dem angegebenen Schlüssel
  für die Verschlüsselung ausdrücklich.

Einmaliger Test unter Linux, mit den eigenen Pfaden und dem geprüften vollständigen
Fingerabdruck statt des Platzhalters:

```sh
BACKUP_GPG_FINGERPRINT=VOLLSTAENDIGER_FINGERABDRUCK /usr/bin/php /pfad/zur/anwendung/bin/backup.php
```

Optional wählen `BACKUP_DIR` ein anderes geschütztes Verzeichnis,
`BACKUP_DUMP_BIN` und `BACKUP_GPG_BIN` die ausführbaren Programme und `GNUPGHOME`
den GnuPG-Schlüsselbund. Alle Pfade und Rechte müssen zur Betriebsumgebung
passen; Umgebungsvariablen `DB_*` überschreiben wie bei der Anwendung die
`config.php`. Der Servertyp wird über die Datenbankverbindung ermittelt. Auf
MySQL wird `--set-gtid-purged=OFF` verwendet; das Backup ist für eine
Wiederherstellung der Anwendungsdatenbank vorgesehen, nicht zur Replikation.

Das Skript richtet keinen Zeitplan ein. Beispiel für einen täglich um 02:30 Uhr
ausgeführten Cron-Eintrag, nachdem der einmalige Test funktioniert hat:

```cron
30 2 * * * BACKUP_GPG_FINGERPRINT=VOLLSTAENDIGER_FINGERABDRUCK /usr/bin/php /pfad/zur/anwendung/bin/backup.php
```

Den Schlüssel im Schlüsselbund des Cron-Benutzers bereitstellen und dessen
Arbeitsumgebung, Ausgabe und Fehler überwachen. Das Skript liefert bei Fehlern
einen Exit-Code ungleich null. Nach einem erfolgreichen Backup löscht es
ausschließlich eigene Sicherungsdateien, die älter als 30 Tage sind. Schlägt die Erstellung oder Verschlüsselung fehl, werden
keine alten Sicherungen gelöscht. Falls das Löschen selbst fehlschlägt, meldet
das Skript einen Fehler; gegebenenfalls wurden bereits einzelne alte Dateien
entfernt.

Der Dump verwendet `--single-transaction` für einen konsistenten Stand von
InnoDB-Tabellen; während der Sicherung dürfen keine Schemaänderungen oder
Migrationen ausgeführt werden. Nichttransaktionale Tabellen und künftig
hinzugefügte Datenbankobjekte müssen gesondert beurteilt werden.

### Wiederherstellung und weitere Dateien

Die Datenbanksicherung umfasst **nicht** `config.php`, `storage/totp.key` oder
andere Dateien. Konfiguration und insbesondere den TOTP-Schlüssel separat
verschlüsselt und zugriffsgeschützt sichern. Ohne den passenden Schlüssel können
gespeicherte Zwei-Faktor-Geheimnisse nicht mehr entschlüsselt werden. Ein
Datenbank-Backup und der zugehörige TOTP-Schlüssel müssen zusammen passen.

Die Wiederherstellung zunächst in einer **separaten Testdatenbank** durchführen:
mit dem privaten GnuPG-Schlüssel die Sicherung entschlüsseln und den SQL-Dump mit
dem passenden Datenbank-Client importieren;
außerdem die gesicherten Dateien in einer isolierten Testumgebung wiederherstellen.
Beispiel mit Bash unter Linux (Pfade und Datenbanknamen anpassen):

```bash
set -o pipefail
gpg --decrypt /pfad/zur/sicherung.sql.gpg | \
  mysql --defaults-extra-file=/sicherer/pfad/test-client.cnf testdatenbank
```

Für MariaDB kann stattdessen `mariadb` verwendet werden. Die Testdatenbank
muss vorher angelegt und leer sein; für den Import sind
entsprechende Rechte erforderlich. `test-client.cnf` enthält die Zugangsdaten im
Abschnitt `[client]` und muss vor fremdem Zugriff geschützt sein. Der private
GnuPG-Schlüssel muss dem ausführenden Konto zugänglich sein. Die Pipe kann bei
Fehlern einen teilweisen Import hinterlassen; Exit-Status und Ausgabe prüfen und
die Testdatenbank gegebenenfalls neu anlegen. Zugangsdaten nicht in Befehlszeilen
schreiben. Die IT-Firma sollte das konkrete Verfahren dokumentieren und
regelmäßig prüfen, ob Anmeldung, Daten und Zwei-Faktor-Anmeldung funktionieren.
Eine Wiederherstellung in die Produktivdatenbank darf nur geplant und nach
vorheriger Sicherung erfolgen.

## Lokaler Start

Der aktuelle Stand enthält einen einfachen Anmeldeablauf. Zum Start werden eine
Datenbank mit dem Schema aus `database/schema.sql` und mindestens ein aktiver
Benutzer in `app_users` benötigt. Benutzerpasswörter müssen mit
PHP-`password_hash()` gehasht sein; Klartextpasswörter dürfen nicht in der
Datenbank gespeichert werden.

Das Schema enthält zunächst keine Katalogdaten. Die minimale, wiederholt
ausführbare Entwicklungsbefüllung ergänzt die Staatsangehörigkeit „Deutschland“
und kann in der MariaDB-Konsole mit dem Anwendungsbenutzer eingespielt werden:

```sql
USE schulverwaltung_dev;
SOURCE C:/dev/schulverwaltung-php/schulverwaltung-php/database/seed.sql;
```

Bei einer frischen Hosting-Installation wird eine eigens im Hosting-Panel
angelegte, leere Datenbank in `public/setup.php` konfiguriert. Der
Assistent verlangt PHP 8.1 oder neuer sowie MariaDB 10.5 oder neuer oder MySQL
8.4. Er verändert
oder löscht keine vorhandenen Tabellen und setzt deshalb eine leere Datenbank
voraus. Nach erfolgreicher Einrichtung antwortet
`setup.php` mit Status 404 und sperrt sich über die vorhandene
`config.php`; ändere anschließend die Schreibrechte der Konfigurationsdatei nach
Möglichkeit auf nur lesbar für den PHP-Prozess. Die Zugangsdaten werden bei der
Übertragung ausschließlich über HTTPS geschützt, daher muss HTTPS vor der
Installation aktiv sein.

Für die lokale Entwicklung müssen PHP mit `pdo_mysql` und `openssl` sowie eine
passende Datenbankkonfiguration verfügbar sein. Der PHP-Entwicklungsserver kann
aus dem Repository-Hauptverzeichnis gestartet werden:

```powershell
php -S 127.0.0.1:8000 -t public
```

Anschließend ist die Anwendung unter `http://127.0.0.1:8000/` erreichbar. Bei
einer frischen Einrichtung kann dort der Installationsassistent aufgerufen
werden. Das Terminal geöffnet lassen; mit `Strg+C` wird der Server beendet.
Die Entwicklungsumgebung darf nicht öffentlich erreichbar sein. Im Produktivbetrieb
muss HTTPS verwendet werden. Die Anmeldung prüft aktive Benutzer bei jedem
geschützten Aufruf erneut; Formulare sind mit CSRF-Tokens geschützt und
Session-Cookies mit `HttpOnly` und `SameSite=Strict` konfiguriert.

### Sicherheits-Header

Apache leitet HTTP auf HTTPS um und setzt Sicherheits-Header einschließlich
Content-Security-Policy und HSTS (ein Jahr, ohne Subdomains). Dynamische
PHP-Antworten werden nicht zwischengespeichert und setzen die Header auch ohne
`mod_headers`; statische Dateien, Apache-Fehler und Weiterleitungen benötigen
dafür `mod_headers`. Die Weiterleitung benötigt `mod_rewrite` und aktivierte
`.htaccess`-Regeln. HTTPS und ein gültiges Zertifikat müssen vor der
Bereitstellung funktionieren: Browser merken sich HSTS für ein Jahr.

Die Proxy-Ausnahme für `X-Forwarded-Proto` darf nur verwendet werden, wenn der
vorgeschaltete Hoster vom Client gesendete Werte entfernt oder überschreibt und
den Apache nicht direkt aus dem Internet erreichbar macht. Andernfalls kann
ein Client die HTTPS-Weiterleitung umgehen. Ist kein solcher vertrauenswürdiger
Proxy vorhanden, die entsprechende `RewriteCond` in `public/.htaccess` entfernen.
Auf dem Zielsystem HTTP-Weiterleitung, HTTPS und die Header nach dem Deployment
prüfen.

### Anmeldeschutz

Anmeldeversuche werden mit Benutzername, IP-Adresse, Ergebnis und Grund
protokolliert. Die letzten 200 Einträge können ausschließlich Administratoren
in der Benutzerverwaltung einsehen und nach Benutzername filtern. Nach fünf
Fehlversuchen für einen Benutzernamen oder 20 Fehlversuchen von einer IP-Adresse
innerhalb von 15 Minuten werden weitere Versuche vorübergehend abgelehnt.
Gesperrte Versuche verlängern das Zeitfenster nicht. Für die IP-Adresse wird
ausschließlich `REMOTE_ADDR` verwendet; hinter einem Proxy können daher mehrere
Personen dieselbe Grenze teilen.

Einträge werden nach 90 Tagen bei einem Prozent der erfolgreichen Anmeldungen
bereinigt. Für eine verlässliche regelmäßige Bereinigung zusätzlich das
CLI-Skript `php bin/cleanup.php` täglich per Cronjob ausführen lassen; ohne
Cronjob kann die tatsächliche Aufbewahrung bei wenigen Anmeldungen länger sein.
Das Skript verwendet dieselbe Datenbankkonfiguration wie die Anwendung.

Für eine bestehende Datenbank **vor dem Deployment des neuen Codes** eine
Sicherung erstellen und `database/migrations/20261106_login_versuche.sql`
einmalig mit einem Datenbankkonto mit CREATE- und FOREIGN-KEY-Rechten ausführen.
Die GitHub-Bereitstellung führt keine Migrationen aus. Ohne die Tabelle sind
Anmeldungen nicht möglich. Der Anwendungsbenutzer benötigt SELECT-, INSERT- und
DELETE-Rechte auf der Tabelle sowie Zugriff auf MySQL-/MariaDB-Benutzersperren
(`GET_LOCK`, `RELEASE_LOCK`). Bei Installationen mit vorgeschaltetem Proxy die
angezeigten IP-Adressen und die gemeinsame Sperrwirkung prüfen.

### Sitzungen

Sitzungen laufen nach 30 Minuten Inaktivität oder spätestens acht Stunden nach
der Anmeldung ab. Die Sitzungs-ID wird nach 15 Minuten bei der nächsten Anfrage
erneuert. Passwort- und 2FA-Änderungen sowie Änderungen an Status oder Rechten
beenden andere Sitzungen beim nächsten Aufruf. Die eigene Sitzung bleibt bei
Änderungen in den Kontoeinstellungen angemeldet. Ein administrativer 2FA-Reset
beendet alle Sitzungen der betroffenen Person.

Für den Produktivbetrieb wird ein auf HTTPS beschränktes
`__Host-sv_session`-Cookie mit `Secure`, `HttpOnly`, `SameSite=Strict` und
Pfad `/` verwendet. Nach der Umstellung müssen sich Benutzer erneut anmelden.
Nur für die lokale Entwicklung ohne HTTPS kann in der lokalen `config.php`
zusätzlich `'DEV_INSECURE_COOKIES' => true` gesetzt werden. Dann heißt das
Cookie `sv_dev_session` und ist nicht transportgeschützt. Diese Einstellung
niemals auf einem öffentlich erreichbaren Server verwenden.
Der Installationsassistent verwendet vor Abschluss der Einrichtung eine eigene
Sitzung und ist deshalb von diesen Einstellungen getrennt.

Vor Bereitstellung des neuen Codes eine Datenbanksicherung erstellen und
`database/migrations/20261106_session_version.sql` einmalig ausführen. GitHub
führt Migrationen nicht aus; ohne die Spalte funktioniert die Anmeldung nicht.

### Kontoeinstellungen und Zwei-Faktor-Anmeldung

Über das Profilmenü können Benutzer ihr Passwort ändern und optional eine
zeitbasierte Zwei-Faktor-Anmeldung (TOTP) einrichten. Für die Einrichtung wird
der angezeigte QR-Code lokal im Browser erzeugt oder der Base32-Schlüssel manuell
in eine Authenticator-App eingetragen und anschließend mit einem aktuellen
App-Code bestätigt. Die mitgelieferte QR-Code-Bibliothek läuft lokal; der
Schlüssel wird nicht an einen externen Dienst gesendet. Zum Ändern des Passworts ist das
bisherige Passwort nötig. Das neue Passwort muss mindestens 12 Zeichen lang
sein und darf höchstens 72 Bytes umfassen. Zum Deaktivieren von 2FA werden
Passwort und ein aktueller Authenticator-Code verlangt.

Nach der Aktivierung werden zehn Wiederherstellungscodes einmalig angezeigt.
Diese sicher und getrennt vom Authenticator-Gerät aufbewahren. Jeder Code kann
anstelle des App-Codes einmal zur Anmeldung verwendet werden. Bereits bestehende
2FA-Konten können in den Kontoeinstellungen mit Passwort und aktuellem
Authenticator-Code neue Codes erzeugen; dabei werden alle alten Codes ungültig.
Der Authenticator kann dort gewechselt werden. Der bisherige Schlüssel bleibt
bis zur erfolgreichen Bestätigung des neuen Schlüssels gültig.

Administratoren können 2FA für andere Benutzer nach erneuter Bestätigung ihres
Passworts und, falls aktiviert, ihres Authenticator-Codes zurücksetzen.
Vorher ist die Identität der betroffenen Person unabhängig zu prüfen. Nach dem
Reset ist 2FA deaktiviert und eine Anmeldung nur mit Passwort möglich; alle
Wiederherstellungscodes und Sitzungen des Kontos werden ungültig. Der Reset
wird in `admin_protokoll` gespeichert und ist in der Benutzerverwaltung unter
**2FA-Rücksetzungen anzeigen** einsehbar. Diese Protokolle werden nicht automatisch
gelöscht; die Aufbewahrung muss organisatorisch festgelegt werden.
Im Notfall kann eine berechtigte Person über SSH
`php bin/reset-2fa.php BENUTZERNAME` ausführen und die Rücksetzung mit `JA`
bestätigen. Der CLI-Zugriff ist entsprechend zu beschränken.

Für bestehende Datenbanken vor Bereitstellung des Codes eine Sicherung erstellen
und `database/migrations/20261106_2fa_wiederherstellung.sql` einmalig ausführen.
Ohne diese Tabellen funktionieren die Wiederherstellungscodes und 2FA-Änderungen
nicht. GitHub führt die Migration nicht aus.

Der QR-Code wird mit der lokal mitgelieferten JavaScript-Bibliothek qrcodejs
erzeugt (MIT-Lizenz in `public/assets/vendor/qrcodejs/LICENSE`, Stand
`04f46c6a0708418cb7b96fc563eacae0fbf77674`). JavaScript muss dafür aktiviert
sein; die manuelle Schlüsseleingabe bleibt als Alternative verfügbar.

Bei einer bestehenden Datenbank muss die Migration
`database/migrations/20261105_add_totp.sql` einmalig mit MariaDB ausgeführt
werden, bevor die neuen Einstellungen verwendet werden. Neue Datenbanken
erhalten die Spalten über `database/schema.sql`.

Für bestehende Datenbanken muss außerdem
`database/migrations/20261105_add_schueler_durchschnitt.sql` einmalig ausgeführt
werden. Der Notendurchschnitt ist eine gespeicherte Datenbankberechnung aus den
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
Repository-Hauptverzeichnis mit dem PHP-Kommandozeilenwerkzeug erstellt oder
ein bestehendes aktives Konto zum Administrator hochgestuft werden. Die
Datenbankverbindung muss zuvor über `config.php` oder Umgebungsvariablen
konfiguriert sein.

Für ein neues Konto das Passwort verdeckt abfragen und nur vorübergehend als
Umgebungsvariable übergeben:

```powershell
$secure = Read-Host 'Passwort für das neue Administratorkonto' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
try {
    $env:APP_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    php bin/setup-admin.php create admin
} finally {
    Remove-Item Env:APP_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    $secure.Dispose()
}
```

Der Benutzername `admin` ist ein Beispiel und muss noch nicht existieren. Das
Passwort muss mindestens 12 Zeichen lang sein und darf höchstens 72 Bytes
umfassen. Um stattdessen ein bestehendes aktives Konto hochzustufen:

```powershell
php bin/setup-admin.php promote BENUTZERNAME
```

In beiden Fällen werden `is_superuser` und `can_export` gesetzt. Beim
Hochstufen bleibt das Passwort unverändert; beim Anlegen wird es mit
`password_hash()` gespeichert. Das Werkzeug führt das Schema nicht aus und
erstellt weder Datenbank noch Datenbankbenutzer; diese Schritte müssen zuvor
separat erledigt sein. Es darf nur von einer vertrauenswürdigen Person
ausgeführt werden.

### Benutzer verwalten

Die **Benutzerverwaltung** ist nur für aktive Administratoren erreichbar.
Nach dem ersten Administrator-Setup ab- und wieder anmelden. Der Link
**Benutzerverwaltung** ist über **Administration** auf der Startseite erreichbar. Dort können Konten angelegt, bearbeitet, aktiviert,
deaktiviert und gelöscht sowie Administrator- und Exportrechte vergeben oder
entzogen werden. Zusätzlich können separate Rechte zum Löschen von
Auswahllisteneinträgen und Schülerinnen vergeben werden; beide sind zunächst
für alle Benutzer, einschließlich Administratoren, deaktiviert. Bei der Bearbeitung bleibt das Passwort unverändert, wenn das
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
Validierungen gelten beim Speichern. Nur Vor- und Nachname sind Pflichtfelder.
Leere Geschwisterzahlen werden als 0 gespeichert; der Status ist standardmäßig
„In Prüfung“. Für bestehende Datenbanken muss vor dem Einsatz dieser Änderung
die Migration `database/migrations/20261106_optional_student_fields.sql` einmalig
mit entsprechenden ALTER-Rechten ausgeführt werden. Vorher ist eine Sicherung
anzulegen. Alternativ kann `bin/migrate-optional-student-fields.sh` interaktiv
über SSH ausgeführt werden. Es benötigt den MySQL-Client `mysql`, eine bereits
erstellte Sicherungsdatei und einen Benutzer mit ALTER-Rechten. Beispiel:

```sh
sh bin/migrate-optional-student-fields.sh
```

Das Skript prüft die Sicherungsdatei nur auf Lesbarkeit und Größe, nicht auf
Vollständigkeit oder Wiederherstellbarkeit. Es fragt den Zielserver und die
Datenbank ab und verlangt eine ausdrückliche Bestätigung; das Passwort fragt der
MySQL-Client separat ab. Bei einem Fehler den Datenbankzustand prüfen, bevor
ein weiterer Versuch erfolgt. Neue Datenbanken erhalten das Schema direkt über
den Installer.
Das Zahnradsymbol öffnet die
Detailansicht mit Stammdaten und Kontaktpersonen; Erziehungsberechtigte können
dort hinzugefügt und bearbeitet werden. Optionale Übernahmefelder synchronisieren
Adresse, Religion oder Staatsangehörigkeit aus dem Datensatz der Schülerin.
In der Schülerinnenakte und beim Bearbeiten führen die Pfeile zum vorherigen
oder nächsten Datensatz gemäß den zuletzt in der Schülerinnenübersicht
gewählten Filtern. Bei Kontaktpersonen umfasst die Navigation aus der
Schülerinnenakte nur deren Kontaktpersonen; aus der Erziehungsberechtigtenliste
umfasst sie die Kontaktpersonen aller zuletzt gefilterten Schülerinnen, sortiert
nach Schülerin und anschließend Kontaktperson. Passt der aktuelle Datensatz
nicht mehr zum Filter, werden keine Nachbarn angezeigt. Ungespeicherte Änderungen
gehen beim Wechsel verloren; bei aktiviertem JavaScript erfolgt eine Rückfrage.
Aufnahmegespräche lassen sich ebenfalls in der Schülerinnenakte erfassen und
bearbeiten sowie über die zentrale Gesprächsliste suchen und bearbeiten.
Marketingkanäle können mehreren Gesprächen zugeordnet werden.

Mit dem Recht „Schülerinnen löschen“ erscheint in der Übersicht ein
Mülleimer-Symbol. Das endgültige Löschen muss auf einer eigenen Seite bestätigt
werden. Solange Kontaktpersonen oder Gespräche zugeordnet sind, blockiert die
Datenbank das Löschen; zugehörige Daten werden nicht automatisch gelöscht. Die
Kontaktpersonen können mit demselben Recht in der Schülerinnenakte oder der
Kontaktpersonenübersicht nach Bestätigung gelöscht werden; die Schülerin und
ihre Gespräche bleiben dabei erhalten. Für Gespräche bietet die Anwendung
derzeit keine Löschfunktion.

Für bestehende Datenbanken vor dem Einsatz der Löschfunktion zuerst eine
Sicherung anlegen und dann
`database/migrations/20261106_student_delete_permission.sql` mit ALTER-Rechten
ausführen, bevor die neuen PHP-Dateien bereitgestellt werden. Die Migration
ändert den Fremdschlüssel der Kontaktpersonen auf `RESTRICT` und ergänzt das
standardmäßig deaktivierte Recht `can_delete_students`. Datenbankänderungen
werden unmittelbar ausgeführt; bei einem Fehler den Zustand beider Änderungen
prüfen und nicht die gesamte Migration blind wiederholen. Danach kann ein
Administrator das Recht in der Benutzerverwaltung vergeben.

### Administration der Schule

Administratoren erreichen die **Administration** über die Startseite. Dort
führen eigene Buttons zur **Benutzerverwaltung**, zum **Import** und zu
**Angaben zur Schule**. Unter **Angaben zur Schule**
lassen sich der Name der Schule, der Name der Schulleitung und optionale
Absenderangaben für Serienbriefe eintragen. Ein eigenes Logo kann als PNG
oder JPEG hochgeladen oder wieder entfernt werden (höchstens 1 MB und
2500 × 2500 Pixel). Die Einstellungen und das Logo werden in der Datenbank
gespeichert und sind Teil der Datenbanksicherung.

Bei Neuinstallationen sind die Einstellungen zunächst leer. Bei bestehenden
Installationen muss vor Verwendung dieser Version nach einer Datensicherung
`database/migrations/20261108_school_settings.sql` einmalig ausgeführt werden.
Auch dort bleiben die Einstellungen zunächst leer; bestehende Daten werden
nicht vorbelegt. Nach der Migration müssen Schulname und Schulleitung in der
Administration eingetragen werden, bevor Serienbrief-PDFs erstellt werden
können. Die übrigen Angaben und das Logo sind optional.

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
CSV-Spaltenüberschriften verwenden die weibliche Form. Die Spalte „Geschwister
an der Marienschule“ heißt nun „Geschwister an dieser Schule“; bestehende Auswertungen,
die die bisherigen Überschriften erwarten, müssen entsprechend angepasst werden.

Exportrechte sind standardmäßig deaktiviert und können in der Benutzerverwaltung
gezielt erteilt oder entzogen werden. Exportdateien enthalten personenbezogene
und teils sensible Angaben und müssen entsprechend geschützt gespeichert und
weitergegeben werden.

Die Startseite bietet außerdem die Übersicht **Wartung**. Sie zeigt
Schülerinnen und Erziehungsberechtigte mit fehlenden Angaben, etwa zu Adresse,
Geburtsdatum, Noten, Schulform, Kontaktdaten oder Klasse. Die Treffer führen in
die jeweilige Liste und bleiben dort nach dem Filter durchsuchbar. Die
Erziehungsberechtigtenliste bietet Bearbeitungs- und Löschsymbole; das
Löschsymbol erscheint nur mit dem Recht „Schülerinnen löschen“. Bei
Erziehungsberechtigten gelten übernommene Adresse, Religion und
Staatsangehörigkeit nur dann als vorhanden, wenn auch der zugehörige Wert der
Schülerin eingetragen ist. Bearbeitung und Löschung verwenden die bestehenden
Zugriffsrechte der Listen.

Auf der Startseite gibt es für Benutzer mit Exportrecht außerdem eigene
Einstiege für **Serienbriefe** und **Statistik**. Für Serienbriefe wird zuerst
die Schulform ausgewählt, anschließend der Status (In Prüfung, Angenommen oder
Abgelehnt) oder die Warteliste. **Keine** berücksichtigt nur Schülerinnen ohne
Schulform; **Beide** berücksichtigt Schülerinnen mit Förderstufe oder Gymnasium,
aber nicht diejenigen ohne Schulform. Die Briefe verwenden jeweils die
eingetragene Schulform; bei fehlender Schulform sind die Brieftexte neutral.
Alle als erziehungsberechtigt markierten Kontaktpersonen erhalten
den Brief; bei gleicher Adresse werden sie gemeinsam aufgeführt, bei
unterschiedlichen Adressen entstehen separate Briefe. Wenn keine passende
Kontaktperson vorhanden ist, wird die Adresse der Schülerin verwendet. Mütter
und Väter werden mit Frau bzw. Herr und ihrem Nachnamen angesprochen, bei einem
gemeinsamen Brief zuerst die Mutter. Andere Kontaktpersonen und Fälle ohne
passende Kontaktperson erhalten eine neutrale Anrede. Brieftexte und Empfänger
sollten vor dem Versand geprüft werden. Ein hinterlegtes Schullogo erscheint
oben rechts im PDF. Die PDF-Erstellung verwendet die lokal mitgelieferte FPDF-Bibliothek;
deren Lizenz liegt unter `src/vendor/fpdf/LICENSE.txt`. Filterung und
PDF-Erstellung benötigen keine Datenbankänderung. Auch PDF-Exporte werden in
`export_protokolle` protokolliert.

Die Statistikseite enthält Tabellen und farbige Ringdiagramme nach Status,
Klasse und Schulform. Der äußere Ring zeigt die Gesamtzahl, der mittlere Ring
die Warteliste und der innere Ring die um Wartelistenplätze bereinigte Zahl.
Die Farben sind den Kategorien in der Tabelle zugeordnet. „Bereinigt“ bedeutet
Gesamtzahl abzüglich Warteliste; bei Erziehungsberechtigten und Gesprächen wird
die Warteliste anhand der zugeordneten Schülerin ermittelt. Weitere Tabellenwerte
zeigen die Gesamtzahlen für Schülerinnen, Erziehungsberechtigte und Gespräche.
Angezeigt werden nur zusammengefasste Werte, keine Namen oder Einzeldatensätze.

### Neue Schülerinnen und Erziehungsberechtigte aus CSV importieren

Administratoren können über **Administration** die Importseite öffnen und eine
UTF-8-CSV-Datei mit Semikolon-Trennung hochladen. Die Kopfzeile muss die Felder
`E-Mail-Adresse Erziehungsberechtigter`, `Vorname Erziehungsberechtigter`,
`Nachname Erziehungsberechtigter`, `Mobil Erziehungsberechtigter`,
`Straße und Hausnummer Erziehungsberechtigter`, `PLZ Erziehungsberechtigter`,
`Wohnort Erziehungsberechtigter`, `Vorname Schülerin`, `Nachname Schülerin`
und `Beziehung` enthalten. Beziehungen sind Mutter, Vater, Vormund,
Pflegeeltern oder Sonstiges.

Vor dem Speichern wird jede Zeile geprüft. Der Import legt neue Schülerinnen
und die zugehörigen Erziehungsberechtigten an; bestehende Schülerinnen werden
nicht gesucht. Zeilen mit identischem Vor- und Nachnamen der Schülerin werden
als dieselbe neue Schülerin zusammengeführt, damit mehrere Kontaktpersonen
zugeordnet werden können. Daher lassen sich zwei verschiedene Schülerinnen
mit exakt gleichem Vor- und Nachnamen in einer Datei nicht unterscheiden.
Weitere Schülerangaben, die nicht in der CSV enthalten sind, bleiben leer oder
verwenden den Datenbank-Standardwert. Die Adresse aus der CSV wird bei der
Schülerin und bei jeder zugehörigen erziehungsberechtigten Person gespeichert;
beim Erziehungsberechtigten wird die Option „Adresse des Kindes übernehmen“
gesetzt. Enthält die CSV für denselben Namen unterschiedliche Adressen, wird
der Import verhindert. Der Import wird erst durch den ausdrücklich betätigten
Import-Button gestartet und erfolgt für alle Zeilen gemeinsam oder gar nicht.
Es werden höchstens 500 Datensätze aus einer Datei bis 2 MB verarbeitet. Eine
Datenbankänderung ist dafür nicht erforderlich.

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
