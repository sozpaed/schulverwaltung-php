<?php

declare(strict_types=1);

use Schulverwaltung\Database;
use Schulverwaltung\LoginThrottle;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile ausführbar.');
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/LoginThrottle.php';

$database = Database::connect();
$database->exec(
    'CREATE TEMPORARY TABLE login_versuche (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        benutzername VARCHAR(150) NOT NULL,
        benutzer_id BIGINT UNSIGNED NULL,
        ip VARBINARY(16) NOT NULL,
        erfolgreich TINYINT(1) NOT NULL,
        grund VARCHAR(32) NULL,
        erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (benutzername, erstellt_am),
        KEY (ip, erstellt_am)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

function assertCounts(LoginThrottle $throttle, int $users, int $ips): void
{
    $actual = $throttle->failureCounts();
    if ($actual !== [$users, $ips]) {
        throw new RuntimeException(sprintf(
            'Erwartet: %d/%d Fehlversuche, erhalten: %d/%d.',
            $users, $ips, $actual[0], $actual[1]
        ));
    }
}

$name = 'Test-' . bin2hex(random_bytes(6));
$ip = '2001:db8::1';
$throttle = new LoginThrottle($database, $name, $ip);
try {
    for ($i = 0; $i < LoginThrottle::USER_LIMIT; $i++) {
        assertCounts($throttle, $i, $i);
        $throttle->record(null, false, 'passwort');
    }
    assertCounts($throttle, 5, 5);
    $throttle->record(null, false, 'gesperrt');
    $throttle->record(null, true, null);
    assertCounts($throttle, 5, 5);
    $database->exec('UPDATE login_versuche SET erstellt_am = NOW() - INTERVAL 16 MINUTE WHERE id = 1');
    assertCounts($throttle, 4, 4);
} finally {
    $throttle->release();
}

$throttle = new LoginThrottle($database, strtoupper($name), '2001:db8::2');
try {
    assertCounts($throttle, 4, 0);
} finally {
    $throttle->release();
}

$database->exec('DELETE FROM login_versuche');
for ($i = 0; $i < LoginThrottle::IP_LIMIT; $i++) {
    $throttle = new LoginThrottle($database, $name . $i, $ip);
    try {
        assertCounts($throttle, 0, $i);
        $throttle->record(null, false, 'passwort');
    } finally {
        $throttle->release();
    }
}
$throttle = new LoginThrottle($database, $name . '-new', $ip);
try {
    assertCounts($throttle, 0, 20);
} finally {
    $throttle->release();
}
$database->exec('UPDATE login_versuche SET erstellt_am = NOW() - INTERVAL 91 DAY ORDER BY id LIMIT 1');
if (LoginThrottle::cleanup($database) !== 1) {
    throw new RuntimeException('Alte Anmeldeversuche wurden nicht korrekt gelöscht.');
}

fwrite(STDOUT, "Anmeldesperren und Aufbewahrung erfolgreich geprüft.\n");
