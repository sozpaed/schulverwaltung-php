<?php

declare(strict_types=1);

use Schulverwaltung\AdmissionLetterPdf;
use Schulverwaltung\AdmissionLetters;
use Schulverwaltung\Auth;
use Schulverwaltung\SchoolSettings;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AdmissionLetters.php';
require_once __DIR__ . '/../../src/AdmissionLetterPdf.php';
require_once __DIR__ . '/../../src/SchoolSettings.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_export']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Daten zu exportieren.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Diese Seite kann nur über das Exportformular aufgerufen werden.');
}

if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Das Formular ist abgelaufen. Bitte lade die Exportseite neu.');
}

try {
    $filters = AdmissionLetters::validateFilters(
        $_POST['school_form'] ?? null,
        $_POST['filter_type'] ?? null,
        $_POST['filter_value'] ?? null
    );
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    exit('Die Filterauswahl ist ungültig. Bitte gehe zurück und wähle die Filter erneut aus.');
}

$settings = SchoolSettings::get($database);
if ($settings['school_name'] === '' || $settings['head_name'] === '') {
    http_response_code(400);
    exit('Bitte zuerst Name der Schule und Name der Schulleitung in der Administration hinterlegen.');
}

$rows = AdmissionLetters::findRecipients($database, $filters);
$recipients = AdmissionLetters::groupRecipients($rows);
if ($recipients === []) {
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/assets/app.css">
        <title>Keine Treffer – Serienbrief</title>
    </head>
    <body><main>
    <?= renderSchoolHeader($database) ?>
        <h1>Keine passenden Schülerinnen gefunden</h1>
        <p>Für die gewählte Schulform und den Filter gibt es keine passenden Datensätze.</p>
        <p><a href="/exporte/">Zurück zum Datenexport</a></p>
    </main></body>
    </html>
    <?php
    exit;
}

$pdf = new AdmissionLetterPdf($settings);
foreach ($recipients as $recipient) {
    $pdf->addLetter($recipient, AdmissionLetters::filtersForRecipient($filters, $recipient));
}
$document = $pdf->Output('S');
$studentCount = count(array_unique(array_column($rows, 'id')));

$logStatement = $database->prepare(
    'INSERT INTO export_protokolle (benutzer_id, bereich, datensaetze, format)
     VALUES (:benutzer_id, :bereich, :datensaetze, :format)'
);
$logStatement->execute([
    'benutzer_id' => $user['id'],
    'bereich' => 'serienbrief',
    'datensaetze' => $studentCount,
    'format' => 'pdf',
]);

$filename = 'serienbrief_' . $filters['school_form'] . '_' . $filters['filter_type']
    . '_' . $filters['filter_value'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($document));
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo $document;
