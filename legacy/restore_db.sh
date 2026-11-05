#!/usr/bin/env bash

set -Eeuo pipefail
umask 077
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

SCRIPT_PATH="$(readlink -f -- "${BASH_SOURCE[0]}")"
PROJECT_DIR="$(cd -- "$(dirname -- "$SCRIPT_PATH")" && pwd -P)"

if [[ ! -x "$PROJECT_DIR/.venv/bin/python" ]]; then
    printf 'Fehler: Python-Umgebung nicht gefunden: %s/.venv\n' "$PROJECT_DIR" >&2
    exit 1
fi

exec "$PROJECT_DIR/.venv/bin/python" -c "$(cat <<'PY'
import os
import shutil
import sqlite3
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

project_dir = Path(sys.argv[1])
arguments = sys.argv[2:]
os.chdir(project_dir)
sys.path.insert(0, str(project_dir))
os.environ.setdefault("DJANGO_SETTINGS_MODULE", "schulverwaltung.settings")

import django

django.setup()

from django.conf import settings
from django.db import connections


SERVICE_NAME = "schulverwaltung.service"
CONFIG_DIR = Path(
    os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config"))
) / "schulverwaltung"
BACKUP_CONFIG = CONFIG_DIR / "database-backup.conf"


def fail(message):
    raise RuntimeError(message)


def default_key_file():
    try:
        lines = BACKUP_CONFIG.read_text(encoding="utf-8").splitlines()
    except OSError:
        return None
    if len(lines) >= 8 and lines[6] == "yes" and lines[7] != "-":
        return Path(lines[7])
    return CONFIG_DIR / ".backup-key.txt"


def pgpass_escape(value):
    return str(value).replace("\\", "\\\\").replace(":", "\\:")


def write_pgpass(database, path):
    values = (
        database.get("HOST") or "",
        database.get("PORT") or "5432",
        database["NAME"],
        database.get("USER") or "",
        database.get("PASSWORD") or "",
    )
    path.write_text(":".join(pgpass_escape(value) for value in values) + "\n")
    path.chmod(0o600)


