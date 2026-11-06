<?php

declare(strict_types=1);

use Schulverwaltung\Database;
use Schulverwaltung\TwoFactorReset;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile ausführbar.');
}
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/TwoFactorReset.php';

$username = $argv[1] ?? '';
if ($username === '' || count($argv) !== 2) {
    fwrite(STDERR, "Aufruf: php bin/reset-2fa.php BENUTZERNAME\n");
    exit(1);
}
$database = Database::connect();
$statement = $database->prepare('SELECT id, username FROM app_users WHERE username = :username');
$statement->execute(['username' => $username]);
$user = $statement->fetch();
if ($user === false) {
    fwrite(STDERR, "Benutzer nicht gefunden.\n");
    exit(1);
}
fwrite(STDOUT, "2FA für {$user['username']} zurücksetzen? Die Anmeldung ist danach nur mit Passwort möglich. Tippe JA: ");
if (trim((string) fgets(STDIN)) !== 'JA') {
    fwrite(STDERR, "Abgebrochen.\n");
    exit(1);
}
try {
    TwoFactorReset::reset($database, (int) $user['id'], null, 'CLI');
    fwrite(STDOUT, "2FA wurde zurückgesetzt.\n");
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
