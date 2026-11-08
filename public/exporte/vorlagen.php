<?php

declare(strict_types=1);

use Schulverwaltung\AdmissionLetterPdf;
use Schulverwaltung\Auth;
use Schulverwaltung\LetterTemplates;
use Schulverwaltung\SchoolSettings;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/AdmissionLetters.php';
require_once __DIR__ . '/../../src/AdmissionLetterPdf.php';

if ($user === null) {
    header('Location: /');
    exit;
}
if (!$user['can_edit_letter_templates']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Serienbriefvorlagen zu bearbeiten.');
}

$form = $_GET['schulform'] ?? '';
$type = $_GET['briefart'] ?? '';
$editing = $form !== '' || $type !== '';
$errors = [];
$saved = ($_GET['gespeichert'] ?? '') === '1';
$template = null;
if ($editing) {
    try {
        LetterTemplates::checkKey($form, $type);
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        exit('Ungültige Vorlagenauswahl.');
    }
    $template = LetterTemplates::get($database, $form, $type);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$editing || !Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Das Formular ist ungültig oder abgelaufen.');
    }
    $subject = $_POST['betreff'] ?? null;
    $body = $_POST['inhalt'] ?? null;
    $version = $_POST['version'] ?? null;
    $action = $_POST['aktion'] ?? null;
    if (!is_string($subject) || !is_string($body) || !is_string($version)
        || !ctype_digit($version) || (int) $version < 1 || !in_array($action, ['speichern', 'vorschau'], true)) {
        http_response_code(400);
        exit('Ungültige Formulardaten.');
    }
    $template = ['betreff' => $subject, 'inhalt' => $body, 'version' => $version];
    try {
        LetterTemplates::validate($subject, $body);
        if ($action === 'vorschau') {
            $settings = SchoolSettings::get($database);
            $sample = [
                'student_name' => 'Anna Beispiel', 'class' => '5a',
                'guardian_surname' => 'Beispiel',
                'guardian_salutation' => "Sehr geehrte Frau Beispiel,\nSehr geehrter Herr Beispiel,",
                'salutations' => ['Sehr geehrte Frau Beispiel,', 'Sehr geehrter Herr Beispiel,'],
                'recipients' => ['Maria Beispiel', 'Max Beispiel'],
                'address' => ['Beispielstraße 1', '12345', 'Beispielstadt'],
            ];
            $pdf = new AdmissionLetterPdf($settings);
            $pdf->addLetter($sample, $template);
            $document = $pdf->Output('S');
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="serienbrief-vorschau.pdf"');
            header('Content-Length: ' . strlen($document));
            echo $document;
            exit;
        }
        if (!LetterTemplates::save($database, $form, $type, $subject, $body, (int) $version)) {
            http_response_code(409);
            $errors[] = 'Die Vorlage wurde zwischenzeitlich geändert. Bitte kopiere deine Änderungen und lade die Seite neu.';
        } else {
            header('Location: /exporte/vorlagen.php?' . http_build_query(['schulform' => $form, 'briefart' => $type, 'gespeichert' => '1']), true, 303);
            exit;
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        $errors[] = $exception->getMessage();
    }
}
$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <?php if ($editing): ?><script src="/assets/letter-editor.js" defer></script><?php endif; ?>
    <title>Serienbriefvorlagen – Schulverwaltung</title>
</head>
<body>
<main>
    <?= renderSchoolHeader($database) ?>
    <p><a href="/exporte/serienbriefe.php">Zurück zu Serienbriefen</a></p>
    <h1>Serienbriefvorlagen</h1>
    <?= renderAccountMenu($user) ?>
    <?php if (!$editing): ?>
        <form method="get">
            <p><label for="schulform">Schulform</label>
                <select id="schulform" name="schulform" required>
                    <option value="">Bitte auswählen</option>
                    <?php foreach (LetterTemplates::FORMS as $value => $label): ?>
                        <option value="<?= escape($value) ?>"><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <p><label for="briefart">Status oder Warteliste</label>
                <select id="briefart" name="briefart" required>
                    <option value="">Bitte auswählen</option>
                    <?php foreach (LetterTemplates::TYPES as $value => $label): ?>
                        <option value="<?= escape($value) ?>"><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <button type="submit">Editieren</button>
        </form>
    <?php else: ?>
        <p><a href="/exporte/vorlagen.php">Andere Vorlage auswählen</a></p>
        <h2><?= escape(LetterTemplates::FORMS[$form] . ' – ' . LetterTemplates::TYPES[$type]) ?></h2>
        <?php if ($saved): ?><p role="status">Die Vorlage wurde gespeichert.</p><?php endif; ?>
        <?php if ($errors !== []): ?>
            <div role="alert"><ul><?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <p>Platzhalter: <code>{{schuelerin}}</code>, <code>{{schulname}}</code>, <code>{{klasse}}</code>,
            <code>{{erziehungsberechtigter_nachname}}</code>, <code>{{erziehungsberechtigter_anrede}}</code>.
            Die persönliche Anrede wird bereits automatisch vor dem Brieftext eingefügt.</p>
        <form method="post" data-letter-editor>
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <input type="hidden" name="version" value="<?= (int) $template['version'] ?>">
            <p><label for="betreff">Betreff</label>
                <input id="betreff" name="betreff" maxlength="500" required value="<?= escape($template['betreff']) ?>"></p>
            <p><label for="inhalt">Brieftext</label></p>
            <div data-editor-controls hidden>
                <button type="button" data-format="bold">Fett</button>
                <button type="button" data-format="italic">Kursiv</button>
            </div>
            <div class="letter-editor" data-editor-content contenteditable="true" role="textbox"
                 aria-label="Brieftext" aria-multiline="true" hidden></div>
            <textarea id="inhalt" name="inhalt" rows="14" required><?= escape($template['inhalt']) ?></textarea>
            <p><button name="aktion" value="speichern" type="submit">Speichern</button>
                <button name="aktion" value="vorschau" type="submit" formtarget="_blank">PDF-Vorschau mit Beispieldaten</button>
                <a href="/exporte/vorlagen.php">Abbrechen</a></p>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
