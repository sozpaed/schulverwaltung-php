<?php

declare(strict_types=1);

use Schulverwaltung\AdmissionLetters;

require_once __DIR__ . '/../src/AdmissionLetters.php';
require_once __DIR__ . '/../src/AdmissionLetterPdf.php';

function defaultTemplate(array $filters): array
{
    $content = AdmissionLetters::letterContent($filters, '{{schuelerin}}', '{{schulname}}');
    return [
        'betreff' => $content['subject'],
        'inhalt' => implode('', array_map(
            static fn (string $paragraph): string => '<p>' . htmlspecialchars($paragraph, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</p>',
            $content['paragraphs']
        )),
    ];
}

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
        'schulform' => 'gymnasium',
        'klasse' => '5a',
        'strasse' => 'Schulstraße 1',
        'plz' => '63071',
        'ort' => 'Offenbach',
        'guardian_id' => 10,
        'guardian_vorname' => 'Mara',
        'guardian_nachname' => 'Beispiel',
        'guardian_beziehung' => 'mutter',
        'adresse_wie_schuelerin' => 1,
        'guardian_strasse' => '',
        'guardian_plz' => null,
        'guardian_ort' => '',
    ],
    [
        'id' => 1,
        'vorname' => 'Anna',
        'nachname' => 'Beispiel',
        'schulform' => 'gymnasium',
        'klasse' => '5a',
        'strasse' => 'Schulstraße 1',
        'plz' => '63071',
        'ort' => 'Offenbach',
        'guardian_id' => 11,
        'guardian_vorname' => 'Paul',
        'guardian_nachname' => 'Beispiel',
        'guardian_beziehung' => 'vater',
        'adresse_wie_schuelerin' => 0,
        'guardian_strasse' => 'Schulstraße 1',
        'guardian_plz' => '63071',
        'guardian_ort' => 'Offenbach',
    ],
    [
        'id' => 1,
        'vorname' => 'Anna',
        'nachname' => 'Beispiel',
        'schulform' => 'gymnasium',
        'klasse' => '5a',
        'strasse' => 'Schulstraße 1',
        'plz' => '63071',
        'ort' => 'Offenbach',
        'guardian_id' => 12,
        'guardian_vorname' => 'Lea',
        'guardian_nachname' => 'Andere',
        'guardian_beziehung' => 'vormund',
        'adresse_wie_schuelerin' => 0,
        'guardian_strasse' => 'Nebenstraße 2',
        'guardian_plz' => '63071',
        'guardian_ort' => 'Offenbach',
    ],
];

