<?php

declare(strict_types=1);

use Schulverwaltung\SchoolSettings;

require_once __DIR__ . '/../src/SchoolSettings.php';

$settings = array_fill_keys(array_keys(SchoolSettings::FIELDS), '');
if (SchoolSettings::validate($settings) !== []) {
    throw new RuntimeException('Leere Schuleinstellungen müssen erlaubt sein.');
}
$settings['school_name'] = 'Beispielschule';
$settings['head_name'] = 'Alex Beispiel';
$settings['email'] = 'kontakt@example.test';
if (SchoolSettings::validate($settings) !== []) {
    throw new RuntimeException('Gültige Schuleinstellungen wurden abgelehnt.');
}
$settings['email'] = 'ungueltig';
if (SchoolSettings::validate($settings) === []) {
    throw new RuntimeException('Eine ungültige E-Mail-Adresse wurde akzeptiert.');
}
$settings['email'] = '';
$settings['school_name'] = "Schule\nFremdtext";
if (SchoolSettings::validate($settings) === []) {
    throw new RuntimeException('Steuerzeichen wurden akzeptiert.');
}
fwrite(STDOUT, "Schuleinstellungen erfolgreich geprüft.\n");
