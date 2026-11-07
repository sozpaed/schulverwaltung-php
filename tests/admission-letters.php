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

$accepted = AdmissionLetters::letterContent($statusFilters, 'Anna Beispiel');
$waiting = AdmissionLetters::letterContent($waitlistFilters, 'Anna Beispiel');
expect(str_contains($accepted['paragraphs'][0], 'aufnehmen'), 'Der Beispieltext für angenommene Schülerinnen fehlt.');
expect(str_contains($waiting['paragraphs'][0], 'Warteliste'), 'Der Beispieltext für die Warteliste fehlt.');
$gymnasiumStatus = AdmissionLetters::validateFilters('gymnasium', 'status', 'in_pruefung');
expect(
    str_contains(AdmissionLetters::letterContent($gymnasiumStatus, 'Anna Beispiel')['paragraphs'][0], 'für das Gymnasium'),
    'Die Schulform Gymnasium wurde im Mustertext grammatikalisch nicht angepasst.'
);

$pdf = new Schulverwaltung\AdmissionLetterPdf(__DIR__ . '/../public/assets/marienschule-logo.png');
$pdf->addLetter($recipients[0] + ['student_name' => 'Anna Beispiel'], $statusFilters);
$document = $pdf->Output('S');
expect(str_starts_with($document, '%PDF-'), 'Die PDF-Ausgabe hat keinen gültigen PDF-Kopf.');
expect(str_contains($document, '%%EOF'), 'Die PDF-Ausgabe wurde nicht vollständig abgeschlossen.');

fwrite(STDOUT, "Serienbrief-Filter, Empfängergruppen und Mustertexte erfolgreich geprüft." . PHP_EOL);
