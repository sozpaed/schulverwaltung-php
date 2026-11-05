# Schulverwaltung – Aufnahmegespräche

Interne Verwaltung der Aufnahmegespräche (Schüler/innen, Erziehungsberechtigte, Gespräche) auf Basis von Django und dem Django-Admin. Es gibt keine eigene Oberfläche: Listen, Formulare und Login stammen vom Django-Admin.

> **Nur interne Nutzung.** Dieses Repository enthält ausschließlich Code. Echte Schüler- oder Elterndaten, Datenbank-Dumps, Backups und Zugangsdaten gehören **nie** ins Repository.

Eine frameworkfreie PHP-/MariaDB-Neuimplementierung entsteht parallel im Ordner
[`php/`](./php/). Der Django-Bestand bleibt währenddessen erhalten. Der aktuelle
Stand umfasst Anmeldung, eine Schülerübersicht mit Suche, Neuanlage und
Bearbeitung, eine Schülerdetailansicht sowie das Erfassen und Bearbeiten von
Erziehungsberechtigten und Aufnahmegesprächen mit separaten Listenansichten.
Staatsangehörigkeiten, Religionen,
Grundschulen und Marketingkanäle können in eigenen Ansichten gepflegt und direkt
aus den zugehörigen Eingabeformularen ergänzt werden. Weitere
Verwaltungsfunktionen werden schrittweise ergänzt.

## Technik

| Komponente | Version / Hinweis |
|---|---|
| Django | 5.2 |
| Datenbank | PostgreSQL mit `DB_NAME` in `.env`; sonst SQLite |
| Anwendungsserver | Gunicorn (systemd-Dienst `schulverwaltung`) |
| Reverse Proxy | Nginx (optional, mit HTTPS) |
| Static Files | WhiteNoise |
| Konfiguration | `python-decouple` (`.env`) |
| Server | Linux (z. B. Linux Mint, Ubuntu, Debian, Fedora/RHEL) |

## Projektstruktur

```
schulverwaltung/             Repository- und Projektordner
├── manage.py
├── requirements.txt
├── .env.example             Konfigurationsvorlage ohne echte Zugangsdaten
├── update.sh                Aktualisierung auf dem Server
├── backup_db.sh             Datenbank-Backup und Cronjob-Einrichtung
├── restore_db.sh            Kontrollierte Wiederherstellung SQLite/PostgreSQL
├── db_umzug.sh              Hilfsskript SQLite-zu-PostgreSQL-Umzug mit temporärem Datenexport
├── schulverwaltung/         Django-Projekteinstellungen
│   ├── settings.py
│   ├── urls.py
│   └── wsgi.py
├── gespraeche/              Fachanwendung
│   ├── models.py            Datenmodelle
│   ├── admin.py             Admin-Oberflächen
│   ├── resources.py         Import-/Exportdefinitionen
│   ├── export_admin.py      separate Export-Hilfsklassen (nicht eingebunden)
│   ├── migrations/          versionierte Datenbankschemata
│   ├── static/              App-spezifische statische Dateien
│   └── views.py             keine eigenen Ansichten; Zugriff über den Admin
└── templates/admin/         Anpassungen des Django-Admin-Templates
```

## Datenmodell

- **Schueler** – Stammdaten, Schulform, Klasse, Status (z. B. „In Prüfung", „Angenommen"), Warteliste
- **Erziehungsberechtigter** – gehört zu einem Schüler (1:n), Kontaktdaten und Beziehung
- **Gespraech** – gehört zu einem Schüler (1:n), mit Protokollant (Django-User), Notizen, Förderbedarf und Aufnahmeempfehlung
- **Grundschule**, **Religion**, **Staatsangehoerigkeit** und **MarketingKanal** – zentrale Auswahllisten, die in Schüler-, Erziehungsberechtigten- und Gesprächsdaten wiederverwendet werden

Die drei Hauptmodelle können über die Django-Admin-Oberfläche mit `django-import-export` importiert und exportiert werden. Für Schülerimporte stehen die Vorlagen `import_schueler_template.csv` (neue Datensätze) und `update_schueler_template.csv` (bestehende Datensätze) bereit. Für Erziehungsberechtigte gibt es `import_erziehungsberechtigte_template.csv` und `update_erziehungsberechtigte_template.csv`. Alle Vorlagen verwenden Semikolons als Trennzeichen. Beim Import im jeweiligen Admin ist das passende Format für Neuanlage ohne ID oder Update mit ID auszuwählen. Ein Update verlangt eine gültige Datenbank-ID und legt keine neuen Datensätze an. In den Erziehungsberechtigten-Dateien bezeichnet `schueler` die Datenbank-ID der zugehörigen Schülerin bzw. des zugehörigen Schülers; `beziehung` verwendet die Werte `mutter`, `vater`, `vormund`, `pflegeeltern` oder `sonstiges`. Leere Zellen können vorhandene Werte überschreiben oder leeren; deshalb vor dem Bestätigen die Importvorschau kontrollieren. Der Import von Grundschule, Religion und Staatsangehörigkeit ordnet Werte über deren Namen zu und legt fehlende Katalogeinträge an. Exporte enthalten personenbezogene Daten und müssen entsprechend geschützt werden.

