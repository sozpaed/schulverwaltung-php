<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;
use Schulverwaltung\GuardianCsvImport;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';
require_once __DIR__ . '/../../src/GuardianCsvImport.php';

Admin::requireAdministrator($user);

$message = '';
$preview = $_SESSION['guardian_csv_import'] ?? null;
$relationships = guardianRelationships();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.');
    }

    $action = $_POST['action'] ?? null;
    if (!is_string($action)) {
        http_response_code(400);
        exit('Ungültige Formularaktion.');
    }

    if ($action === 'cancel') {
        unset($_SESSION['guardian_csv_import']);
        header('Location: /', true, 303);
        exit;
    }

    if ($action === 'preview') {
        unset($_SESSION['guardian_csv_import']);
        $preview = null;
        $upload = $_FILES['csv_file'] ?? null;
        if (!is_array($upload)
            || !isset($upload['error'], $upload['tmp_name'], $upload['size'])
            || !is_int($upload['error'])
            || !is_string($upload['tmp_name'])
            || !is_int($upload['size'])
            || $upload['error'] !== UPLOAD_ERR_OK) {
            $message = 'Die CSV-Datei konnte nicht hochgeladen werden.';
        } elseif (!is_uploaded_file($upload['tmp_name']) || $upload['size'] > 2_000_000) {
            $message = 'Die Datei ist ungültig oder größer als 2 MB.';
        } else {
            $contents = file_get_contents($upload['tmp_name']);
            if ($contents === false) {
                $message = 'Die hochgeladene CSV-Datei konnte nicht gelesen werden.';
            } else {
                $parsed = GuardianCsvImport::parse($contents);
                if ($parsed['headerErrors'] !== []) {
                    $message = implode(' ', $parsed['headerErrors']);
                } else {
                    $groupLines = [];
                    $addressRows = [];
                    foreach ($parsed['rows'] as &$row) {
                        $row['student_group'] = $row['errors'] === []
                            ? GuardianCsvImport::studentGroupKey($row['values'])
                            : hash('sha256', 'invalid-row:' . $row['line']);
                        $groupLines[$row['student_group']][] = $row['line'];
                        if ($row['errors'] === []) {
                            $addressRows[] = [$row['values'], $row['student_group']];
                        }
                    }
                    unset($row);

                    $_SESSION['guardian_csv_import'] = [
                        'token' => bin2hex(random_bytes(32)),
                        'rows' => $parsed['rows'],
                        'group_lines' => $groupLines,
                        'addresses_consistent' => GuardianCsvImport::addressesAreConsistent($addressRows),
                    ];
                    $preview = $_SESSION['guardian_csv_import'];
                }
            }
        }
    } elseif ($action === 'import') {
        $preview = $_SESSION['guardian_csv_import'] ?? null;
        $token = $_POST['import_token'] ?? null;
        if (!is_array($preview)
            || !is_string($token)
            || !hash_equals($preview['token'], $token)) {
            http_response_code(400);
            exit('Die Importvorschau ist abgelaufen. Bitte lade die Datei erneut hoch.');
        }

        $resolvedRows = [];
        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                $message = 'Der Import ist nicht möglich: Bitte korrigiere alle markierten CSV-Zeilen.';
                break;
            }
            $resolvedRows[] = [$row['values'], $row['student_group']];
        }

        if ($message === '' && count($resolvedRows) !== count($preview['rows'])) {
            $message = 'Der Import ist nicht möglich, da nicht alle CSV-Zeilen gültig sind.';
        }

        if ($message === '' && !GuardianCsvImport::addressesAreConsistent($resolvedRows)) {
            $message = 'Für denselben Namen sind unterschiedliche Adressen eingetragen. Bitte vereinheitliche die Adressen oder prüfe die CSV-Zeilen.';
            unset($_SESSION['guardian_csv_import']);
            $preview = null;
        }

        if ($message === '') {
            try {
                $database->beginTransaction();
                $createStudent = $database->prepare(
                    'INSERT INTO schueler (
                        vorname, nachname, strasse, plz, ort, geburtsort
                    ) VALUES (
                        :vorname, :nachname, :strasse, :plz, :ort, \'\'
                    )'
                );
                $insert = $database->prepare(
                    'INSERT INTO erziehungsberechtigte (
                        schueler_id, vorname, nachname, beziehung, strasse, plz, ort,
                        adresse_wie_schuelerin, mobil, email
                    ) VALUES (
                        :schueler_id, :vorname, :nachname, :beziehung, :strasse, :plz, :ort,
                        1, :mobil, :email
                    )'
                );
                $studentIds = [];
                foreach ($resolvedRows as [$values, $studentGroup]) {
                    if (!isset($studentIds[$studentGroup])) {
                        $createStudent->execute([
                            'vorname' => $values['schuelerin_vorname'],
                            'nachname' => $values['schuelerin_nachname'],
                            'strasse' => $values['strasse'],
                            'plz' => $values['plz'] === '' ? null : $values['plz'],
                            'ort' => $values['ort'],
                        ]);
                        $studentIds[$studentGroup] = (int) $database->lastInsertId();
                    }
                    $insert->execute([
                        'schueler_id' => $studentIds[$studentGroup],
                        'vorname' => $values['vorname'],
                        'nachname' => $values['nachname'],
                        'beziehung' => $values['beziehung'],
                        'strasse' => $values['strasse'],
                        'plz' => $values['plz'] === '' ? null : $values['plz'],
                        'ort' => $values['ort'],
                        'mobil' => $values['mobil'],
                        'email' => $values['email'],
                    ]);
                }
                if ($message !== '') {
                    $database->rollBack();
                } else {
                    $database->commit();
                    $message = count($studentIds) . ' Schülerinnen und '
                        . count($resolvedRows) . ' Erziehungsberechtigte wurden erfolgreich importiert.';
                    unset($_SESSION['guardian_csv_import']);
                    $preview = null;
                }
            } catch (Throwable $exception) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $exception;
            }
        }
    } else {
        http_response_code(400);
        exit('Ungültige Formularaktion.');
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
    <title>CSV-Import – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Neue Schülerinnen und Erziehungsberechtigte aus CSV importieren</h1>
    <?= renderAccountMenu($user) ?>
    <?php if ($message !== ''): ?>
        <p role="status"><?= escape($message) ?></p>
    <?php endif; ?>

    <?php if ($preview === null): ?>
        <p>Die Datei muss UTF-8 und mit Semikolon getrennt sein. Es werden höchstens 500 Zeilen und 2 MB verarbeitet.</p>
        <p>Die Adresse aus der CSV wird im Datensatz der Schülerin und beim Erziehungsberechtigten gespeichert. Eine vorhandene Adresse der Schülerin wird dabei ersetzt; die Adresse des Erziehungsberechtigten wird als vom Kind übernommen markiert.</p>
        <p>Für Angaben, die nicht in der CSV enthalten sind, werden leere Werte oder die Datenbank-Standardwerte verwendet. Zeilen mit identischem Vor- und Nachnamen der Schülerin werden als dieselbe neue Schülerin zusammengeführt.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <p><label for="csv_file">CSV-Datei</label>
                <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required></p>
            <button type="submit">Datei prüfen</button>
        </form>
    <?php else: ?>
        <?php
        $allRowsPotentiallyImportable = $preview['addresses_consistent'];
        foreach ($preview['rows'] as $row) {
            if ($row['errors'] !== []) {
                $allRowsPotentiallyImportable = false;
                break;
            }
        }
        ?>
        <p role="status">
            <?= $allRowsPotentiallyImportable
                ? 'Alle Zeilen können importiert werden. Für jeden unterschiedlichen Vor- und Nachnamen wird ein neuer Schülerinnendatensatz angelegt.'
                : (!$preview['addresses_consistent']
                    ? 'Für identische Schülerinnen-Namen sind unterschiedliche Adressen eingetragen. Bitte vereinheitliche die Adressen oder prüfe die Zeilen.'
                    : 'Der Import ist derzeit nicht möglich. Bitte korrigiere die markierten Zeilen in der CSV-Datei und prüfe sie erneut.') ?>
        </p>
        <form method="post">
            <input type="hidden" name="action" value="import">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <input type="hidden" name="import_token" value="<?= escape($preview['token']) ?>">
            <table>
                <thead>
                <tr>
                    <th scope="col">CSV-Zeile</th>
                    <th scope="col">Erziehungsberechtigte Person</th>
                    <th scope="col">Beziehung</th>
                    <th scope="col">Schülerin</th>
                    <th scope="col">Anlage</th>
                    <th scope="col">Adresse</th>
                    <th scope="col">Ergebnis</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['rows'] as $index => $row): ?>
                    <?php
                    $studentLines = $preview['group_lines'][$row['student_group']];
                    $isFirstGroupRow = $studentLines[0] === $row['line'];
                    ?>
                    <tr>
                        <td><?= (int) $row['line'] ?></td>
                        <td><?= escape($row['values']['vorname'] . ' ' . $row['values']['nachname']) ?></td>
                        <td><?= escape($relationships[$row['values']['beziehung']] ?? $row['values']['beziehung']) ?></td>
                        <td><?= escape($row['values']['schuelerin_vorname'] . ' ' . $row['values']['schuelerin_nachname']) ?></td>
                        <td><?= $isFirstGroupRow
                            ? 'Wird neu angelegt'
                            : 'Zusammenführung mit CSV-Zeile ' . (int) $studentLines[0] ?></td>
                        <td><?= escape($row['values']['strasse'] . ', ' . $row['values']['plz'] . ' ' . $row['values']['ort']) ?></td>
                        <td>
                            <?php if ($row['errors'] !== []): ?>
                                <?= escape(implode(' ', $row['errors'])) ?>
                            <?php elseif (!$preview['addresses_consistent']): ?>
                                Adresse für diesen Namen prüfen.
                            <?php else: ?>
                                <?= $isFirstGroupRow
                                    ? 'Schülerin und Kontaktperson werden angelegt.'
                                    : 'Kontaktperson wird derselben Schülerin zugeordnet.' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit"<?= !$allRowsPotentiallyImportable ? ' disabled' : '' ?>>Import</button>
        </form>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abbrechen</button>
    </form>
</main>
</body>
</html>
