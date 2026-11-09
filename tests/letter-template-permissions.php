<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\PermissionGroups;

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
    can_export INTEGER, can_edit_letter_templates INTEGER, can_view_maintenance INTEGER,
    can_view_statistics INTEGER, can_delete_catalogs INTEGER, can_delete_students INTEGER,
    totp_enabled INTEGER, sitzungs_version INTEGER
)');
$db->exec("INSERT INTO app_users VALUES (1, 'editor', 1, 0, 0, 1, 0, 0, 0, 0, 0, 0)");
$db->exec('CREATE TABLE berechtigungsgruppen (
    id INTEGER PRIMARY KEY, name TEXT UNIQUE,
    can_export INTEGER, can_edit_letter_templates INTEGER, can_view_maintenance INTEGER,
    can_view_statistics INTEGER, can_delete_catalogs INTEGER, can_delete_students INTEGER
)');
$db->exec('CREATE TABLE benutzer_berechtigungsgruppen (
    benutzer_id INTEGER, gruppe_id INTEGER, PRIMARY KEY (benutzer_id, gruppe_id)
)');
$db->exec("INSERT INTO berechtigungsgruppen VALUES
    (1, 'Export', 1, 0, 0, 0, 0, 0),
    (2, 'Statistik', 0, 0, 0, 1, 0, 0)");
$db->exec('INSERT INTO benutzer_berechtigungsgruppen VALUES (1, 1), (1, 2)');
session_start();
$now = time();
$_SESSION['user'] = [
    'id' => 1, 'sitzungs_version' => 0, 'angemeldet_am' => $now,
    'letzte_aktivitaet' => $now, 'erneuert_am' => $now,
];
$user = Auth::user($db);
expectPermission(
    $user !== null && $user['can_edit_letter_templates'] && $user['can_export']
        && $user['can_view_statistics'] && !$user['is_superuser'],
    'Direkte und Gruppenrechte wurden nicht additiv ermittelt.'
);
$db->exec('DELETE FROM benutzer_berechtigungsgruppen WHERE gruppe_id = 1');
expectPermission(!Auth::user($db)['can_export'], 'Ein entfernter Gruppen-Zugriff blieb aktiv.');
$db->exec('UPDATE app_users SET can_view_maintenance = 1 WHERE id = 1');
expectPermission(Auth::user($db)['can_view_maintenance'], 'Das Wartungsrecht wurde nicht geladen.');
$db->exec('UPDATE app_users SET can_view_statistics = 1 WHERE id = 1');
expectPermission(Auth::user($db)['can_view_statistics'], 'Das Statistikrecht wurde nicht geladen.');
$db->exec('UPDATE app_users SET can_edit_letter_templates = 0 WHERE id = 1');
$user = Auth::user($db);
expectPermission($user !== null && !$user['can_edit_letter_templates'], 'Ein entzogenes Editorrecht bleibt aktiv.');
$db->exec('UPDATE app_users SET sitzungs_version = 1 WHERE id = 1');
expectPermission(Auth::user($db) === null, 'Die Sitzung wurde nach einer Rechteänderung nicht widerrufen.');
expectPermission(PermissionGroups::validateSelection($db, ['2']) === [2], 'Eine gültige Gruppenauswahl wurde nicht akzeptiert.');
try {
    PermissionGroups::validateSelection($db, ['999']);
    expectPermission(false, 'Eine nicht vorhandene Gruppe wurde akzeptiert.');
} catch (InvalidArgumentException) {
}
expectPermission(PermissionGroups::validateName(' Redaktion ') === 'Redaktion', 'Der Gruppenname wurde nicht normalisiert.');
expectPermission(
    PermissionGroups::validateRights(['can_export' => '1'])['can_export'] === 1,
    'Ein gültiges Gruppenrecht wurde nicht akzeptiert.'
);
fwrite(STDOUT, "Gruppen- und Serienbriefeditor-Berechtigungen erfolgreich geprüft.\n");
