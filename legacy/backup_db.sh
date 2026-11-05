#!/usr/bin/env bash

set -Eeuo pipefail
umask 077
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_PATH="$(readlink -f -- "${BASH_SOURCE[0]}")"
PROJECT_DIR="$(cd -- "$(dirname -- "$SCRIPT_PATH")" && pwd -P)"
CONFIG_DIR="${XDG_CONFIG_HOME:-${HOME:?HOME ist nicht gesetzt}/.config}/schulverwaltung"
CONFIG_FILE="$CONFIG_DIR/database-backup.conf"
LOG_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/schulverwaltung"
LOG_FILE="$LOG_DIR/database-backup.log"
BACKUP_DIR_NAME="Schulverwaltung-Backups"
CRON_BEGIN="# BEGIN schulverwaltung-database-backup"
CRON_END="# END schulverwaltung-database-backup"

fail() {
    printf 'Fehler: %s\n' "$*" >&2
    exit 1
}

load_config() {
    [[ -r "$CONFIG_FILE" ]] || fail "Konfiguration nicht lesbar: $CONFIG_FILE"

    local -a config=()
    mapfile -t config < "$CONFIG_FILE"
    [[ ${#config[@]} -eq 6 || ${#config[@]} -eq 8 ]] ||
        fail "Ungültige Backup-Konfiguration: $CONFIG_FILE"

    BACKUP_DIR="${config[0]}"
    MOUNT_POINT="${config[1]}"
    MOUNT_SOURCE="${config[2]}"
    MINUTE="${config[3]}"
    HOUR="${config[4]}"
    INTERVAL="${config[5]}"
    ENCRYPTION="${config[6]:-no}"
    KEY_FILE="${config[7]:--}"

    [[ "$ENCRYPTION" == yes || "$ENCRYPTION" == no ]] ||
        fail "Ungültige Verschlüsselungseinstellung in $CONFIG_FILE"
    if [[ "$ENCRYPTION" == yes ]]; then
        [[ "$KEY_FILE" != "-" && "$KEY_FILE" == /* ]] ||
            fail "Ungültiger Schlüsseldateipfad in $CONFIG_FILE"
        [[ -f "$KEY_FILE" && -r "$KEY_FILE" ]] ||
            fail "Schlüsseldatei nicht gefunden oder nicht lesbar: $KEY_FILE"
        local key_real_path
        key_real_path="$(realpath -e -- "$KEY_FILE")" ||
            fail "Schlüsseldateipfad konnte nicht aufgelöst werden."
        case "$key_real_path/" in
            "$MOUNT_POINT"/*)
                fail "Die Schlüsseldatei darf nicht auf dem Backup-Medium liegen."
                ;;
        esac
        command -v gpg >/dev/null 2>&1 ||
            fail "GPG ist nicht installiert; verschlüsselte Backups können nicht erstellt werden."
    fi
}

verify_media() {
    [[ -d "$MOUNT_POINT" ]] || fail "Backup-Medium nicht gefunden: $MOUNT_POINT"
    mountpoint -q -- "$MOUNT_POINT" ||
        fail "Backup-Medium ist nicht eingehängt: $MOUNT_POINT"

    local current_target current_source
    current_target="$(findmnt --raw --noheadings --target "$MOUNT_POINT" --output TARGET)" ||
        fail "Eingehängtes Backup-Medium konnte nicht ermittelt werden."
    current_source="$(findmnt --raw --noheadings --target "$MOUNT_POINT" --output SOURCE)" ||
        fail "Quelle des Backup-Mediums konnte nicht ermittelt werden."

    [[ "$current_target" == "$MOUNT_POINT" ]] ||
        fail "Der gespeicherte Pfad ist kein eigener Einhängepunkt mehr: $MOUNT_POINT"
    [[ "$current_source" == "$MOUNT_SOURCE" ]] ||
        fail "Am Backup-Pfad ist ein anderes Medium eingehängt als bei der Einrichtung."
    [[ -d "$BACKUP_DIR" && -w "$BACKUP_DIR" ]] ||
        fail "Backup-Ordner fehlt oder ist nicht beschreibbar: $BACKUP_DIR"
}

ensure_gpg_installed() {
    if command -v gpg >/dev/null 2>&1; then
        return
    fi

    local package_manager package
    if command -v dnf >/dev/null 2>&1; then
        package_manager="dnf"
        package="gnupg2"
    elif command -v apt-get >/dev/null 2>&1; then
        package_manager="apt-get"
        package="gnupg"
    elif command -v yum >/dev/null 2>&1; then
        package_manager="yum"
        package="gnupg2"
    elif command -v zypper >/dev/null 2>&1; then
        package_manager="zypper"
        package="gpg2"
    elif command -v pacman >/dev/null 2>&1; then
        package_manager="pacman"
        package="gnupg"
    else
        fail "GPG fehlt und es wurde kein unterstützter Paketmanager gefunden. Installiere GnuPG manuell."
    fi

    printf 'GPG ist nicht installiert. Paketmanager: %s; Paket: %s.\n' \
        "$package_manager" "$package"
    local install_confirmation
    read -r -p "GnuPG jetzt mit Administratorrechten installieren? [j/N] " install_confirmation
    [[ "$install_confirmation" == [jJ] || "$install_confirmation" == [jJ][aA] ]] ||
        fail "GPG wird benötigt, wenn verschlüsselte Backups aktiviert sind."

    local -a install_command
    case "$package_manager" in
        dnf) install_command=(dnf install -y "$package") ;;
        apt-get) install_command=(apt-get install -y "$package") ;;
        yum) install_command=(yum install -y "$package") ;;
        zypper) install_command=(zypper --non-interactive install "$package") ;;
        pacman) install_command=(pacman --noconfirm -S --needed "$package") ;;
    esac

    if [[ "$EUID" -eq 0 ]]; then
        "${install_command[@]}" ||
            fail "Installation von GnuPG mit $package_manager ist fehlgeschlagen."
    else
        command -v sudo >/dev/null 2>&1 ||
            fail "sudo ist nicht verfügbar. Installiere GnuPG manuell mit Administratorrechten."
        sudo -v || fail "Administratorrechte konnten nicht bestätigt werden."
        sudo "${install_command[@]}" ||
            fail "Installation von GnuPG mit $package_manager ist fehlgeschlagen."
    fi

    command -v gpg >/dev/null 2>&1 ||
        fail "Die Paketinstallation war erfolgreich, aber gpg ist weiterhin nicht verfügbar."
    printf '%s\n' "GPG ist installiert."
}

create_backup() {
    local python="$PROJECT_DIR/.venv/bin/python"
    [[ -x "$python" ]] || fail "Python-Umgebung nicht gefunden: $python"

    "$python" - "$PROJECT_DIR" "$BACKUP_DIR" "$ENCRYPTION" "$KEY_FILE" <<'PY'
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
from datetime import datetime
from pathlib import Path

project_dir = Path(sys.argv[1])
backup_dir = Path(sys.argv[2])
encryption = sys.argv[3]
key_file = Path(sys.argv[4]) if sys.argv[4] != "-" else None
sys.path.insert(0, str(project_dir))
os.environ.setdefault("DJANGO_SETTINGS_MODULE", "schulverwaltung.settings")

import django

django.setup()

from django.conf import settings
from django.db import connections

database = settings.DATABASES["default"]
engine = database["ENGINE"]
timestamp = datetime.now().astimezone().strftime("%Y%m%d_%H%M%S_%f")

if engine == "django.db.backends.sqlite3":
    suffix = ".sqlite3"
elif engine == "django.db.backends.postgresql":
    suffix = ".dump"
else:
    raise SystemExit(f"Nicht unterstützter Datenbanktyp: {engine}")

final_path = backup_dir / f"schulverwaltung_{timestamp}{suffix}"
if encryption == "yes":
    if key_file is None or not key_file.is_file():
        raise RuntimeError("Die konfigurierte Schlüsseldatei fehlt.")
    if key_file.stat().st_mode & 0o077:
        raise RuntimeError("Die Schlüsseldatei muss ausschließlich für den Besitzer lesbar sein (chmod 600).")
    if shutil.which("gpg") is None:
        raise RuntimeError("GPG ist nicht installiert oder nicht im PATH.")
    final_path = final_path.with_name(final_path.name + ".gpg")

with tempfile.TemporaryDirectory(prefix="schulverwaltung-backup-") as temp_dir:
    os.chmod(temp_dir, 0o700)
    temporary_name = str(Path(temp_dir) / f"database{suffix}")
    password_file = None
    try:
        if engine == "django.db.backends.sqlite3":
            source = connections["default"]
            source.ensure_connection()
            with sqlite3.connect(temporary_name) as destination:
                source.connection.backup(destination)
                result = destination.execute("PRAGMA integrity_check").fetchone()
                if not result or result[0] != "ok":
                    raise RuntimeError(f"SQLite-Prüfung fehlgeschlagen: {result}")
        else:
            pg_dump = shutil.which("pg_dump")
            if not pg_dump:
                raise RuntimeError("pg_dump ist nicht installiert oder nicht im PATH.")

            def pgpass_escape(value):
                return str(value).replace("\\", "\\\\").replace(":", "\\:")

            fd, password_file = tempfile.mkstemp(
                prefix=".schulverwaltung-pgpass-",
                dir=temp_dir,
            )
            os.fchmod(fd, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8") as file:
                file.write(
                    ":".join(
                        pgpass_escape(value)
                        for value in (
                            database.get("HOST") or "",
                            database.get("PORT") or "5432",
                            database["NAME"],
                            database.get("USER") or "",
                            database.get("PASSWORD") or "",
                        )
                    )
                    + "\n"
                )

            environment = os.environ.copy()
            environment.pop("PGPASSWORD", None)
            environment["PGPASSFILE"] = password_file
            command = [
                pg_dump,
                "--no-password",
                "--format=custom",
                f"--file={temporary_name}",
                f"--host={database.get('HOST') or ''}",
                f"--port={database.get('PORT') or '5432'}",
                f"--username={database.get('USER') or ''}",
                database["NAME"],
            ]
            subprocess.run(command, check=True, env=environment)

        if not os.path.isfile(temporary_name) or os.path.getsize(temporary_name) == 0:
            raise RuntimeError("Die Backup-Datei fehlt oder ist leer.")

        if encryption == "yes":
            fd, encrypted_name = tempfile.mkstemp(
                prefix=".schulverwaltung-encrypted-",
                dir=backup_dir,
            )
            os.close(fd)
            os.chmod(encrypted_name, 0o600)
            gpg_home = str(Path(temp_dir) / "gnupg")
            os.mkdir(gpg_home, 0o700)
            try:
                subprocess.run(
                    [
                        shutil.which("gpg"),
                        "--homedir",
                        gpg_home,
                        "--batch",
                        "--yes",
                        "--no-symkey-cache",
                        "--pinentry-mode",
                        "loopback",
                        "--passphrase-file",
                        str(key_file),
                        "--symmetric",
                        "--cipher-algo",
                        "AES256",
                        "--force-mdc",
                        "--output",
                        encrypted_name,
                        temporary_name,
                    ],
                    check=True,
                )
                if os.path.getsize(encrypted_name) == 0:
                    raise RuntimeError("Die verschlüsselte Backup-Datei ist leer.")
                os.replace(encrypted_name, final_path)
            finally:
                if os.path.exists(encrypted_name):
                    os.unlink(encrypted_name)
        else:
            os.replace(temporary_name, final_path)

        os.chmod(final_path, 0o600)
        print(f"Backup erfolgreich erstellt: {final_path}")
    finally:
        connections.close_all()
        if password_file and os.path.exists(password_file):
            os.unlink(password_file)
PY
}

run_backup() {
    load_config
    verify_media
    create_backup
}

install_cron_job() {
    command -v crontab >/dev/null 2>&1 ||
        fail "crontab ist nicht installiert. Installiere/aktiviere den Cron-Dienst und versuche es erneut."

    local day_of_month="*" day_of_week="*"
    case "$INTERVAL" in
        täglich) ;;
        wöchentlich) day_of_week="1" ;;
        monatlich) day_of_month="1" ;;
        *) fail "Ungültiger Backup-Intervall in der Konfiguration." ;;
    esac

    local quoted_script cron_command cron_line existing_crontab updated_crontab
    printf -v quoted_script "'%s'" "${SCRIPT_PATH//\'/\'\\\'\'}"
    cron_command="/bin/bash $quoted_script --scheduled"
    cron_command="${cron_command//%/\\%}"
    cron_line="$MINUTE $HOUR $day_of_month * $day_of_week $cron_command"
    existing_crontab="$(crontab -l 2>/dev/null || true)"
    updated_crontab="$(
        printf '%s\n' "$existing_crontab" |
            awk -v begin="$CRON_BEGIN" -v end="$CRON_END" '
                $0 == begin { skip = 1; next }
                $0 == end { skip = 0; next }
                !skip { print }
                END { if (skip) exit 2 }
            '
    )" || fail "Vorhandener verwalteter Cronjob-Block ist unvollständig; Crontab wurde nicht verändert."
    updated_crontab="${updated_crontab%$'\n'}"
    if [[ -n "$updated_crontab" ]]; then
        updated_crontab+=$'\n'
    fi
    updated_crontab+="$CRON_BEGIN"$'\n'"$cron_line"$'\n'"$CRON_END"

    printf '%s\n' "$updated_crontab" | crontab - ||
        fail "Der Cronjob konnte nicht eingerichtet werden."
}

setup_backup() {
    [[ -t 0 ]] || fail "Die Einrichtung muss interaktiv in einem Terminal erfolgen."
    [[ "$SCRIPT_PATH" != *$'\n'* ]] || fail "Der Skriptpfad darf keinen Zeilenumbruch enthalten."
    [[ -x "$PROJECT_DIR/.venv/bin/python" ]] ||
        fail "Lege zuerst die virtuelle Umgebung .venv im Projektordner an."
    command -v findmnt >/dev/null 2>&1 || fail "findmnt ist nicht installiert."
    command -v mountpoint >/dev/null 2>&1 || fail "mountpoint ist nicht installiert."

    printf '%s\n' "Einrichtung des Datenbank-Backups"
    printf '%s\n' "Gib den Einhängepunkt des Backup-Mediums an (z. B. /run/media/${USER:-BENUTZER}/USB)."
    local mount_point
    read -r -p "Einhängepunkt: " mount_point
    [[ -n "$mount_point" && "$mount_point" == /* ]] ||
        fail "Bitte einen absoluten Pfad zum eingehängten Medium angeben."
    [[ -d "$mount_point" ]] || fail "Der Pfad existiert nicht: $mount_point"
    mount_point="$(cd -- "$mount_point" && pwd -P)"
    mountpoint -q -- "$mount_point" ||
        fail "Der angegebene Pfad ist kein eingehängtes Medium: $mount_point"

    local mount_target mount_source
    mount_target="$(findmnt --raw --noheadings --target "$mount_point" --output TARGET)" ||
        fail "Einhängepunkt konnte nicht ermittelt werden."
    mount_source="$(findmnt --raw --noheadings --target "$mount_point" --output SOURCE)" ||
        fail "Medium konnte nicht ermittelt werden."
    [[ "$mount_target" == "$mount_point" ]] ||
        fail "Bitte den genauen Einhängepunkt angeben: $mount_target"
    [[ -n "$mount_source" ]] || fail "Die Quelle des Backup-Mediums ist leer."

    local interval_choice interval
    printf '%s\n' "Intervall: 1) täglich  2) wöchentlich (montags)  3) monatlich (am 1.)"
    read -r -p "Auswahl [1-3]: " interval_choice
    case "$interval_choice" in
        1) interval="täglich" ;;
        2) interval="wöchentlich" ;;
        3) interval="monatlich" ;;
        *) fail "Bitte 1, 2 oder 3 eingeben." ;;
    esac

    local backup_time hour minute
    read -r -p "Uhrzeit im lokalen Zeitzonenformat HH:MM: " backup_time
    [[ "$backup_time" =~ ^([01][0-9]|2[0-3]):([0-5][0-9])$ ]] ||
        fail "Ungültige Uhrzeit. Erwartet wird HH:MM, z. B. 06:30."
    hour="${BASH_REMATCH[1]}"
    minute="${BASH_REMATCH[2]}"

    local backup_dir="$mount_point/$BACKUP_DIR_NAME"
    mkdir -p -- "$backup_dir"
    chmod 700 -- "$backup_dir"
    BACKUP_DIR="$backup_dir"
    MOUNT_POINT="$mount_point"
    MOUNT_SOURCE="$mount_source"
    verify_media

    printf '\nMedium: %s (%s)\n' "$MOUNT_POINT" "$MOUNT_SOURCE"
    printf 'Zielordner: %s\n' "$BACKUP_DIR"
    printf 'Zeitplan: %s um %s:%s (lokale Systemzeit)\n' "$interval" "$hour" "$minute"
    printf '%s\n' \
        "Verschlüsselung schützt die Backup-Dateien zusätzlich, sofern der Schlüssel getrennt aufbewahrt wird." \
        "Ein versteckter Dateiname allein schützt den Schlüssel nicht."
    local encryption_choice encryption="no" key_file="-"
    read -r -p "Backups verschlüsseln? [1] Ja - Verschlüsseln / [2] Nein: " encryption_choice
    case "$encryption_choice" in
      1|[jJ]|[jJ][aA]|"Ja - Verschlüsseln"|"ja - verschlüsseln")
        ensure_gpg_installed
        command -v openssl >/dev/null 2>&1 ||
            fail "OpenSSL ist nicht installiert; der Zufallsschlüssel kann nicht erstellt werden."
        encryption="yes"
        key_file="$CONFIG_DIR/.backup-key.txt"
        printf 'Der Schlüssel wird standardmäßig außerhalb des Backup-Mediums gespeichert:\n%s\n' "$key_file"
        read -r -p "Anderen absoluten Pfad für die Schlüsseldatei eingeben oder Enter für den Standard: " requested_key_file
        if [[ -n "$requested_key_file" ]]; then
            [[ "$requested_key_file" == /* && "$requested_key_file" != *$'\n'* ]] ||
                fail "Der Schlüsselpfad muss absolut sein und darf keinen Zeilenumbruch enthalten."
            key_file="$requested_key_file"
        fi
        key_file="$(realpath -m -- "$key_file")"
        case "$key_file/" in
            "$mount_point"/*)
                fail "Die Schlüsseldatei muss außerhalb des Backup-Mediums liegen."
                ;;
        esac
        [[ ! -e "$key_file" && ! -L "$key_file" ]] ||
            fail "Die Schlüsseldatei existiert bereits und wird nicht überschrieben: $key_file"
        ;;
      ""|2|[nN]|[nN][eE][iI][nN]) ;;
      *) fail "Bitte 'Ja - Verschlüsseln' oder 'Nein' auswählen." ;;
    esac

    local confirmation
    read -r -p "Erstes Backup erstellen und Zeitplan einrichten? [j/N] " confirmation
    [[ "$confirmation" == [jJ] || "$confirmation" == [jJ][aA] ]] ||
        fail "Einrichtung abgebrochen."

    mkdir -p -- "$CONFIG_DIR" "$LOG_DIR"
    chmod 700 -- "$CONFIG_DIR" "$LOG_DIR"
    if [[ "$encryption" == yes ]]; then
        local key_dir
        key_dir="$(dirname -- "$key_file")"
        if [[ ! -d "$key_dir" ]]; then
            mkdir -p -- "$key_dir"
            chmod 700 -- "$key_dir"
        fi
        (
            umask 077
            set -o noclobber
            openssl rand -hex 32 > "$key_file"
        ) || fail "Der Schlüssel konnte nicht sicher erstellt werden: $key_file"
        chmod 600 -- "$key_file"
    fi

    local config_tmp
    config_tmp="$(mktemp "$CONFIG_DIR/.database-backup.XXXXXX")"
    chmod 600 -- "$config_tmp"
    printf '%s\n' \
        "$BACKUP_DIR" \
        "$MOUNT_POINT" \
        "$MOUNT_SOURCE" \
        "$minute" \
        "$hour" \
        "$interval" \
        "$encryption" \
        "$key_file" > "$config_tmp"
    mv -f -- "$config_tmp" "$CONFIG_FILE"
    chmod 600 -- "$CONFIG_FILE"

    ENCRYPTION="$encryption"
    KEY_FILE="$key_file"
    create_backup

    MINUTE="$minute"
    HOUR="$hour"
    INTERVAL="$interval"
    install_cron_job

    printf '\nZeitplan eingerichtet. Laufprotokoll: %s\n' "$LOG_FILE"
    local cron_service_active=false
    if command -v systemctl >/dev/null 2>&1; then
        if systemctl is-active --quiet cron 2>/dev/null ||
            systemctl is-active --quiet crond 2>/dev/null; then
            cron_service_active=true
        fi
    fi
    if [[ "$cron_service_active" == false ]]; then
        printf '%s\n' \
            "Hinweis: Der Cron-Dienst scheint nicht aktiv zu sein; der Zeitplan läuft erst, wenn er gestartet ist." \
            "Linux Mint/Ubuntu/Debian: 'sudo systemctl enable --now cron'; Fedora/RHEL: 'sudo systemctl enable --now crond'."
    fi
}

case "${1:-}" in
    --scheduled)
        mkdir -p -- "$LOG_DIR"
        exec >> "$LOG_FILE" 2>&1
        printf '\n[%s] Geplanter Backup-Lauf\n' "$(date --iso-8601=seconds)"
        run_backup
        ;;
    --backup-now)
        mkdir -p -- "$LOG_DIR"
        exec >> "$LOG_FILE" 2>&1
        printf '\n[%s] Manueller Backup-Lauf\n' "$(date --iso-8601=seconds)"
        run_backup
        ;;
    "")
        setup_backup
        ;;
    *)
        fail "Aufruf: $0 [--backup-now]"
        ;;
esac