$recipients = AdmissionLetters::groupRecipients($rows);
expect(count($recipients) === 2, 'Gemeinsame Adressen wurden nicht gebündelt.');
expect(count($recipients[0]['recipients']) === 2, 'Empfänger mit gleicher Adresse wurden nicht zusammengeführt.');
expect($recipients[0]['school_form'] === 'gymnasium', 'Die Schulform der Schülerin fehlt in der Empfängergruppe.');
expect($recipients[1]['address'][0] === 'Nebenstraße 2', 'Eine separate Erziehungsberechtigtenadresse wurde ignoriert.');
expect($recipients[0]['salutations'] === ['Sehr geehrte Frau Beispiel,', 'Sehr geehrter Herr Beispiel,'], 'Die Eltern werden nicht in der richtigen Reihenfolge angesprochen.');
expect($recipients[1]['salutations'] === ['Sehr geehrte Erziehungsberechtigte,'], 'Für Vormünder fehlt die neutrale Anrede.');
$reversedRecipients = AdmissionLetters::groupRecipients([$rows[1], $rows[0]]);
expect($reversedRecipients[0]['salutations'] === $recipients[0]['salutations'], 'Die Anrede hängt von der Eingangsreihenfolge ab.');
expect(AdmissionLetters::groupRecipients([$rows[1]])[0]['salutations'] === ['Sehr geehrter Herr Beispiel,'], 'Ein einzelner Vater wird nicht korrekt angesprochen.');
expect(AdmissionLetters::groupRecipients([$rows[0]])[0]['salutations'] === ['Sehr geehrte Frau Beispiel,'], 'Eine einzelne Mutter wird nicht korrekt angesprochen.');
$separateFather = array_replace($rows[1], ['guardian_strasse' => 'Andere Straße 3']);
$separate = AdmissionLetters::groupRecipients([$rows[0], $separateFather]);
expect($separate[0]['salutations'] === ['Sehr geehrte Frau Beispiel,'] && $separate[1]['salutations'] === ['Sehr geehrter Herr Beispiel,'], 'Getrennte Adressen erhalten nicht ihre jeweilige Anrede.');
$mixed = AdmissionLetters::groupRecipients([$rows[0], array_replace($rows[2], ['adresse_wie_schuelerin' => 1])]);
expect($mixed[0]['salutations'] === ['Sehr geehrte Frau Beispiel,', 'Sehr geehrte Erziehungsberechtigte,'], 'Gemischte Empfänger werden nicht vollständig angesprochen.');
$differentNames = AdmissionLetters::groupRecipients([
    array_replace($rows[1], ['guardian_nachname' => 'Vatername']),
    array_replace($rows[0], ['guardian_nachname' => 'Müller']),
]);
expect($differentNames[0]['salutations'] === ['Sehr geehrte Frau Müller,', 'Sehr geehrter Herr Vatername,'], 'Unterschiedliche Nachnamen werden nicht korrekt verwendet.');

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
foreach (['in_pruefung', 'angenommen', 'abgelehnt'] as $status) {
    $none = AdmissionLetters::validateFilters('keine', 'status', $status);
    $content = AdmissionLetters::letterContent($none, 'Anna Beispiel', $schoolName);
    $text = $content['subject'] . ' ' . implode(' ', $content['paragraphs']);
    expect(!str_contains($text, 'Gymnasium') && !str_contains($text, 'Förderstufe'), 'Bei fehlender Schulform wird eine Schulform behauptet.');
}
$noneWaiting = AdmissionLetters::validateFilters('keine', 'warteliste', 'ja');
$waitingText = implode(' ', AdmissionLetters::letterContent($noneWaiting, 'Anna Beispiel', $schoolName)['paragraphs']);
expect(str_contains($waitingText, 'Warteliste') && !str_contains($waitingText, 'Förderstufe'), 'Der Wartelistentext ohne Schulform ist falsch.');
expect(AdmissionLetters::validateFilters('beide', 'status', 'angenommen')['school_form'] === 'beide', 'Die Auswahl beider Schulformen wird nicht akzeptiert.');

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
$pdf->addLetter($recipients[0] + ['student_name' => 'Anna Beispiel'], defaultTemplate($statusFilters));
$document = $pdf->Output('S');
expect(str_starts_with($document, '%PDF-'), 'Die PDF-Ausgabe hat keinen gültigen PDF-Kopf.');
expect(str_contains($document, '%%EOF'), 'Die PDF-Ausgabe wurde nicht vollständig abgeschlossen.');
expect(str_contains($document, 'Beispielschule'), 'Der konfigurierte Schulname fehlt im PDF.');
expect(str_contains($document, 'Alex Beispiel'), 'Die konfigurierte Schulleitung fehlt im PDF.');
expect(str_contains($document, '(63071 Offenbach) Tj'), 'Postleitzahl und Wohnort stehen nicht gemeinsam in einer PDF-Zeile.');
expect(
    preg_match('/\(Sehr geehrte Frau Beispiel,\) Tj.*?\(Sehr geehrter Herr Beispiel,\) Tj/s', $document) === 1,
    'Die persönlichen Anreden stehen nicht in der richtigen Reihenfolge im PDF.'
);
expect(
    preg_match('/\(Alex Beispiel\) Tj.*?\(([^()]*)\) Tj/s', $document, $signature) === 1
        && $signature[1] === 'Schulleitung',
    'Unter dem Namen der Schulleitung steht nicht die Funktionsbezeichnung.'
);
expect(str_contains($document, 'schule@example.test'), 'Die konfigurierte E-Mail fehlt im PDF.');
expect(!str_contains($document, 'Tumbrink'), 'Eine alte Schulleitung ist noch im PDF enthalten.');
expect(!str_contains($document, 'MUSTER'), 'Der Musterhinweis erscheint noch im PDF ohne Logo.');
expect(str_contains($document, '(Beispielschule) Tj'), 'Ohne Logo fehlt der Schulname im PDF-Kopf.');

