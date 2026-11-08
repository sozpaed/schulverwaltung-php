<?php

declare(strict_types=1);

use Schulverwaltung\AdmissionLetters;

require_once __DIR__ . '/../src/AdmissionLetters.php';
require_once __DIR__ . '/../src/AdmissionLetterPdf.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$statusFilters = AdmissionLetters::validateFilters('gymnasium', 'status', 'angenommen');
expect($statusFilters['school_label'] === 'Gymnasium', 'Schulform wurde nicht korrekt validiert.');
expect($statusFilters['filter_label'] === 'Angenommen', 'Status wurde nicht korrekt validiert.');

$waitlistFilters = AdmissionLetters::validateFilters('foerderstufe', 'warteliste', 'ja');
expect($waitlistFilters['filter_label'] === 'Warteliste (Ja)', 'Warteliste wurde nicht korrekt validiert.');

try {
    AdmissionLetters::validateFilters('gymnasium', 'status', 'unbekannt');
    expect(false, 'Ein ungültiger Status wurde akzeptiert.');
} catch (InvalidArgumentException) {
}

$rows = [
    [
        'id' => 1,
        'vorname' => 'Anna',
        'nachname' => 'Beispiel',
        'strasse' => 'Schulstraße 1',
        'plz' => '63071',
        'ort' => 'Offenbach',
        'guardian_id' => 10,
        'guardian_vorname' => 'Mara',
        'guardian_nachname' => 'Beispiel',
        'adresse_wie_schuelerin' => 1,
        'guardian_strasse' => '',
        'guardian_plz' => null,
        'guardian_ort' => '',
    ],
    [
        'id' => 1,
        'vorname' => 'Anna',
        'nachname' => 'Beispiel',
        'strasse' => 'Schulstraße 1',
        'plz' => '63071',
        'ort' => 'Offenbach',
        'guardian_id' => 11,
        'guardian_vorname' => 'Paul',
        'guardian_nachname' => 'Beispiel',
        'adresse_wie_schuelerin' => 0,
        'guardian_strasse' => 'Schulstraße 1',
        'guardian_plz' => '63071',
        'guardian_ort' => 'Offenbach',
    ],
    [
        'id' => 1,
        'vorname' => 'Anna',
        'nachname' => 'Beispiel',
        'strasse' => 'Schulstraße 1',
        'plz' => '63071',
        'ort' => 'Offenbach',
        'guardian_id' => 12,
        'guardian_vorname' => 'Lea',
        'guardian_nachname' => 'Andere',
        'adresse_wie_schuelerin' => 0,
        'guardian_strasse' => 'Nebenstraße 2',
        'guardian_plz' => '63071',
        'guardian_ort' => 'Offenbach',
    ],
];

$recipients = AdmissionLetters::groupRecipients($rows);
expect(count($recipients) === 2, 'Gemeinsame Adressen wurden nicht gebündelt.');
expect(count($recipients[0]['recipients']) === 2, 'Empfänger mit gleicher Adresse wurden nicht zusammengeführt.');
expect($recipients[1]['address'][0] === 'Nebenstraße 2', 'Eine separate Erziehungsberechtigtenadresse wurde ignoriert.');

$schoolName = 'Beispielschule';
$accepted = AdmissionLetters::letterContent($statusFilters, 'Anna Beispiel', $schoolName);
$waiting = AdmissionLetters::letterContent($waitlistFilters, 'Anna Beispiel', $schoolName);
expect(str_contains($accepted['paragraphs'][0], 'aufnehmen'), 'Der Beispieltext für angenommene Schülerinnen fehlt.');
expect(str_contains($waiting['paragraphs'][0], 'Warteliste'), 'Der Beispieltext für die Warteliste fehlt.');
expect(str_contains($accepted['paragraphs'][0], $schoolName), 'Der konfigurierte Schulname fehlt im Brieftext.');
$gymnasiumStatus = AdmissionLetters::validateFilters('gymnasium', 'status', 'in_pruefung');
expect(
    str_contains(AdmissionLetters::letterContent($gymnasiumStatus, 'Anna Beispiel', $schoolName)['paragraphs'][0], 'für das Gymnasium'),
    'Die Schulform Gymnasium wurde im Mustertext grammatikalisch nicht angepasst.'
);

$settings = [
    'school_name' => $schoolName,
    'head_name' => 'Alex Beispiel',
    'street' => 'Beispielstraße 1',
    'postal_code' => '12345',
    'city' => 'Beispielstadt',
    'phone' => '012345',
    'email' => 'schule@example.test',
    'website' => 'example.test',
    'logo_data' => null,
    'logo_type' => null,
];
$pdf = new Schulverwaltung\AdmissionLetterPdf($settings);
$pdf->SetCompression(false);
$pdf->addLetter($recipients[0] + ['student_name' => 'Anna Beispiel'], $statusFilters);
$document = $pdf->Output('S');
expect(str_starts_with($document, '%PDF-'), 'Die PDF-Ausgabe hat keinen gültigen PDF-Kopf.');
expect(str_contains($document, '%%EOF'), 'Die PDF-Ausgabe wurde nicht vollständig abgeschlossen.');
expect(str_contains($document, 'Beispielschule'), 'Der konfigurierte Schulname fehlt im PDF.');
expect(str_contains($document, 'Alex Beispiel'), 'Die konfigurierte Schulleitung fehlt im PDF.');
expect(str_contains($document, 'schule@example.test'), 'Die konfigurierte E-Mail fehlt im PDF.');
expect(!str_contains($document, 'Tumbrink'), 'Eine alte Schulleitung ist noch im PDF enthalten.');

$settings['logo_data'] = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0yQAAAAASUVORK5CYII=';
$settings['logo_type'] = 'PNG';
$pdfWithLogo = new Schulverwaltung\AdmissionLetterPdf($settings);
$pdfWithLogo->addLetter($recipients[0], $statusFilters);
$pdfWithLogo->addLetter($recipients[1], $statusFilters);
$logoDocument = $pdfWithLogo->Output('S');
expect(str_starts_with($logoDocument, '%PDF-'), 'PDF mit konfiguriertem Logo konnte nicht erzeugt werden.');
expect(substr_count($logoDocument, '/Subtype /Image') === 2, 'Das Logo wurde auf mehreren Seiten nicht wiederverwendet.');

fwrite(STDOUT, "Serienbrief-Filter, Empfängergruppen und Mustertexte erfolgreich geprüft." . PHP_EOL);
