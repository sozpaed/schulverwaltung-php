<?php

declare(strict_types=1);

use Schulverwaltung\AdmissionLetterPdf;
use Schulverwaltung\AdmissionLetters;
use Schulverwaltung\LetterTemplates;

require_once __DIR__ . '/../src/AdmissionLetters.php';
require_once __DIR__ . '/../src/AdmissionLetterPdf.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Für diesen Test wird die PDO-SQLite-Erweiterung benötigt.\n");
    exit(1);
}
function expectTemplate(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE serienbriefvorlagen (schulform TEXT, briefart TEXT, betreff TEXT, inhalt TEXT, version INTEGER DEFAULT 1, PRIMARY KEY (schulform, briefart))');
LetterTemplates::initialize($db);
expectTemplate((int) $db->query('SELECT COUNT(*) FROM serienbriefvorlagen')->fetchColumn() === 12, 'Es wurden nicht zwölf Vorlagen angelegt.');
foreach (LetterTemplates::FORMS as $form => $label) {
    foreach (LetterTemplates::TYPES as $type => $typeLabel) {
        $template = LetterTemplates::get($db, $form, $type);
        LetterTemplates::validate($template['betreff'], $template['inhalt']);
        expectTemplate(str_contains($template['inhalt'], '{{schuelerin}}'), 'Ausgangsvorlage enthält keinen Schülerinnenplatzhalter.');
    }
}
$subject = 'Für {{schuelerin}} – {{klasse}}';
$body = '<p>Hallo <strong>{{schuelerin}}</strong> in <em>{{klasse}}</em>.</p><p>{{erziehungsberechtigter_nachname}}<br/>{{erziehungsberechtigter_anrede}}</p>';
expectTemplate(LetterTemplates::save($db, 'gymnasium', 'angenommen', $subject, $body, 1), 'Vorlage konnte nicht gespeichert werden.');
expectTemplate(!LetterTemplates::save($db, 'gymnasium', 'angenommen', 'Alt', '<p>Alt</p>', 1), 'Gleichzeitige Bearbeitung wird nicht erkannt.');
LetterTemplates::initialize($db);
$template = LetterTemplates::get($db, 'gymnasium', 'angenommen');
expectTemplate($template['betreff'] === $subject && (int) $template['version'] === 2, 'Initialisierung hat eine geänderte Vorlage überschrieben.');
expectTemplate(LetterTemplates::get($db, 'keine', 'angenommen')['betreff'] !== $subject, 'Eine andere Vorlage wurde verändert.');
foreach ([
    ['Betreff', '<p>Hallo <script>1</script></p>'],
    ['Betreff', '<p onclick="x">Hallo</p>'],
    ['Betreff', '<p>{{unbekannt}}</p>'],
    ['Betreff', '<p>{{schu<strong>elerin}}</strong></p>'],
    ['Betreff', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///test">]><p>&x;</p>'],
    ['{{unbekannt}}', '<p>Text</p>'],
    ['Betreff', '<p> </p>'],
] as [$invalidSubject, $invalidBody]) {
    try {
        LetterTemplates::validate($invalidSubject, $invalidBody);
        expectTemplate(false, 'Ungültige Vorlage wurde angenommen: ' . $invalidBody);
    } catch (InvalidArgumentException $exception) {
        expectTemplate($exception->getMessage() !== '', 'Eine Fehlermeldung fehlt.');
    }
}
$base = [
    'id' => 1, 'vorname' => 'Anna', 'nachname' => 'Beispiel', 'schulform' => 'gymnasium',
    'klasse' => '5a', 'strasse' => '', 'plz' => null, 'ort' => '',
    'guardian_id' => 1, 'guardian_vorname' => 'Maria', 'guardian_nachname' => 'Müller',
    'guardian_beziehung' => 'mutter', 'adresse_wie_schuelerin' => 1,
    'guardian_strasse' => '', 'guardian_plz' => null, 'guardian_ort' => '',
];
$father = array_replace($base, ['guardian_id' => 2, 'guardian_vorname' => 'Max', 'guardian_beziehung' => 'vater']);
$recipient = AdmissionLetters::groupRecipients([$father, $base])[0];
expectTemplate($recipient['guardian_surname'] === 'Müller', 'Gleiche Nachnamen werden nicht zusammengefasst.');
expectTemplate($recipient['guardian_salutation'] === "Sehr geehrte Frau Müller,\nSehr geehrter Herr Müller,", 'Anredeplatzhalter ist falsch sortiert.');
$recipientDifferent = AdmissionLetters::groupRecipients([array_replace($father, ['guardian_nachname' => 'Schmidt']), $base])[0];
expectTemplate($recipientDifferent['guardian_surname'] === 'Müller und Schmidt', 'Verschiedene Nachnamen werden nicht verbunden.');
$noGuardian = AdmissionLetters::groupRecipients([array_replace($base, ['guardian_id' => null, 'klasse' => ''])])[0];
expectTemplate($noGuardian['guardian_surname'] === '' && $noGuardian['guardian_salutation'] === '' && $noGuardian['class'] === '', 'Fehlende Platzhalterwerte sind nicht leer.');
$settings = ['school_name' => 'Testschule', 'head_name' => 'Leitung', 'street' => '', 'postal_code' => '', 'city' => '', 'phone' => '', 'email' => '', 'website' => '', 'logo_data' => null, 'logo_type' => null];
$pdf = new AdmissionLetterPdf($settings);
$pdf->SetCompression(false);
$pdf->addLetter($recipient, $template);
$output = $pdf->Output('S');
expectTemplate(str_contains($output, 'Anna Beispiel') && str_contains($output, '5a'), 'Platzhalter fehlen im PDF.');
expectTemplate(!str_contains($output, '{{'), 'Platzhalter wurden nicht ersetzt.');
expectTemplate(str_contains($output, '/BaseFont /Helvetica-Bold') && str_contains($output, '/BaseFont /Helvetica-Oblique'), 'Fett oder Kursiv fehlen im PDF.');
fwrite(STDOUT, "Serienbriefvorlagen erfolgreich geprüft.\n");