foreach ([
    [$separate[0], '(Sehr geehrte Frau Beispiel,) Tj', '(Sehr geehrter Herr Beispiel,) Tj'],
    [$separate[1], '(Sehr geehrter Herr Beispiel,) Tj', '(Sehr geehrte Frau Beispiel,) Tj'],
    [$recipients[1], '(Sehr geehrte Erziehungsberechtigte,) Tj', '(Sehr geehrte Frau Beispiel,) Tj'],
    [$differentNames[0], '(Sehr geehrte Frau M' . chr(0xfc) . 'ller,) Tj', '(Sehr geehrte Erziehungsberechtigte,) Tj'],
] as [$recipient, $expectedSalutation, $unexpectedSalutation]) {
    $pdfForRecipient = new Schulverwaltung\AdmissionLetterPdf($settings);
    $pdfForRecipient->SetCompression(false);
    $pdfForRecipient->addLetter($recipient, defaultTemplate($statusFilters));
    $recipientDocument = $pdfForRecipient->Output('S');
    expect(str_contains($recipientDocument, $expectedSalutation), 'Die erwartete Anrede fehlt im PDF.');
    expect(!str_contains($recipientDocument, $unexpectedSalutation), 'Das PDF enthält eine falsche Anrede.');
}

foreach ([
    [['', '12345', ''], '12345'],
    [['', '', 'Beispielort'], 'Beispielort'],
    [['', '', ''], null],
] as [$address, $expectedLine]) {
    $pdfWithPartialAddress = new Schulverwaltung\AdmissionLetterPdf($settings);
    $pdfWithPartialAddress->SetCompression(false);
    $pdfWithPartialAddress->addLetter(array_replace($recipients[0], ['address' => $address]), defaultTemplate($statusFilters));
    $partialDocument = $pdfWithPartialAddress->Output('S');
    if ($expectedLine !== null) {
        expect(str_contains($partialDocument, '(' . $expectedLine . ') Tj'), 'Eine unvollständige Adresse wird nicht korrekt ausgegeben.');
    } else {
        expect(!str_contains($partialDocument, '() Tj'), 'Eine leere Adresse erzeugt eine leere PDF-Textzeile.');
    }
}

$settings['logo_data'] = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0yQAAAAASUVORK5CYII=';
$settings['logo_type'] = 'PNG';
$pdfWithLogo = new Schulverwaltung\AdmissionLetterPdf($settings);
$pdfWithLogo->SetCompression(false);
$pdfWithLogo->addLetter($recipients[0], defaultTemplate($statusFilters));
$pdfWithLogo->addLetter($recipients[1], defaultTemplate($statusFilters));
$logoDocument = $pdfWithLogo->Output('S');
expect(str_starts_with($logoDocument, '%PDF-'), 'PDF mit konfiguriertem Logo konnte nicht erzeugt werden.');
expect(substr_count($logoDocument, '/Subtype /Image') === 2, 'Das Logo wurde auf mehreren Seiten nicht wiederverwendet.');
expect(!str_contains($logoDocument, 'MUSTER'), 'Der Musterhinweis erscheint noch im PDF mit Logo.');
preg_match_all('/q ([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm \/I\d+ Do Q/', $logoDocument, $logoPositions, PREG_SET_ORDER);
expect(count($logoPositions) === 2, 'Das Logo fehlt auf einer der PDF-Seiten.');
foreach ($logoPositions as $position) {
    $rightEdgeMm = ((float) $position[1] + (float) $position[3]) * 25.4 / 72;
    $widthMm = (float) $position[1] * 25.4 / 72;
    expect(abs($rightEdgeMm - 190) < 0.02 && $widthMm <= 57.01, 'Das Logo ist nicht rechtsbündig im Kopf platziert.');
}

fwrite(STDOUT, "Serienbrief-Filter, Empfängergruppen und Mustertexte erfolgreich geprüft." . PHP_EOL);
