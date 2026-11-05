#!/bin/bash

# Bricht das Skript bei einem Fehler ab
set -e
umask 077

echo "================================================="
echo "🔄 Datenbank-Umzug (SQLite -> PostgreSQL)"
echo "================================================="

cd "$(dirname "$0")"

TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/schulverwaltung-umzug.XXXXXX")"
DATA_FILE="$TEMP_DIR/daten_export.json"

cleanup() {
    if [[ -n "${DATA_FILE:-}" && -f "$DATA_FILE" ]]; then
        rm -f -- "$DATA_FILE"
    fi
    if [[ -n "${TEMP_DIR:-}" && -d "$TEMP_DIR" ]]; then
        rmdir -- "$TEMP_DIR" 2>/dev/null ||
            echo "Hinweis: Temporäres Verzeichnis konnte nicht entfernt werden: $TEMP_DIR" >&2
    fi
}
trap cleanup EXIT

# Falls venv genutzt wird, hier aktivieren (Kommentarzeichen entfernen falls nötig)
# source venv/bin/activate

echo "1️⃣  Sichere Daten aus der aktuellen SQLite-Datenbank..."
# WICHTIG: contenttypes und auth.permission werden ausgeschlossen, 
# da diese von PostgreSQL bei der Migration neu generiert werden und sonst kollidieren.
python manage.py dumpdata --exclude auth.permission --exclude contenttypes > "$DATA_FILE"
echo "✅ Datenexport wurde mit restriktiven Rechten temporär außerhalb des Projekts gespeichert."

echo ""
echo "================================================="
echo "⚠️  WICHTIGER ZWISCHENSCHRITT"
echo "================================================="
echo "Das Skript pausiert jetzt."
echo "Bitte öffne ein zweites Terminal oder editiere JETZT deine .env Datei,"
echo "sodass die Zugangsdaten für PostgreSQL eingetragen und aktiv sind."
echo ""
read -p "👉 Drücke [ENTER], sobald die .env auf PostgreSQL umgestellt ist..."

echo ""
echo "2️⃣  Bereite leere PostgreSQL-Datenbank vor..."
# Legt die Tabellenstrukturen in der neuen, leeren Datenbank an
python manage.py migrate

echo "3️⃣  Importiere die gesicherten Daten in PostgreSQL..."
# Schreibt die JSON-Daten in die frischen PostgreSQL-Tabellen
python manage.py loaddata "$DATA_FILE"

echo "================================================="
echo "🚀 Umzug erfolgreich abgeschlossen!"
echo "Alle Daten liegen nun in der PostgreSQL-Datenbank."
echo "Der temporäre Klartext-Export wird beim Beenden des Skripts entfernt."
echo "================================================="