<?php

declare(strict_types=1);

use Schulverwaltung\Database;

require_once __DIR__ . '/../src/Database.php';

$versions = [
    '10.5.0-MariaDB' => 'mariadb',
    '11.8.2-MariaDB' => 'mariadb',
    '5.5.5-10.11.6-MariaDB-1' => 'mariadb',
    '10.4.99-MariaDB' => null,
    '8.4.0' => 'mysql',
    '8.4.11-11' => 'mysql',
    '8.4.11-commercial' => 'mysql',
    '8.0.40' => null,
    '9.0.0' => null,
    '8.4.11-MariaDB' => null,
    '8.4.11;DROP' => null,
    'unknown' => null,
];

foreach ($versions as $version => $expected) {
    $actual = Database::supportedServerType($version);
    if ($actual !== $expected) {
        fwrite(STDERR, sprintf("Unerwarteter Servertyp für %s: %s\n", $version, var_export($actual, true)));
        exit(1);
    }
}

fwrite(STDOUT, "Datenbankversionen erfolgreich geprüft.\n");
