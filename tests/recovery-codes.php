<?php

declare(strict_types=1);

use Schulverwaltung\Database;
use Schulverwaltung\RecoveryCodes;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile ausführbar.');
}
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/RecoveryCodes.php';

$database = Database::connect();
$database->exec('CREATE TEMPORARY TABLE app_users (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY, is_active TINYINT NOT NULL,
    totp_enabled TINYINT NOT NULL, sitzungs_version INT UNSIGNED NOT NULL
) ENGINE=InnoDB');
$database->exec('CREATE TEMPORARY TABLE totp_wiederherstellungscodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    benutzer_id BIGINT UNSIGNED NOT NULL, code_hash VARCHAR(255) NOT NULL,
    verwendet_am DATETIME NULL
) ENGINE=InnoDB');
$database->exec('INSERT INTO app_users VALUES (1, 1, 1, 0)');
$database->beginTransaction();
$codes = RecoveryCodes::replace($database, 1);
$database->commit();
if (count($codes) !== 10 || count(array_unique($codes)) !== 10) {
    throw new RuntimeException('Es wurden nicht zehn unterschiedliche Codes erzeugt.');
}
foreach ($codes as $code) {
    if (preg_match('/\A[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{5}-[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{5}\z/', $code) !== 1) {
        throw new RuntimeException('Ungültiges Codeformat.');
    }
}
if (RecoveryCodes::consume($database, 1, 1, $codes[0])
    || !RecoveryCodes::consume($database, 1, 0, $codes[0])
    || RecoveryCodes::consume($database, 1, 0, $codes[0])) {
    throw new RuntimeException('Version oder einmalige Verwendung wurde nicht durchgesetzt.');
}
$database->beginTransaction();
$newCodes = RecoveryCodes::replace($database, 1);
$database->commit();
if (RecoveryCodes::consume($database, 1, 0, $codes[1])
    || !RecoveryCodes::consume($database, 1, 0, $newCodes[0])) {
    throw new RuntimeException('Die Erneuerung der Codes ist fehlgeschlagen.');
}
fwrite(STDOUT, "Wiederherstellungscodes erfolgreich geprüft.\n");
