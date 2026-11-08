<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../src/SessionPolicy.php';
require_once __DIR__ . '/../src/Auth.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Für diesen Test wird die PDO-SQLite-Erweiterung benötigt.\n");
    exit(1);
}
function expectPermission(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE app_users (
    id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER,
    can_export INTEGER, can_edit_letter_templates INTEGER, can_delete_catalogs INTEGER,
    can_delete_students INTEGER, totp_enabled INTEGER, sitzungs_version INTEGER
)');
$db->exec("INSERT INTO app_users VALUES (1, 'editor', 1, 0, 0, 1, 0, 0, 0, 0)");
session_start();
$now = time();
$_SESSION['user'] = [
    'id' => 1, 'sitzungs_version' => 0, 'angemeldet_am' => $now,
    'letzte_aktivitaet' => $now, 'erneuert_am' => $now,
];
$user = Auth::user($db);
expectPermission($user !== null && $user['can_edit_letter_templates'] && !$user['can_export'] && !$user['is_superuser'], 'Das Editorrecht ist nicht unabhängig vom Export- und Administratorrecht.');
$db->exec('UPDATE app_users SET can_edit_letter_templates = 0 WHERE id = 1');
$user = Auth::user($db);
expectPermission($user !== null && !$user['can_edit_letter_templates'], 'Ein entzogenes Editorrecht bleibt aktiv.');
$db->exec('UPDATE app_users SET sitzungs_version = 1 WHERE id = 1');
expectPermission(Auth::user($db) === null, 'Die Sitzung wurde nach einer Rechteänderung nicht widerrufen.');
fwrite(STDOUT, "Serienbriefeditor-Berechtigungen erfolgreich geprüft.\n");
