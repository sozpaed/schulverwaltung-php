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
                    foreach ($parsed['rows'] as &$row) {
                        $row['candidates'] = [];
                        $row['selected_student_id'] = '';
                        $row['duplicate_student_ids'] = [];
                        if ($row['errors'] !== []) {
                            continue;
                        }

                        $studentStatement = $database->prepare(
                            'SELECT id, vorname, nachname
                             FROM schueler
                             WHERE vorname = :vorname AND nachname = :nachname
                             ORDER BY nachname, vorname, id'
                        );
                        $studentStatement->execute([
                            'vorname' => $row['values']['schuelerin_vorname'],
                            'nachname' => $row['values']['schuelerin_nachname'],
                        ]);
                        $row['candidates'] = $studentStatement->fetchAll();
                        if (count($row['candidates']) === 1) {
                            $row['selected_student_id'] = (string) $row['candidates'][0]['id'];
                        }

                        foreach ($row['candidates'] as $candidate) {
                            $duplicateStatement = $database->prepare(
                                'SELECT id FROM erziehungsberechtigte
                                 WHERE schueler_id = :student_id
                                   AND LOWER(vorname) = LOWER(:vorname)
                                   AND LOWER(nachname) = LOWER(:nachname)
                                   AND LOWER(email) = LOWER(:email)
                                 LIMIT 1'
                            );
                            $duplicateStatement->execute([
                                'student_id' => $candidate['id'],
                                'vorname' => $row['values']['vorname'],
                                'nachname' => $row['values']['nachname'],
                                'email' => $row['values']['email'],
                            ]);
                            if ($duplicateStatement->fetchColumn() !== false) {
                                $row['duplicate_student_ids'][] = (string) $candidate['id'];
                            }
                        }
                    }
                    unset($row);

                    $_SESSION['guardian_csv_import'] = [
                        'token' => bin2hex(random_bytes(32)),
                        'rows' => $parsed['rows'],
                    ];
                    $preview = $_SESSION['guardian_csv_import'];
                }
            }
        }
    } elseif ($action === 'import') {
        $preview = $_SESSION['guardian_csv_import'] ?? null;
        $token = $_POST['import_token'] ?? null;
        $selections = $_POST['student_id'] ?? [];
        if (!is_array($preview)
            || !is_string($token)
            || !hash_equals($preview['token'], $token)
            || !is_array($selections)) {
            http_response_code(400);
            exit('Die Importvorschau ist abgelaufen. Bitte lade die Datei erneut hoch.');
        }

        $resolvedRows = [];
        foreach ($preview['rows'] as $index => $row) {
            if ($row['errors'] !== []) {
                $message = 'Der Import ist nicht möglich: Bitte korrigiere alle markierten CSV-Zeilen.';
                break;
            }
            $selection = $selections[(string) $index] ?? '';
            if (!is_string($selection) || $selection === '') {
                $message = 'Bitte ordne jeder CSV-Zeile eine Schülerin zu.';
                break;
            }

            $studentStatement = $database->prepare(
                'SELECT id
                 FROM schueler
                 WHERE vorname = :vorname AND nachname = :nachname
                 ORDER BY id'
            );
            $studentStatement->execute([
                'vorname' => $row['values']['schuelerin_vorname'],
                'nachname' => $row['values']['schuelerin_nachname'],
            ]);
            $candidateIds = array_map('strval', $studentStatement->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($selection, $candidateIds, true)) {
                $message = 'Eine Zuordnung ist nicht mehr gültig. Bitte lade die Datei erneut hoch.';
                break;
            }

            $preview['rows'][$index]['selected_student_id'] = $selection;
            $resolvedRows[] = [$row['values'], $selection];
        }
        $_SESSION['guardian_csv_import'] = $preview;

        if ($message === '' && count($resolvedRows) !== count($preview['rows'])) {
            $message = 'Der Import ist nicht möglich, da nicht alle Zeilen eindeutig zugeordnet sind.';
        }

        if ($message === '' && !GuardianCsvImport::addressesAreConsistent($resolvedRows)) {
            $message = 'Für dieselbe Schülerin sind unterschiedliche Adressen eingetragen. Bitte vereinheitliche die Adressen in der CSV-Datei und lade sie erneut hoch.';
            unset($_SESSION['guardian_csv_import']);
            $preview = null;
        }

        if ($message === '') {
            try {
                $database->beginTransaction();
                $updateStudentAddress = $database->prepare(
                    'UPDATE schueler
                     SET strasse = :strasse, plz = :plz, ort = :ort
                     WHERE id = :id'
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
                $updatedStudentIds = [];
                foreach ($resolvedRows as [$values, $studentId]) {
                    $duplicateStatement = $database->prepare(
                        'SELECT id FROM erziehungsberechtigte
                         WHERE schueler_id = :student_id
                           AND LOWER(vorname) = LOWER(:vorname)
                           AND LOWER(nachname) = LOWER(:nachname)
                           AND LOWER(email) = LOWER(:email)
                         LIMIT 1'
                    );
                    $duplicateStatement->execute([
                        'student_id' => $studentId,
                        'vorname' => $values['vorname'],
                        'nachname' => $values['nachname'],
                        'email' => $values['email'],
                    ]);
                    if ($duplicateStatement->fetchColumn() !== false) {
                        $message = 'Eine gleiche Kontaktperson ist bereits vorhanden. Es wurden keine Datensätze importiert.';
                        break;
                    }
                    if (!isset($updatedStudentIds[$studentId])) {
                        $updateStudentAddress->execute([
                            'id' => $studentId,
                            'strasse' => $values['strasse'],
                            'plz' => $values['plz'] === '' ? null : $values['plz'],
                            'ort' => $values['ort'],
                        ]);
                        $updatedStudentIds[$studentId] = true;
                    }
                    $insert->execute([
                        'schueler_id' => $studentId,
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
                    $message = count($resolvedRows) . ' Erziehungsberechtigte wurden erfolgreich importiert.';
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
    <h1>Erziehungsberechtigte aus CSV importieren</h1>
    <?= renderAccountMenu($user) ?>
    <?php if ($message !== ''): ?>
        <p role="status"><?= escape($message) ?></p>
    <?php endif; ?>

    <?php if ($preview === null): ?>
        <p>Die Datei muss UTF-8 und mit Semikolon getrennt sein. Es werden höchstens 500 Zeilen und 2 MB verarbeitet.</p>
        <p>Die Adresse aus der CSV wird im Datensatz der Schülerin und beim Erziehungsberechtigten gespeichert. Eine vorhandene Adresse der Schülerin wird dabei ersetzt; die Adresse des Erziehungsberechtigten wird als vom Kind übernommen markiert.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <p><label for="csv_file">CSV-Datei</label>
                <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required></p>
            <button type="submit">Datei prüfen</button>
        </form>
    <?php else: ?>
        <?php
        $allRowsPotentiallyImportable = true;
        foreach ($preview['rows'] as $row) {
            $availableCandidates = array_diff(
                array_map(static fn (array $candidate): string => (string) $candidate['id'], $row['candidates']),
                $row['duplicate_student_ids']
            );
            if ($row['errors'] !== [] || $availableCandidates === []) {
                $allRowsPotentiallyImportable = false;
                break;
            }
        }
        ?>
        <p role="status">
            <?= $allRowsPotentiallyImportable
                ? 'Alle Zeilen können nach Prüfung der Zuordnung importiert werden.'
                : 'Der Import ist derzeit nicht möglich. Bitte korrigiere die markierten Zeilen in der CSV-Datei und prüfe sie erneut.' ?>
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
                    <th scope="col">Schülerin in CSV</th>
                    <th scope="col">Zuordnung in der Datenbank</th>
                    <th scope="col">Ergebnis</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['rows'] as $index => $row): ?>
                    <?php
                    $selectedId = $row['selected_student_id'];
                    $selectedDuplicate = in_array($selectedId, $row['duplicate_student_ids'], true);
                    $rowCanImport = $row['errors'] === [] && $row['candidates'] !== [];
                    $availableCandidateIds = array_diff(
                        array_map(static fn (array $candidate): string => (string) $candidate['id'], $row['candidates']),
                        $row['duplicate_student_ids']
                    );
                    ?>
                    <tr>
                        <td><?= (int) $row['line'] ?></td>
                        <td><?= escape($row['values']['vorname'] . ' ' . $row['values']['nachname']) ?></td>
                        <td><?= escape($relationships[$row['values']['beziehung']] ?? $row['values']['beziehung']) ?></td>
                        <td><?= escape($row['values']['schuelerin_vorname'] . ' ' . $row['values']['schuelerin_nachname']) ?></td>
                        <td>
                            <?php if ($row['candidates'] === []): ?>
                                Keine passende Schülerin gefunden
                            <?php else: ?>
                                <label for="student_id_<?= (int) $index ?>">Schülerin zuordnen</label>
                                <select id="student_id_<?= (int) $index ?>" name="student_id[<?= (int) $index ?>]"<?= $row['errors'] === [] ? ' required' : '' ?>>
                                    <option value="">Bitte auswählen</option>
                                    <?php foreach ($row['candidates'] as $candidate): ?>
                                        <?php $candidateId = (string) $candidate['id']; ?>
                                        <?php $candidateLabel = $candidate['nachname'] . ', ' . $candidate['vorname'] . ' (ID ' . $candidateId . ')'; ?>
                                        <option value="<?= escape($candidateId) ?>"<?= $selectedId === $candidateId ? ' selected' : '' ?>>
                                            <?= escape($candidateLabel) ?>
                                            <?= in_array($candidateId, $row['duplicate_student_ids'], true) ? ' – Kontaktperson bereits vorhanden' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['errors'] !== []): ?>
                                <?= escape(implode(' ', $row['errors'])) ?>
                            <?php elseif (!$rowCanImport): ?>
                                Keine Zuordnung möglich.
                            <?php elseif ($selectedDuplicate): ?>
                                Diese Kontaktperson ist bereits vorhanden.
                            <?php elseif ($availableCandidateIds === []): ?>
                                Diese Kontaktperson ist bei allen Treffern bereits vorhanden.
                            <?php elseif (count($row['candidates']) > 1 && $selectedId === ''): ?>
                                Mehrere Treffer – bitte Schülerin auswählen.
                            <?php else: ?>
                                Import möglich.
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
