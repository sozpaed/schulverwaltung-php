<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;
use Schulverwaltung\SchoolSettings;
use Schulverwaltung\AdmissionLetterPdf;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';
require_once __DIR__ . '/../../src/SchoolSettings.php';
require_once __DIR__ . '/../../src/AdmissionLetterPdf.php';

Admin::requireAdministrator($user);

$settings = SchoolSettings::get($database);
$errors = [];
$saved = isset($_GET['gespeichert']) && $_GET['gespeichert'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Das Formular ist abgelaufen. Bitte lade die Seite neu.');
    }

    foreach (SchoolSettings::FIELDS as $field => $_) {
        $value = $_POST[$field] ?? null;
        $settings[$field] = is_string($value) ? trim($value) : null;
    }
    $errors = SchoolSettings::validate($settings);
    $removeLogo = $_POST['remove_logo'] ?? '0';
    if (!is_string($removeLogo) || !in_array($removeLogo, ['0', '1'], true)) {
        $errors[] = 'Ungültige Logoauswahl.';
    }

    $upload = $_FILES['logo'] ?? null;
    if (!is_array($upload) || !isset($upload['error']) || !is_int($upload['error'])) {
        $errors[] = 'Der Logo-Upload konnte nicht geprüft werden.';
    } elseif ($upload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($removeLogo === '1') {
            $errors[] = 'Bitte entweder ein neues Logo hochladen oder das vorhandene Logo entfernen.';
        } elseif ($upload['error'] !== UPLOAD_ERR_OK || !isset($upload['tmp_name'], $upload['size'])
            || !is_string($upload['tmp_name']) || !is_int($upload['size'])
            || $upload['size'] > 1_000_000 || !is_uploaded_file($upload['tmp_name'])) {
            $errors[] = 'Das Logo konnte nicht hochgeladen werden oder ist größer als 1 MB.';
        } else {
            $contents = file_get_contents($upload['tmp_name']);
            if ($contents === false) {
                $errors[] = 'Das Logo konnte nicht gelesen werden.';
            } else {
                $image = getimagesizefromstring($contents);
                if ($image === false || !in_array($image[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
                    || $image[0] < 1 || $image[1] < 1 || $image[0] > 2500 || $image[1] > 2500) {
                    $errors[] = 'Das Logo muss ein PNG oder JPEG mit höchstens 2500 × 2500 Pixeln sein.';
                } else {
                    $logoData = base64_encode($contents);
                    $logoType = $image[2] === IMAGETYPE_PNG ? 'PNG' : 'JPEG';
                    try {
                        $testSettings = array_fill_keys(array_keys(SchoolSettings::FIELDS), '');
                        $testSettings['logo_data'] = $logoData;
                        $testSettings['logo_type'] = $logoType;
                        $testPdf = new AdmissionLetterPdf($testSettings);
                        $testPdf->AddPage();
                        $testPdf->Output('S');
                        $settings['logo_data'] = $logoData;
                        $settings['logo_type'] = $logoType;
                    } catch (Exception $exception) {
                        $errors[] = 'Das Logo kann nicht für Serienbriefe verarbeitet werden. Bitte ein anderes PNG oder JPEG verwenden.';
                    }
                }
            }
        }
    } elseif ($removeLogo === '1') {
        $settings['logo_data'] = null;
        $settings['logo_type'] = null;
    }

    if ($errors === []) {
        $values = [];
        foreach (array_keys(SchoolSettings::FIELDS) as $field) {
            $values[$field] = $settings[$field];
        }
        $values['logo_data'] = $settings['logo_data'];
        $values['logo_type'] = $settings['logo_type'];
        SchoolSettings::save($database, $values);
        header('Location: /administration/schule.php?gespeichert=1', true, 303);
        exit;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Angaben zur Schule – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/">Zur Startseite</a></p>
    <p><a href="/administration/">Zur Administration</a></p>
    <h1>Angaben zur Schule</h1>
    <?= renderAccountMenu($user) ?>
    <h2>Schule und Serienbrief</h2>
    <?php if ($saved): ?><p role="status">Die Einstellungen wurden gespeichert.</p><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div role="alert"><p>Bitte korrigiere folgende Angaben:</p><ul>
            <?php foreach ($errors as $error): ?><li><?= escape($error) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape(Auth::csrfToken()) ?>">
        <?php foreach (SchoolSettings::FIELDS as $field => [$label, $maximum]): ?>
            <p><label for="<?= escape($field) ?>"><?= escape($label) ?></label>
                <input id="<?= escape($field) ?>" name="<?= escape($field) ?>" maxlength="<?= $maximum ?>"
                       value="<?= escape(is_string($settings[$field]) ? $settings[$field] : '') ?>"></p>
        <?php endforeach; ?>
        <p><label for="logo">Logo für Serienbriefe (PNG oder JPEG, max. 1 MB)</label>
            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg"></p>
        <?php if ($settings['logo_data'] !== null): ?>
            <p>Ein Logo ist gespeichert.</p>
            <p><label for="remove_logo">Gespeichertes Logo entfernen</label>
                <input id="remove_logo" name="remove_logo" type="checkbox" value="1"></p>
        <?php endif; ?>
        <button type="submit">Einstellungen speichern</button>
    </form>
</main>
</body>
</html>