## Lokale Entwicklung

```bash
# Repository klonen
git clone https://github.com/sozpaed/schulverwaltung.git
cd schulverwaltung

# Virtuelle Umgebung anlegen und aktivieren
python3 -m venv schulverwaltung-venv
source schulverwaltung-venv/bin/activate        # Windows: schulverwaltung-venv\Scripts\activate

# Abhängigkeiten installieren
pip install -r requirements.txt

# Konfiguration anlegen
cp .env.example .env                            # danach .env mit eigenen Werten füllen
# Für lokale Entwicklung DEBUG=True setzen und SECRET_KEY ersetzen.
# Ohne DB_NAME verwendet Django die lokale Datei db.sqlite3.

# Datenbank vorbereiten und starten
python manage.py migrate
python manage.py createsuperuser
python manage.py runserver
```

Der Admin ist anschließend unter `http://127.0.0.1:8000/admin/` erreichbar.
Die Zwei-Faktor-Authentifizierung ist optional. Benutzer können sie nach der
Anmeldung über **Sicherheitseinstellungen** im Admin-Kopfbereich einrichten
oder deaktivieren. Für eingerichtete Konten ist bei der Anmeldung zusätzlich
zum Passwort ein App-Code erforderlich. Erzeuge nach der Einrichtung im
Zwei-Faktor-Profil Wiederherstellungscodes und bewahre sie sicher auf. Als
Ausstellername zeigt die App „MSO-Anmeldeverwaltung“ an. Bereits eingerichtete
App-Einträge übernehmen Namensänderungen nicht automatisch; dafür muss das
Konto in der App neu eingerichtet werden.

> Für die Entwicklung nur mit **Testdaten** arbeiten, nie mit echten Daten.

## Konfiguration (`.env`)

Die Datei `.env` wird nicht versioniert. Als Vorlage dient `.env.example`:

```
SECRET_KEY=<hier-einen-neuen-Schlüssel-generieren>
DEBUG=False
ALLOWED_HOSTS=127.0.0.1,localhost
CSRF_TRUSTED_ORIGINS=
USE_HTTPS=False

# Nur auf dem Server für PostgreSQL setzen; leer bedeutet SQLite.
DB_NAME=
DB_USER=
DB_PASSWORD=
DB_HOST=localhost
DB_PORT=5432
```

Einen neuen Secret Key erzeugen:

```bash
python -c 'from django.core.management.utils import get_random_secret_key; print(get_random_secret_key())'
```

Auf dem Server `DEBUG=False` lassen und `ALLOWED_HOSTS` auf die tatsächlich
verwendeten Hostnamen beschränken. Für HTTPS `CSRF_TRUSTED_ORIGINS` mit den
vollständigen `https://`-Ursprüngen befüllen und `USE_HTTPS=True` erst aktivieren,
wenn Nginx HTTPS terminiert und den konfigurierten Proxy-Header setzt.
Dateirechte auf dem Server: `chmod 600 .env`

## Deployment auf dem Server

Eine separate Deployment-Anleitung ist derzeit nicht im Repository enthalten.
Das Update-Skript geht von einer virtuellen Umgebung `.venv/` und einem
systemd-Dienst `schulverwaltung.service` aus. Vor dem Einsatz die Pfade,
Zugangsdaten und Dienstkonfiguration auf dem Server prüfen.
Vor `migrate` erstellt `update.sh` mit `backup_db.sh` ein Datenbank-Backup.
Schlägt das Backup fehl – etwa weil das konfigurierte externe Medium nicht
eingehängt oder nicht beschreibbar ist –, wird das Update vor der Migration
abgebrochen. Das Backup-Medium muss daher auch beim manuellen Update verfügbar
sein.

### Update einspielen

```bash
./update.sh
```

Das Skript wechselt ins Repository, lädt Änderungen mit `git pull --ff-only`,
aktiviert `.venv/`, installiert Abhängigkeiten, führt Migrationen und
`collectstatic` aus und startet anschließend den systemd-Dienst neu.

### Datenbankumzug

`db_umzug.sh` speichert den temporären Klartext-Export außerhalb des
Projektordners in einem nur für den aktuellen Benutzer zugänglichen
temporären Verzeichnis. Die Datei wird beim Beenden des Skripts entfernt,
auch wenn der Umzug mit einem Fehler endet.

### Backup der Datenbank

Das interaktive Skript richtet ein regelmäßiges Backup auf einem bereits
eingehängten Medium ein und erstellt sofort eine erste Sicherung:

