<?php

declare(strict_types=1);

use Schulverwaltung\Database;
use Schulverwaltung\LetterTemplates;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AdmissionLetters.php';
require_once __DIR__ . '/../src/LetterTemplates.php';

if (PHP_SAPI !== 'cli') {
    exit('Dieses Skript darf nur über die Kommandozeile ausgeführt werden.');
}

LetterTemplates::initialize(Database::connect());
fwrite(STDOUT, "Serienbriefvorlagen initialisiert. Vorhandene Vorlagen wurden nicht überschrieben.\n");
