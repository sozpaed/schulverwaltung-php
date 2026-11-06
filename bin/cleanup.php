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

try {
    $deleted = LoginThrottle::cleanup(Database::connect());
    fwrite(STDOUT, sprintf("%d alte Anmeldeprotokolle gelöscht.\n", $deleted));
} catch (Throwable $exception) {
    fwrite(STDERR, "Bereinigung fehlgeschlagen: " . $exception->getMessage() . "\n");
    exit(1);
}