```bash
./backup_db.sh
```

Es fragt den Einhängepunkt, den Intervall und die lokale Uhrzeit ab und bietet
optional GPG-Verschlüsselung an. Wöchentliche Backups laufen montags, monatliche
am ersten Tag des Monats. Die Sicherungen liegen in `Schulverwaltung-Backups`
auf dem Medium. SQLite wird konsistent als Datenbankdatei gesichert; PostgreSQL
wird als nativer `pg_dump`-Dump gespeichert.
Der Benutzer-Cronjob prüft vor jedem Lauf, dass dasselbe Medium eingehängt ist,
und schreibt ein Protokoll nach `~/.local/state/schulverwaltung/`.
Mit `./backup_db.sh --backup-now` lässt sich jederzeit ein zusätzliches Backup
auslösen. Bei Auswahl der Verschlüsselung prüft das Skript GnuPG und fragt bei
Bedarf vor der Installation mit Administratorrechten. Unterstützt werden dnf,
apt-get, yum, zypper und pacman. Der zufällig generierte Schlüssel liegt standardmäßig in
`~/.config/schulverwaltung/.backup-key.txt` mit Dateirechten nur für den
aktuellen Benutzer. Das Skript verlangt einen Pfad außerhalb des Backup-Mediums.
Ein versteckter Dateiname ist kein Schutz: Den Schlüssel separat und sicher
aufbewahren und zusätzlich eine geschützte Kopie für den Wiederherstellungsfall
anlegen. Ohne den Schlüssel sind verschlüsselte Backups nicht wiederherstellbar.
Die Standardablage auf demselben Rechner schützt nicht vor dem gleichzeitigen
Diebstahl von Rechner und Backup-Medium; für diesen Fall den Schlüssel getrennt
auf einem anderen sicheren Medium verwahren.
Bei verschlüsselten Backups wird die unverschlüsselte Datenbankdatei nur
vorübergehend in einem privaten lokalen temporären Ordner erstellt und danach
entfernt. Alte Backups werden nicht automatisch gelöscht; den freien Speicher
regelmäßig prüfen. Daten und Schlüssel angemessen schützen.

Ein GPG-Backup kann mit dem gespeicherten Schlüssel entschlüsselt werden:

```bash
gpg --batch --pinentry-mode loopback \
  --passphrase-file ~/.config/schulverwaltung/.backup-key.txt \
  --output wiederhergestellte-datenbank.sqlite3 \
  --decrypt /pfad/zum/backup.sqlite3.gpg
```

Bei PostgreSQL lautet die entschlüsselte Datei auf `.dump`; sie kann mit
`pg_restore` in eine PostgreSQL-Datenbank eingespielt werden.

### Datenbank wiederherstellen

Die Wiederherstellung überschreibt Daten. Das Skript kontrolliert zuerst Typ
und Integrität des Backups, verlangt eine ausdrückliche Bestätigung und erstellt
vorher ein Sicherheitsbackup des aktuellen Datenstands. Das konfigurierte
Backup-Medium muss dafür eingehängt sein. Ein laufender
`schulverwaltung.service` wird während des Vorgangs angehalten und nach
erfolgreicher Prüfung wieder gestartet:

```bash
./restore_db.sh /pfad/zum/backup.dump.gpg
```

Ohne Argument fragt das Skript interaktiv nach der Backup-Datei. SQLite-Backups
(`.sqlite3`, optional `.gpg`) werden atomar eingespielt. PostgreSQL-Backups
(`.dump`, optional `.gpg`) werden mit `pg_restore --clean` in die in `.env`
konfigurierte Datenbank eingespielt; der Restore läuft in einer Transaktion,
damit ein fehlgeschlagener Einspielversuch zurückgerollt wird. Dafür müssen
`pg_restore` und die passenden Datenbankrechte verfügbar sein. Für
verschlüsselte Dateien wird der GPG-Schlüssel abgefragt; standardmäßig schlägt
das Skript den eingerichteten Schlüsselpfad vor.

## Datenschutz und Sicherheit

- Zugriff nur über HTTPS; bei Erreichbarkeit über das Internet ist das zwingend.
- `DEBUG = False` im Produktivbetrieb; vor jedem Livegang `python manage.py check --deploy` ausführen.
- Die Zwei-Faktor-Authentifizierung ist optional; für eingerichtete Konten wird sie beim Login verlangt. Wiederherstellungscodes sicher und getrennt vom Passwort aufbewahren.
- Nur Personen mit dienstlicher Notwendigkeit erhalten einen Benutzer; Rechte im Admin gezielt vergeben.
- Aufbewahrungs- und Löschfristen für Bewerberdaten einhalten (abgelehnte Bewerbungen, Verzichte).
- Keine Passwörter, Schlüssel, Exporte oder Datenbank-Dumps committen (siehe `.gitignore`).
