#!/bin/sh

set -eu

fail() {
    printf 'Fehler: %s\n' "$1" >&2
    exit 1
}

[ -t 0 ] || fail 'Das Skript muss interaktiv über ein SSH-Terminal ausgeführt werden.'
command -v mysql >/dev/null 2>&1 || fail 'Der MySQL-Client mysql wurde nicht gefunden.'

script_dir=$(CDPATH= cd "$(dirname "$0")" && pwd)
migration_file="$script_dir/../database/migrations/20261106_optional_student_fields.sql"
[ -r "$migration_file" ] || fail "Die Migrationsdatei ist nicht lesbar: $migration_file"
migration_sql=$(cat "$migration_file") || fail 'Die Migrationsdatei konnte nicht gelesen werden.'
[ -n "$migration_sql" ] || fail 'Die Migrationsdatei ist leer.'

printf '%s\n' 'Migration: Optionale Angaben bei Schülerinnen'
printf '%s\n' 'Vorher eine aktuelle, geprüfte Datenbanksicherung erstellen.'
printf 'Pfad zur vorhandenen Sicherungsdatei: '
IFS= read -r backup
[ -f "$backup" ] && [ -s "$backup" ] && [ -r "$backup" ] \
    || fail 'Die Sicherungsdatei muss existieren, lesbar und nicht leer sein.'

printf 'Datenbank-Host: '
IFS= read -r host
printf 'Datenbank-Port [3306]: '
IFS= read -r port
port=${port:-3306}
printf 'Datenbankname: '
IFS= read -r database
printf 'Datenbankbenutzer mit ALTER-Recht: '
IFS= read -r user

case "$host" in ''|*[!A-Za-z0-9.:-]*) fail 'Ungültiger Datenbank-Host.' ;; esac
case "$port" in ''|*[!0-9]*) fail 'Ungültiger Datenbank-Port.' ;; esac
[ "${#port}" -le 5 ] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ] \
    || fail 'Der Datenbank-Port muss zwischen 1 und 65535 liegen.'
case "$database" in ''|*[!A-Za-z0-9_-]*) fail 'Ungültiger Datenbankname.' ;; esac
[ -n "$user" ] || fail 'Der Datenbankbenutzer darf nicht leer sein.'

printf '\nDatenbank: %s auf %s:%s\n' "$database" "$host" "$port"
printf 'Sicherungsdatei: %s\n' "$backup"
printf '%s\n' 'Die Datei wurde nur auf Lesbarkeit und Größe geprüft, nicht auf Wiederherstellbarkeit.'
printf 'Migration für diese Datenbank ausführen? JA eingeben: '
IFS= read -r confirmation
[ "$confirmation" = 'JA' ] || fail 'Abgebrochen; die Datenbank wurde nicht geändert.'

printf '%s\n' 'Das Datenbankpasswort wird vom MySQL-Client abgefragt.'
verification_sql="
SELECT COUNT(*)
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'schueler'
  AND COLUMN_NAME IN ('geburtsdatum', 'plz', 'staatsangehoerigkeit_id')
  AND IS_NULLABLE = 'YES';"

if result=$(mysql --protocol=TCP --host="$host" --port="$port" --user="$user" \
    --password --database="$database" --batch --skip-column-names \
    --execute="$migration_sql
$verification_sql"); then
    if [ "$result" = '3' ]; then
        printf '%s\n' 'Migration erfolgreich: Alle drei Spalten erlauben NULL.'
    else
        fail 'Die Prüfung war nicht erfolgreich. Datenbankzustand prüfen, bevor das Skript erneut ausgeführt wird.'
    fi
else
    fail 'Migration oder Prüfung fehlgeschlagen. Datenbankzustand prüfen, bevor das Skript erneut ausgeführt wird.'
fi
