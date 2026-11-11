<?php

declare(strict_types=1);

use Schulverwaltung\InstallToken;

require_once __DIR__ . '/../src/InstallToken.php';

$path = tempnam(sys_get_temp_dir(), 'install-token-');
if ($path === false) {
    throw new RuntimeException('Temporäre Token-Datei konnte nicht angelegt werden.');
}

try {
    if (file_put_contents($path, "random-test-token\r\n") === false) {
        throw new RuntimeException('Temporäre Token-Datei konnte nicht beschrieben werden.');
    }
    if (InstallToken::load($path) !== 'random-test-token') {
        throw new RuntimeException('Token-Datei wurde nicht korrekt gelesen.');
    }
    if (!InstallToken::matches(InstallToken::load($path), 'random-test-token')) {
        throw new RuntimeException('Ein korrektes Token wurde abgewiesen.');
    }
    if (InstallToken::matches(InstallToken::load($path), 'wrong-token')) {
        throw new RuntimeException('Ein falsches Token wurde akzeptiert.');
    }
    if (InstallToken::matches(InstallToken::load($path), ['random-test-token'])) {
        throw new RuntimeException('Ein nicht-zeichenkettenförmiges Token wurde akzeptiert.');
    }

    if (file_put_contents($path, "\r\n") === false || InstallToken::load($path) !== null) {
        throw new RuntimeException('Eine leere Token-Datei wurde als gültig behandelt.');
    }
    if (!unlink($path) || InstallToken::load($path) !== null) {
        throw new RuntimeException('Eine fehlende Token-Datei wurde als gültig behandelt.');
    }
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

fwrite(STDOUT, "Installations-Token erfolgreich geprüft.\n");
