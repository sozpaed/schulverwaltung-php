<?php

declare(strict_types=1);

use Schulverwaltung\Maintenance;

require_once __DIR__ . '/../src/Maintenance.php';

function expectMaintenance(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$studentFields = [
    'adresse', 'wohnort', 'geburtsdatum', 'geburtsort', 'religion',
    'grundschule', 'staatsangehoerigkeit', 'noten', 'schulform', 'klasse',
];
$guardianFields = [
    'adresse', 'wohnort', 'erziehungsberechtigt', 'religion',
    'staatsangehoerigkeit', 'email', 'telefon', 'klasse',
];
$students = Maintenance::studentDefinitions();
$guardians = Maintenance::guardianDefinitions();

expectMaintenance(array_keys($students) === $studentFields, 'Die Wartungsfelder der Schülerinnen sind unvollständig oder falsch sortiert.');
expectMaintenance(array_keys($guardians) === $guardianFields, 'Die Wartungsfelder der Erziehungsberechtigten sind unvollständig oder falsch sortiert.');
expectMaintenance(Maintenance::definition('schueler', 'noten') === $students['noten'], 'Der Schülerinnenfilter wurde nicht gefunden.');
expectMaintenance(
    str_contains($guardians['adresse']['condition'], 'e.adresse_wie_schuelerin'),
    'Der Adressfilter berücksichtigt die Übernahme der Schüleradresse nicht.'
);
expectMaintenance(Maintenance::definition('schueler', 'unbekannt') === null, 'Ein unbekannter Wartungsfilter wurde akzeptiert.');
expectMaintenance(Maintenance::definition('unbekannt', 'adresse') === null, 'Ein unbekannter Wartungsbereich wurde akzeptiert.');

foreach ([$students, $guardians] as $definitions) {
    foreach ($definitions as $definition) {
        expectMaintenance(!str_contains($definition['condition'], ';'), 'Ein Filterausdruck enthält ein Statement-Endezeichen.');
        expectMaintenance(isset($definition['label'], $definition['condition']), 'Ein Wartungsfilter ist unvollständig.');
    }
}

fwrite(STDOUT, "Wartungsfilter erfolgreich geprüft." . PHP_EOL);
