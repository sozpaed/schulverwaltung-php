#!/bin/bash

# Bricht das Skript sofort ab, falls ein einzelner Befehl fehlschlägt
set -e

echo "========================================"
echo "🚀 Starte Update für die Schulverwaltung"
echo "========================================"

# 1. Stelle sicher, dass wir im richtigen Verzeichnis sind
# (Wechselt in den Ordner, in dem dieses Skript liegt)
cd "$(dirname "$0")"

echo "🕒 Prüfe Synchronisierung der Serveruhr..."
if command -v timedatectl >/dev/null 2>&1; then
    time_sync_status="$(timedatectl show -p NTPSynchronized --value 2>/dev/null || true)"
    if [[ "$time_sync_status" == "yes" ]]; then
        echo "✅ Serveruhr ist synchronisiert."
    else
        echo "⚠️  Die Synchronisierung der Serveruhr konnte nicht bestätigt werden." >&2
        echo "   Prüfe 'timedatectl status'; eine falsche Uhr kann TOTP-Anmeldungen stören." >&2
    fi
else
    echo "⚠️  timedatectl ist nicht verfügbar; Serveruhr-Synchronisierung nicht geprüft." >&2
    echo "   Prüfe die Zeitsynchronisierung manuell, da eine falsche Uhr TOTP stören kann." >&2
fi

# 2. Neuesten Code laden
echo "⬇️  Ziehe neueste Änderungen von GitHub..."
git pull --ff-only

# 3. Virtuelle Umgebung aktivieren (Pfad ggf. anpassen, falls der Ordner anders heißt)
echo "🐍 Aktiviere virtuelle Umgebung..."
source .venv/bin/activate

# 4. Abhängigkeiten prüfen (falls neue Pakete in requirements.txt stehen)
echo "📦 Prüfe Python-Pakete..."
pip install -r requirements.txt

# 5. Datenbank sichern und nur bei erfolgreichem Backup migrieren
echo "💾 Erstelle Datenbank-Backup vor der Migration..."
if ! ./backup_db.sh --backup-now; then
    echo "❌ Update abgebrochen: Das Datenbank-Backup war nicht erfolgreich." >&2
    echo "Prüfe, ob das konfigurierte Backup-Medium eingehängt und beschreibbar ist." >&2
    echo "Weitere Details: ~/.local/state/schulverwaltung/database-backup.log" >&2
    exit 1
fi

# 6. Datenbank migrieren
echo "🗄️  Führe Datenbank-Migrationen durch..."
python manage.py migrate

# 7. Statische Dateien sammeln (CSS, JS, Bilder)
echo "🎨 Sammle statische Dateien..."
python manage.py collectstatic --noinput

# 8. Systemd-Dienst neustarten (erfordert sudo-Rechte)
echo "🔄 Starte Gunicorn (schulverwaltung.service) neu..."
sudo systemctl restart schulverwaltung.service

echo "========================================"
echo "✅ Update erfolgreich abgeschlossen!"
echo "========================================"