<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$studentIdValue = $_GET['schueler_id'] ?? null;
$studentId = is_string($studentIdValue)
    ? filter_var($studentIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($studentId === false) {
    http_response_code(400);
    exit('Ungültige Schülerinnen-ID.');
}

$studentStatement = $database->prepare('SELECT * FROM schueler WHERE id = :id');
$studentStatement->execute(['id' => $studentId]);
$student = $studentStatement->fetch();

if ($student === false) {
    http_response_code(404);
    exit('Der Datensatz der Schülerin wurde nicht gefunden.');
}

$guardianId = null;
$existingGuardian = null;
if (array_key_exists('id', $_GET)) {
    $guardianIdValue = $_GET['id'];
    $guardianId = is_string($guardianIdValue)
        ? filter_var($guardianIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if ($guardianId === false) {
        http_response_code(400);
        exit('Ungültige Kontaktpersonen-ID.');
    }

    $guardianStatement = $database->prepare(
        'SELECT * FROM erziehungsberechtigte WHERE id = :id AND schueler_id = :schueler_id'
    );
    $guardianStatement->execute([
        'id' => $guardianId,
        'schueler_id' => $studentId,
    ]);
    $existingGuardian = $guardianStatement->fetch();

    if ($existingGuardian === false) {
        http_response_code(404);
        exit('Die Kontaktperson wurde für diese Schülerin nicht gefunden.');
    }
}

$input = [
    'vorname' => '',
    'nachname' => '',
    'geburtsdatum' => '',
    'religion_id' => '',
    'staatsangehoerigkeit_id' => '',
    'religion_wie_schuelerin' => '0',
    'staatsangehoerigkeit_wie_schuelerin' => '0',
    'beziehung' => 'mutter',
    'erziehungsberechtigt' => '1',
    'strasse' => '',
    'plz' => '',
    'ort' => '',
    'adresse_wie_schuelerin' => '0',
    'telefon' => '',
    'mobil' => '',
    'email' => '',
];
$relationships = guardianRelationships();
$errors = [];

$religions = $database->query('SELECT id, name FROM religionen ORDER BY name, id')->fetchAll();
$nationalities = $database->query(
    'SELECT id, name FROM staatsangehoerigkeiten ORDER BY name, id'
)->fetchAll();

if ($existingGuardian !== null) {
    foreach (array_keys($input) as $field) {
        $value = $existingGuardian[$field] ?? null;
        $input[$field] = $value === null ? '' : (string) $value;
    }

    if ($input['adresse_wie_schuelerin'] === '1') {
        $input['strasse'] = $student['strasse'];
        $input['plz'] = $student['plz'] ?? '';
        $input['ort'] = $student['ort'];
    }
    if ($input['religion_wie_schuelerin'] === '1') {
        $input['religion_id'] = $student['religion_id'] === null
            ? ''
            : (string) $student['religion_id'];
    }
    if ($input['staatsangehoerigkeit_wie_schuelerin'] === '1') {
        $input['staatsangehoerigkeit_id'] = $student['staatsangehoerigkeit_id'] === null
            ? ''
            : (string) $student['staatsangehoerigkeit_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        foreach (array_keys($input) as $field) {
            $isCheckbox = in_array($field, [
                'religion_wie_schuelerin',
                'staatsangehoerigkeit_wie_schuelerin',
                'adresse_wie_schuelerin',
            ], true);
            $value = $_POST[$field] ?? ($isCheckbox ? '0' : '');

            if (!is_string($value)) {
                $errors[] = 'Ungültige Formulardaten.';
                continue;
            }

            $input[$field] = trim($value);
            if (preg_match('//u', $input[$field]) !== 1) {
                $errors[] = 'Ein Textfeld enthält ungültige Zeichen.';
            }
        }

        foreach (['vorname' => 100, 'nachname' => 100, 'strasse' => 200, 'ort' => 100, 'telefon' => 50, 'mobil' => 50, 'email' => 254] as $field => $maximumLength) {
            $length = preg_match_all('/./us', $input[$field]);
            if ($length === false || $length > $maximumLength || (in_array($field, ['vorname', 'nachname'], true) && $length === 0)) {
                $errors[] = sprintf('%s ist erforderlich oder überschreitet die maximale Länge.', $field);
            }
        }

        if ($input['geburtsdatum'] !== '') {
            $dateParts = [];
            if (
                preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $input['geburtsdatum'], $dateParts) !== 1
                || !checkdate((int) ($dateParts[2] ?? 0), (int) ($dateParts[3] ?? 0), (int) ($dateParts[1] ?? 0))
            ) {
                $errors[] = 'Bitte gib ein gültiges Geburtsdatum an oder lasse das Feld leer.';
            }
        }

        if ($input['plz'] !== '' && preg_match('/\A[0-9]{5}\z/', $input['plz']) !== 1) {
            $errors[] = 'Die Postleitzahl muss leer sein oder genau fünf Ziffern enthalten.';
        }

        if ($input['email'] !== '' && filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Bitte gib eine gültige E-Mail-Adresse an.';
        }

        if (!array_key_exists($input['beziehung'], $relationships)) {
            $errors[] = 'Bitte wähle eine gültige Beziehung aus.';
        }

        foreach (['religion_wie_schuelerin', 'staatsangehoerigkeit_wie_schuelerin', 'adresse_wie_schuelerin', 'erziehungsberechtigt'] as $field) {
            if (!in_array($input[$field], ['0', '1'], true)) {
                $errors[] = 'Ungültige Auswahl bei den Übernahmeoptionen.';
            }
        }

        foreach ([
            'religion_id' => 'religionen',
            'staatsangehoerigkeit_id' => 'staatsangehoerigkeiten',
        ] as $field => $table) {
            if ($input[$field] === '') {
                $input[$field] = null;
                continue;
            }

            $id = filter_var($input[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $errors[] = 'Eine ausgewählte Katalogangabe ist ungültig.';
                continue;
            }

            $lookup = $database->prepare(sprintf('SELECT 1 FROM %s WHERE id = :id', $table));
            $lookup->execute(['id' => $id]);
            if ($lookup->fetchColumn() === false) {
                $errors[] = 'Eine ausgewählte Katalogangabe ist nicht mehr verfügbar.';
            } else {
                $input[$field] = (string) $id;
            }
        }

        if ($input['adresse_wie_schuelerin'] === '1') {
            $input['strasse'] = $student['strasse'];
            $input['plz'] = $student['plz'] ?? '';
            $input['ort'] = $student['ort'];
        }
        if ($input['religion_wie_schuelerin'] === '1') {
            $input['religion_id'] = $student['religion_id'] === null
                ? null
                : (string) $student['religion_id'];
        }
        if ($input['staatsangehoerigkeit_wie_schuelerin'] === '1') {
            $input['staatsangehoerigkeit_id'] = $student['staatsangehoerigkeit_id'] === null
                ? null
                : (string) $student['staatsangehoerigkeit_id'];
        }

        if ($errors === []) {
            $values = [
                'schueler_id' => $studentId,
                'vorname' => $input['vorname'],
                'nachname' => $input['nachname'],
                'geburtsdatum' => $input['geburtsdatum'] === '' ? null : $input['geburtsdatum'],
                'religion_id' => $input['religion_id'],
                'staatsangehoerigkeit_id' => $input['staatsangehoerigkeit_id'],
                'religion_wie_schuelerin' => (int) $input['religion_wie_schuelerin'],
                'staatsangehoerigkeit_wie_schuelerin' => (int) $input['staatsangehoerigkeit_wie_schuelerin'],
                'beziehung' => $input['beziehung'],
                'erziehungsberechtigt' => (int) $input['erziehungsberechtigt'],
                'strasse' => $input['strasse'],
                'plz' => $input['plz'] === '' ? null : $input['plz'],
                'ort' => $input['ort'],
                'adresse_wie_schuelerin' => (int) $input['adresse_wie_schuelerin'],
                'telefon' => $input['telefon'],
                'mobil' => $input['mobil'],
                'email' => $input['email'],
            ];

            if ($guardianId === null) {
                $statement = $database->prepare(
                    'INSERT INTO erziehungsberechtigte (
                        schueler_id, vorname, nachname, geburtsdatum, religion_id,
                        staatsangehoerigkeit_id, religion_wie_schuelerin,
                        staatsangehoerigkeit_wie_schuelerin, beziehung,
                        erziehungsberechtigt, strasse, plz, ort, adresse_wie_schuelerin,
                        telefon, mobil, email
                    ) VALUES (
                        :schueler_id, :vorname, :nachname, :geburtsdatum, :religion_id,
                        :staatsangehoerigkeit_id, :religion_wie_schuelerin,
                        :staatsangehoerigkeit_wie_schuelerin, :beziehung,
                        :erziehungsberechtigt, :strasse, :plz, :ort, :adresse_wie_schuelerin,
                        :telefon, :mobil, :email
                    )'
                );
            } else {
                $statement = $database->prepare(
                    'UPDATE erziehungsberechtigte SET
                        vorname = :vorname,
                        nachname = :nachname,
                        geburtsdatum = :geburtsdatum,
                        religion_id = :religion_id,
                        staatsangehoerigkeit_id = :staatsangehoerigkeit_id,
                        religion_wie_schuelerin = :religion_wie_schuelerin,
                        staatsangehoerigkeit_wie_schuelerin = :staatsangehoerigkeit_wie_schuelerin,
                        beziehung = :beziehung,
                        erziehungsberechtigt = :erziehungsberechtigt,
                        strasse = :strasse,
                        plz = :plz,
                        ort = :ort,
                        adresse_wie_schuelerin = :adresse_wie_schuelerin,
                        telefon = :telefon,
                        mobil = :mobil,
                        email = :email
                     WHERE id = :id AND schueler_id = :schueler_id'
                );
                $values['id'] = $guardianId;
            }

            $statement->execute($values);
            header('Location: /schueler/zeigen.php?id=' . $studentId);
            exit;
        }
    }
}

$csrfToken = Auth::csrfToken();
$pageTitle = $guardianId === null ? 'Kontaktperson hinzufügen' : 'Kontaktperson bearbeiten';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= escape($csrfToken) ?>">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/catalog-add.js" defer></script>
    <title><?= escape($pageTitle) ?> – Schulverwaltung</title>
</head>
<body>
<main>
    <?= renderSchoolHeader($database) ?>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/schueler/zeigen.php?id=<?= (int) $studentId ?>">Zur Schülerinnenakte</a></p>
    <p><a href="/kataloge/">Auswahllisten pflegen</a></p>
    <h1><?= escape($pageTitle) ?></h1>
    <p>Kontaktperson für <?= escape($student['vorname'] . ' ' . $student['nachname']) ?>.</p>
    <?= renderAccountMenu($user) ?>

    <?php if ($errors !== []): ?>
        <div role="alert">
            <p>Bitte korrigiere folgende Angaben:</p>
            <ul>
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <fieldset>
            <legend>Person</legend>
            <p><label for="vorname">Vorname</label>
                <input id="vorname" name="vorname" maxlength="100" required value="<?= escape($input['vorname']) ?>"></p>
            <p><label for="nachname">Nachname</label>
                <input id="nachname" name="nachname" maxlength="100" required value="<?= escape($input['nachname']) ?>"></p>
            <p><label for="geburtsdatum">Geburtsdatum (optional)</label>
                <input id="geburtsdatum" name="geburtsdatum" type="date" value="<?= escape($input['geburtsdatum']) ?>"></p>
            <p><label for="beziehung">Beziehung zum Kind</label>
                <select id="beziehung" name="beziehung" required>
                    <?php foreach ($relationships as $value => $label): ?>
                        <option value="<?= escape($value) ?>" <?= $input['beziehung'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <p><label for="erziehungsberechtigt">Erziehungsberechtigt</label>
                <select id="erziehungsberechtigt" name="erziehungsberechtigt">
                    <option value="1" <?= $input['erziehungsberechtigt'] === '1' ? 'selected' : '' ?>>Ja</option>
                    <option value="0" <?= $input['erziehungsberechtigt'] === '0' ? 'selected' : '' ?>>Nein</option>
                </select></p>
        </fieldset>

        <fieldset>
            <legend>Adresse und Kontakt</legend>
            <p><label for="adresse_wie_schuelerin">Adresse des Kindes übernehmen</label>
                <input id="adresse_wie_schuelerin" name="adresse_wie_schuelerin" type="checkbox" value="1" <?= $input['adresse_wie_schuelerin'] === '1' ? 'checked' : '' ?>></p>
            <p><label for="strasse">Straße und Hausnummer</label>
                <input id="strasse" name="strasse" maxlength="200" value="<?= escape($input['strasse']) ?>"></p>
            <div class="form-grid form-grid--postal">
                <p><label for="plz">Postleitzahl</label>
                    <input id="plz" name="plz" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" value="<?= escape($input['plz']) ?>"></p>
                <p><label for="ort">Ort</label>
                    <input id="ort" name="ort" maxlength="100" value="<?= escape($input['ort']) ?>"></p>
            </div>
            <p><label for="telefon">Telefon</label>
                <input id="telefon" name="telefon" type="tel" maxlength="50" value="<?= escape($input['telefon']) ?>"></p>
            <p><label for="mobil">Mobil</label>
                <input id="mobil" name="mobil" type="tel" maxlength="50" value="<?= escape($input['mobil']) ?>"></p>
            <p><label for="email">E-Mail</label>
                <input id="email" name="email" type="email" maxlength="254" value="<?= escape($input['email']) ?>"></p>
        </fieldset>

        <fieldset>
            <legend>Religion und Staatsangehörigkeit</legend>
            <p><label for="religion_wie_schuelerin">Religion des Kindes übernehmen</label>
                <input id="religion_wie_schuelerin" name="religion_wie_schuelerin" type="checkbox" value="1" data-copy-from-student data-copy-select-id="religion_id" <?= $input['religion_wie_schuelerin'] === '1' ? 'checked' : '' ?>></p>
            <p><label for="religion_id">Religion</label>
                <select id="religion_id" name="religion_id">
                    <option value="">Keine Angabe</option>
                    <?php foreach ($religions as $religion): ?>
                        <option value="<?= (int) $religion['id'] ?>" <?= $input['religion_id'] === (string) $religion['id'] ? 'selected' : '' ?>><?= escape($religion['name']) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <div data-catalog-add data-catalog-type="religionen" data-select-id="religion_id" data-copy-checkbox="religion_wie_schuelerin" data-csrf-token="<?= escape($csrfToken) ?>">
                <label for="neue-religion">Religion direkt ergänzen</label>
                <input id="neue-religion" name="name" maxlength="50">
                <button type="button" data-catalog-submit>Hinzufügen</button>
                <span data-catalog-status role="status" aria-live="polite"></span>
            </div>
            <p><label for="staatsangehoerigkeit_wie_schuelerin">Staatsangehörigkeit des Kindes übernehmen</label>
                <input id="staatsangehoerigkeit_wie_schuelerin" name="staatsangehoerigkeit_wie_schuelerin" type="checkbox" value="1" data-copy-from-student data-copy-select-id="staatsangehoerigkeit_id" <?= $input['staatsangehoerigkeit_wie_schuelerin'] === '1' ? 'checked' : '' ?>></p>
            <p><label for="staatsangehoerigkeit_id">Staatsangehörigkeit</label>
                <select id="staatsangehoerigkeit_id" name="staatsangehoerigkeit_id">
                    <option value="">Keine Angabe</option>
                    <?php foreach ($nationalities as $nationality): ?>
                        <option value="<?= (int) $nationality['id'] ?>" <?= $input['staatsangehoerigkeit_id'] === (string) $nationality['id'] ? 'selected' : '' ?>><?= escape($nationality['name']) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <div data-catalog-add data-catalog-type="staatsangehoerigkeiten" data-select-id="staatsangehoerigkeit_id" data-copy-checkbox="staatsangehoerigkeit_wie_schuelerin" data-csrf-token="<?= escape($csrfToken) ?>">
                <label for="neue-staatsangehoerigkeit">Staatsangehörigkeit direkt ergänzen</label>
                <input id="neue-staatsangehoerigkeit" name="name" maxlength="100">
                <button type="button" data-catalog-submit>Hinzufügen</button>
                <span data-catalog-status role="status" aria-live="polite"></span>
            </div>
        </fieldset>
        <button type="submit">Speichern</button>
    </form>
</main>
</body>
</html>
