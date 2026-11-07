<?php

declare(strict_types=1);

use Schulverwaltung\GuardianCsvImport;

require_once __DIR__ . '/../src/GuardianCsvImport.php';

function expectGuardianCsvImport(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$header = 'E-Mail-Adresse Erziehungsberechtigter;Vorname Erziehungsberechtigter;Nachname Erziehungsberechtigter;Mobil Erziehungsberechtigter;Straße und Hausnummer Erziehungsberechtigter;PLZ Erziehungsberechtigter;Wohnort Erziehungsberechtigter;Vorname Schülerin;Nachname Schülerin;Beziehung';
$csv = "\xEF\xBB\xBF" . $header . "\r\n"
    . '"mutter@example.test";Anna;Muster;+49170123456;"Musterstraße 1";01234;Berlin;Lena;Beispiel;Mutter';
$parsed = GuardianCsvImport::parse($csv);
expectGuardianCsvImport($parsed['headerErrors'] === [], 'Eine gültige CSV-Datei wurde abgelehnt.');
expectGuardianCsvImport(count($parsed['rows']) === 1, 'Der CSV-Datensatz wurde nicht erkannt.');
expectGuardianCsvImport($parsed['rows'][0]['errors'] === [], 'Ein gültiger Datensatz erhielt Fehler.');
expectGuardianCsvImport($parsed['rows'][0]['values']['beziehung'] === 'mutter', 'Die Beziehung wurde nicht normalisiert.');
expectGuardianCsvImport($parsed['rows'][0]['values']['plz'] === '01234', 'Die führende Null der PLZ ging verloren.');
$studentGroup = GuardianCsvImport::studentGroupKey($parsed['rows'][0]['values']);
$sameStudent = $parsed['rows'][0]['values'];
$sameStudent['vorname'] = 'Andere Kontaktperson';
expectGuardianCsvImport(
    GuardianCsvImport::studentGroupKey($sameStudent) === $studentGroup,
    'Gleiche Schülerinnen-Namen wurden nicht gruppiert.'
);
$differentStudent = $sameStudent;
$differentStudent['schuelerin_nachname'] = 'Anders';
expectGuardianCsvImport(
    GuardianCsvImport::studentGroupKey($differentStudent) !== $studentGroup,
    'Unterschiedliche Schülerinnen-Namen wurden gruppiert.'
);
$addressRow = [$parsed['rows'][0]['values'], '12'];
expectGuardianCsvImport(
    GuardianCsvImport::addressesAreConsistent([$addressRow, $addressRow]),
    'Gleiche Adressen derselben Schülerin wurden als widersprüchlich erkannt.'
);
$differentAddress = $parsed['rows'][0]['values'];
$differentAddress['strasse'] = 'Andere Straße 2';
expectGuardianCsvImport(
    !GuardianCsvImport::addressesAreConsistent([
        $addressRow,
        [$differentAddress, '12'],
    ]),
    'Widersprüchliche Adressen derselben Schülerin wurden akzeptiert.'
);
expectGuardianCsvImport(
    GuardianCsvImport::addressesAreConsistent([
        $addressRow,
        [$differentAddress, '13'],
    ]),
    'Adressen unterschiedlicher Schülerinnen wurden verglichen.'
);

$invalidRelationship = GuardianCsvImport::parse(
    $header . "\n;Anna;Muster;123;;12345;Berlin;Lena;Beispiel;Onkel"
);
expectGuardianCsvImport(
    str_contains(implode(' ', $invalidRelationship['rows'][0]['errors']), 'Beziehung'),
    'Eine unbekannte Beziehung wurde akzeptiert.'
);

$missingHeader = GuardianCsvImport::parse("Vorname;Nachname\nAnna;Muster");
expectGuardianCsvImport($missingHeader['headerErrors'] !== [], 'Fehlende CSV-Spalten wurden nicht erkannt.');

$invalidUtf8 = GuardianCsvImport::parse($header . "\n\xFF");
expectGuardianCsvImport($invalidUtf8['headerErrors'] !== [], 'Ungültiges UTF-8 wurde akzeptiert.');

fwrite(STDOUT, "CSV-Importprüfung erfolgreich geprüft." . PHP_EOL);