def run_restore():
    if len(arguments) > 1:
        fail("Aufruf: ./restore_db.sh [Pfad-zum-Backup]")
    if arguments:
        supplied_path = arguments[0]
    elif sys.stdin.isatty():
        supplied_path = input("Pfad zur Backup-Datei: ").strip()
    else:
        fail("Bitte den Backup-Pfad als Argument angeben.")
    if not supplied_path:
        fail("Es wurde kein Backup-Pfad angegeben.")

    backup_path = Path(supplied_path).expanduser().resolve(strict=True)
    if not backup_path.is_file():
        fail(f"Die Backup-Datei ist keine reguläre Datei: {backup_path}")

    database = settings.DATABASES["default"]
    engine = database["ENGINE"]
    if engine == "django.db.backends.sqlite3":
        expected_suffix = ".sqlite3"
        configured_target = Path(database["NAME"]).expanduser()
        if configured_target.is_symlink():
            fail(f"Die konfigurierte SQLite-Datenbank ist ein Symlink: {configured_target}")
        target_path = configured_target.resolve()
        if target_path.exists() and not target_path.is_file():
            fail(f"Das SQLite-Ziel ist keine reguläre Datei: {target_path}")
        if target_path == backup_path:
            fail("Backup-Datei und SQLite-Zieldatenbank dürfen nicht identisch sein.")
    elif engine == "django.db.backends.postgresql":
        expected_suffix = ".dump"
    else:
        fail(f"Nicht unterstützter Datenbanktyp: {engine}")

    encrypted = backup_path.name.endswith(expected_suffix + ".gpg")
    plain = backup_path.name.endswith(expected_suffix)
    if not (encrypted or plain):
        fail(
            f"Das Backup passt nicht zur konfigurierten Datenbank "
            f"({engine}; erwartet *{expected_suffix} oder *{expected_suffix}.gpg)."
        )

    with tempfile.TemporaryDirectory(prefix="schulverwaltung-restore-") as temp_name:
        temp_dir = Path(temp_name)
        temp_dir.chmod(0o700)
        if encrypted:
            gpg = shutil.which("gpg")
            if not gpg:
                fail("GPG ist für die Entschlüsselung nicht installiert.")
            configured_key = default_key_file()
            prompt = "Pfad zur Schlüsseldatei"
            if configured_key:
                prompt += f" [Enter für {configured_key}]"
            prompt += ": "
            if sys.stdin.isatty():
                requested_key = input(prompt).strip()
            else:
                fail("Für ein verschlüsseltes Backup muss der Schlüssel interaktiv angegeben werden.")
            key_file = (
                Path(requested_key).expanduser()
                if requested_key
                else configured_key
            )
            if key_file is None:
                fail("Kein Pfad zur Schlüsseldatei angegeben.")
            key_file = key_file.resolve(strict=True)
            if not key_file.is_file() or not os.access(key_file, os.R_OK):
                fail(f"Schlüsseldatei nicht lesbar: {key_file}")
            if key_file.stat().st_mode & 0o077:
                fail(f"Schlüsseldatei ist nicht ausreichend geschützt; erwartet werden Rechte 600: {key_file}")

            gpg_home = temp_dir / "gnupg"
            gpg_home.mkdir(mode=0o700)
            backup_file = temp_dir / backup_path.name.removesuffix(".gpg")
            subprocess.run(
                [
                    gpg,
                    "--homedir",
                    str(gpg_home),
                    "--batch",
                    "--no-symkey-cache",
                    "--pinentry-mode",
                    "loopback",
                    "--passphrase-file",
                    str(key_file),
                    "--output",
                    str(backup_file),
                    "--decrypt",
                    str(backup_path),
                ],
                check=True,
            )
        else:
            backup_file = backup_path

        if engine == "django.db.backends.sqlite3" and backup_file == target_path:
            fail("Backup-Datei und SQLite-Zieldatenbank dürfen nicht identisch sein.")

        if backup_file.stat().st_size == 0:
            fail("Die Backup-Datei ist leer.")

        if engine == "django.db.backends.sqlite3":
            source_uri = backup_file.as_uri() + "?mode=ro"
            with sqlite3.connect(source_uri, uri=True) as source:
                integrity = source.execute("PRAGMA integrity_check").fetchone()
                if not integrity or integrity[0] != "ok":
                    fail(f"SQLite-Integritätsprüfung fehlgeschlagen: {integrity}")
                table_count = source.execute(
                    "SELECT count(*) FROM sqlite_master WHERE type = 'table'"
                ).fetchone()[0]
            if table_count == 0:
                fail("Das SQLite-Backup enthält keine Tabellen.")
        else:
            pg_restore = shutil.which("pg_restore")
            if not pg_restore:
                fail("pg_restore ist nicht installiert; installiere die PostgreSQL-Client-Werkzeuge.")
            listing = subprocess.run(
                [pg_restore, "--list", str(backup_file)],
                check=True,
                capture_output=True,
                text=True,
            ).stdout
            if not listing.strip():
                fail("Das PostgreSQL-Backup enthält keine wiederherstellbaren Einträge.")

        print(f"\nBackup-Datei: {backup_path}")
        if engine == "django.db.backends.sqlite3":
            print(f"Zieldatenbank: SQLite {target_path}")
            print(f"Tabellen im Backup: {table_count}")
        else:
            print(f"Zieldatenbank: PostgreSQL {database['NAME']} auf {database.get('HOST') or 'lokalem Socket'}")
            print("Das Backup wird mit pg_restore --clean in diese Datenbank eingespielt.")
        print("Das aktuelle Datenbank-Backup wird vor der Wiederherstellung gesichert.")
        if sys.stdin.isatty():
            confirmation = input(
                "Zum Fortfahren exakt WIEDERHERSTELLEN eingeben: "
            )
        else:
            fail("Die Wiederherstellung erfordert eine interaktive Bestätigung.")
        if confirmation != "WIEDERHERSTELLEN":
            fail("Wiederherstellung abgebrochen.")

        backup_script = project_dir / "backup_db.sh"
        if not backup_script.is_file():
            fail(f"Backup-Skript nicht gefunden: {backup_script}")
        print("\nErstelle Sicherheitsbackup des aktuellen Datenstands ...", flush=True)
        subprocess.run([str(backup_script), "--backup-now"], check=True)

        systemctl = shutil.which("systemctl")
        service_was_active = False
        if systemctl:
            service_status = subprocess.run(
                [systemctl, "is-active", "--quiet", SERVICE_NAME],
                check=False,
            )
            service_was_active = service_status.returncode == 0
        if service_was_active:
            print(f"Stoppe {SERVICE_NAME} ...", flush=True)
            command = [systemctl, "stop", SERVICE_NAME]
            if os.geteuid() != 0:
                sudo = shutil.which("sudo")
                if not sudo:
                    fail("sudo wird benötigt, um den laufenden Anwendungsdienst anzuhalten.")
                command.insert(0, sudo)
            subprocess.run(command, check=True)

        restore_succeeded = False
        try:
            if engine == "django.db.backends.sqlite3":
                target_path.parent.mkdir(parents=True, exist_ok=True)
                existing_stat = (
                    target_path.stat()
                    if target_path.exists()
                    else None
                )
                fd, temporary_target_name = tempfile.mkstemp(
                    prefix=f".{target_path.name}.restore-",
                    dir=target_path.parent,
                )
                temporary_target = Path(temporary_target_name)
                try:
                    with os.fdopen(fd, "wb") as target, backup_file.open("rb") as source:
                        shutil.copyfileobj(source, target)
                        target.flush()
                        os.fsync(target.fileno())
                    if existing_stat:
                        os.chmod(temporary_target, stat.S_IMODE(existing_stat.st_mode))
                        os.chown(
                            temporary_target,
                            existing_stat.st_uid,
                            existing_stat.st_gid,
                        )
                    else:
                        temporary_target.chmod(0o600)
                    with sqlite3.connect(
                        f"file:{temporary_target}?mode=ro",
                        uri=True,
                    ) as restored_db:
                        result = restored_db.execute("PRAGMA integrity_check").fetchone()
                        if not result or result[0] != "ok":
                            fail(f"SQLite-Prüfung der Wiederherstellungsdatei fehlgeschlagen: {result}")
                    if existing_stat:
                        connection = sqlite3.connect(str(target_path))
                        try:
                            checkpoint = connection.execute(
                                "PRAGMA wal_checkpoint(TRUNCATE)"
                            ).fetchone()
                            if checkpoint and checkpoint[0] != 0:
                                fail("SQLite-WAL konnte vor der Wiederherstellung nicht vollständig geschrieben werden.")
                        finally:
                            connection.close()
                    os.replace(temporary_target, target_path)
                finally:
                    if temporary_target.exists():
                        temporary_target.unlink()

                for suffix in ("-wal", "-shm"):
                    sidecar = Path(str(target_path) + suffix)
                    if sidecar.exists():
                        if sidecar.is_symlink() or not sidecar.is_file():
                            fail(f"Unerwartete SQLite-Sidecar-Datei: {sidecar}")
                        sidecar.unlink()
            else:
                pg_restore = shutil.which("pg_restore")
                pgpass_file = temp_dir / "pgpass"
                write_pgpass(database, pgpass_file)
                environment = os.environ.copy()
                environment.pop("PGPASSWORD", None)
                environment["PGPASSFILE"] = str(pgpass_file)
                command = [
                    pg_restore,
                    "--no-password",
                    "--single-transaction",
                    "--clean",
                    "--if-exists",
                    "--no-owner",
                    "--no-privileges",
                    "--exit-on-error",
                    f"--host={database.get('HOST') or ''}",
                    f"--port={database.get('PORT') or '5432'}",
                    f"--username={database.get('USER') or ''}",
                    f"--dbname={database['NAME']}",
                    str(backup_file),
                ]
                subprocess.run(command, check=True, env=environment)

            connections.close_all()
            connection = connections["default"]
            connection.ensure_connection()
            with connection.cursor() as cursor:
                cursor.execute("SELECT 1")
                if cursor.fetchone()[0] != 1:
                    fail("Die wiederhergestellte Datenbank besteht die Verbindungskontrolle nicht.")
            restore_succeeded = True
            print("Wiederherstellung und Datenbank-Verbindung erfolgreich geprüft.")
        finally:
            connections.close_all()
            if service_was_active:
                if restore_succeeded:
                    print(f"Starte {SERVICE_NAME} ...", flush=True)
                    command = [systemctl, "start", SERVICE_NAME]
                    if os.geteuid() != 0:
                        command.insert(0, shutil.which("sudo"))
                    subprocess.run(command, check=True)
                else:
                    print(
                        f"Die Wiederherstellung war nicht erfolgreich. {SERVICE_NAME} "
                        "bleibt vorsichtshalber gestoppt; Datenbank prüfen und Dienst "
                        "danach manuell starten.",
                        file=sys.stderr,
                    )


if __name__ == "__main__":
    try:
        run_restore()
    except (OSError, RuntimeError, subprocess.CalledProcessError, sqlite3.Error) as error:
        print(f"Fehler: {error}", file=sys.stderr)
        sys.exit(1)
PY
)" "$PROJECT_DIR" "$@"
