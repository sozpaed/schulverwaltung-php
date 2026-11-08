<?php

declare(strict_types=1);

use Schulverwaltung\AdmissionLetterPdf;
use Schulverwaltung\AdmissionLetters;

require_once __DIR__ . '/../src/AdmissionLetters.php';
require_once __DIR__ . '/../src/AdmissionLetterPdf.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Für diesen Test wird die PDO-SQLite-Erweiterung benötigt.\n");
    exit(1);
}

function expectSelection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec("CREATE TABLE schueler (
    id INTEGER PRIMARY KEY, vorname TEXT, nachname TEXT, schulform TEXT,
    strasse TEXT, plz TEXT, ort TEXT, status TEXT, warteliste INTEGER
)");
$database->exec("CREATE TABLE erziehungsberechtigte (
    id INTEGER PRIMARY KEY, schueler_id INTEGER, vorname TEXT, nachname TEXT, beziehung TEXT,
    adresse_wie_schuelerin INTEGER, strasse TEXT, plz TEXT, ort TEXT, erziehungsberechtigt INTEGER
)");
$student = $database->prepare('INSERT INTO schueler VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 'Anna', 'A', '', 'Weg 1', '12345', 'Ort', 'angenommen', 1],
    [2, 'Bea', 'B', 'foerderstufe', 'Weg 2', '12345', 'Ort', 'angenommen', 1],
    [3, 'Clara', 'C', 'gymnasium', 'Weg 3', '12345', 'Ort', 'angenommen', 1],
    [4, 'Dora', 'D', 'gymnasium', 'Weg 4', '12345', 'Ort', 'abgelehnt', 0],
    [5, 'Eva', 'E', '', 'Weg 5', '12345', 'Ort', 'abgelehnt', 0],
] as $row) {
    $student->execute($row);
}
$database->exec("INSERT INTO erziehungsberechtigte VALUES
    (1, 2, 'Person', 'Eins', 'vater', 1, '', NULL, '', 1),
    (2, 2, 'Person', 'Zwei', 'mutter', 1, '', NULL, '', 1),
    (3, 3, 'Person', 'Drei', 'mutter', 1, '', NULL, '', 0)");

foreach ([
    ['keine', 'status', 'angenommen', [1]],
    ['beide', 'status', 'angenommen', [2, 3]],
    ['foerderstufe', 'status', 'angenommen', [2]],
    ['gymnasium', 'status', 'angenommen', [3]],
    ['keine', 'status', 'abgelehnt', [5]],
    ['beide', 'status', 'abgelehnt', [4]],
    ['keine', 'warteliste', 'ja', [1]],
    ['beide', 'warteliste', 'ja', [2, 3]],
] as [$schoolForm, $type, $value, $expectedIds]) {
    $filters = AdmissionLetters::validateFilters($schoolForm, $type, $value);
    $rows = AdmissionLetters::findRecipients($database, $filters);
    $ids = array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
    expectSelection($ids === $expectedIds, "Falsche Auswahl für $schoolForm / $type / $value.");
    $recipients = AdmissionLetters::groupRecipients($rows);
    expectSelection(count($recipients) === count($expectedIds), "Falsche Briefanzahl für $schoolForm / $type / $value.");
}

$filters = AdmissionLetters::validateFilters('beide', 'status', 'angenommen');
$recipients = AdmissionLetters::groupRecipients(AdmissionLetters::findRecipients($database, $filters));
expectSelection(count($recipients[0]['recipients']) === 2, 'Gemeinsame Empfänger wurden nicht gebündelt.');
expectSelection($recipients[0]['salutations'] === ['Sehr geehrte Frau Zwei,', 'Sehr geehrter Herr Eins,'], 'Die Beziehungen werden nicht aus der Datenbank übernommen.');
expectSelection($recipients[1]['recipients'] === ['Erziehungsberechtigte der Schülerin Clara C'], 'Fallback ohne Erziehungsberechtigte fehlt.');
expectSelection($recipients[1]['salutations'] === ['Sehr geehrte Erziehungsberechtigte,'], 'Die neutrale Anrede ohne Erziehungsberechtigte fehlt.');
$settings = [
    'school_name' => 'Beispielschule', 'head_name' => 'Beispiel',
    'street' => '', 'postal_code' => '', 'city' => '', 'phone' => '',
    'email' => '', 'website' => '', 'logo_data' => null, 'logo_type' => null,
];
$pdf = new AdmissionLetterPdf($settings);
$pdf->SetCompression(false);
foreach ($recipients as $recipient) {
    $letterFilters = AdmissionLetters::filtersForRecipient($filters, $recipient);
    $pdf->addLetter($recipient, $letterFilters);
}
$document = $pdf->Output('S');
expectSelection(str_contains($document, 'die F' . chr(0xf6) . 'rderstufe'), 'Der Förderstufenbrief fehlt.');
expectSelection(str_contains($document, 'das Gymnasium'), 'Der Gymnasialbrief fehlt.');

$filters = AdmissionLetters::validateFilters('keine', 'warteliste', 'ja');
$recipients = AdmissionLetters::groupRecipients(AdmissionLetters::findRecipients($database, $filters));
$letterFilters = AdmissionLetters::filtersForRecipient($filters, $recipients[0]);
expectSelection($letterFilters['school_form'] === 'keine', 'Eine leere Schulform wird nicht für den Brief umgesetzt.');
$pdf = new AdmissionLetterPdf($settings);
$pdf->SetCompression(false);
$pdf->addLetter($recipients[0], $letterFilters);
$document = $pdf->Output('S');
expectSelection(str_contains($document, 'Warteliste'), 'Der Wartelistenbrief ohne Schulform fehlt.');
expectSelection(!str_contains($document, 'Gymnasium') && !str_contains($document, 'F' . chr(0xf6) . 'rderstufe'), 'Der Brief ohne Schulform enthält eine falsche Schulform.');

fwrite(STDOUT, "Serienbrief-Auswahl und schulformspezifische PDFs erfolgreich geprüft.\n");
