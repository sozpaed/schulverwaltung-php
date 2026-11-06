<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$editId = null;
if (array_key_exists('id', $_GET)) {
    $idValue = $_GET['id'];
    $editId = is_string($idValue)
        ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if ($editId === false) {
        http_response_code(400);
        exit('Ungültige Schülerinnen-ID.');
    }

    $studentStatement = $database->prepare('SELECT * FROM schueler WHERE id = :id');
    $studentStatement->execute(['id' => $editId]);
    $existingStudent = $studentStatement->fetch();

    if ($existingStudent === false) {
        http_response_code(404);
        exit('Der Datensatz der Schülerin wurde nicht gefunden.');
    }
}

$errors = [];
$input = [
    'vorname' => '',
    'nachname' => '',
    'geburtsdatum' => '',
    'strasse' => '',
    'plz' => '',
    'ort' => '',
    'geburtsort' => '',
    'religion_id' => '',
    'staatsangehoerigkeit_id' => '',
    'grundschule_id' => '',
    'anzahl_geschwister' => '0',
    'davon_an_der_marienschule' => '0',
    'freundin' => '',
    'deutsch' => '',
    'mathematik' => '',
    'sachkunde' => '',
    'av' => '',
    'sv' => '',
    'status' => 'in_pruefung',
    'schulform' => '',
    'klasse' => '',
    'warteliste' => '0',
];

$statuses = [
    'abgelehnt' => 'Abgelehnt',
    'angenommen' => 'Angenommen',
    'in_pruefung' => 'In Prüfung',
    'verzicht' => 'Verzicht',
];
$schoolForms = [
    '' => 'Noch nicht zugeordnet',
    'foerderstufe' => 'Förderstufe',
    'gymnasium' => 'Gymnasium',
];
$classes = [
    '' => 'Noch nicht zugeordnet',
    '5a' => '5a',
    '5b' => '5b',
    '5c' => '5c',
    '5d' => '5d',
    '5e' => '5e',
];

$religions = $database->query('SELECT id, name FROM religionen ORDER BY name, id')->fetchAll();
$nationalities = $database->query(
    'SELECT id, name FROM staatsangehoerigkeiten ORDER BY name, id'
)->fetchAll();
$schools = $database->query('SELECT id, name, ort FROM grundschulen ORDER BY name, ort, id')->fetchAll();

foreach ($nationalities as $nationality) {
    if ($editId === null && $nationality['name'] === 'Deutschland') {
        $input['staatsangehoerigkeit_id'] = (string) $nationality['id'];
        break;
    }
}

if ($editId !== null) {
    foreach (array_keys($input) as $field) {
        $value = $existingStudent[$field] ?? null;
        $input[$field] = $value === null ? '' : (string) $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        foreach (array_keys($input) as $field) {
            $value = $_POST[$field] ?? match ($field) {
                'anzahl_geschwister', 'davon_an_der_marienschule', 'warteliste' => '0',
                'status' => 'in_pruefung',
                default => '',
            };

            if (!is_string($value)) {
                $errors[] = 'Ungültige Formulardaten.';
                continue;
            }

            $input[$field] = trim($value);

            if (preg_match('//u', $input[$field]) !== 1) {
                $errors[] = 'Ein Textfeld enthält ungültige Zeichen.';
            }
        }

        $requiredFields = [
            'vorname' => ['Vorname', 100],
            'nachname' => ['Nachname', 100],
        ];

        foreach ($requiredFields as $field => [$label, $maximumLength]) {
            $length = preg_match_all('/./us', $input[$field]);
            if ($input[$field] === '' || $length === false || $length > $maximumLength) {
                $errors[] = sprintf('%s ist erforderlich und darf höchstens %d Zeichen enthalten.', $label, $maximumLength);
            }
        }

        foreach (['strasse' => ['Straße', 200], 'ort' => ['Wohnort', 100], 'geburtsort' => ['Geburtsort', 100]] as $field => [$label, $maximumLength]) {
            $length = preg_match_all('/./us', $input[$field]);
            if ($length === false || $length > $maximumLength) {
                $errors[] = sprintf('%s darf höchstens %d Zeichen enthalten.', $label, $maximumLength);
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

        if (
            preg_match_all('/./us', $input['freundin']) > 100
        ) {
            $errors[] = 'Freundin/Freund darf höchstens 100 Zeichen enthalten.';
        }

        foreach (['anzahl_geschwister', 'davon_an_der_marienschule'] as $field) {
            if ($input[$field] === '') {
                $input[$field] = '0';
            }
            $number = filter_var(
                $input[$field],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 4294967295]]
            );
            if ($number === false) {
                $errors[] = 'Geschwisterzahlen müssen nichtnegative Ganzzahlen sein.';
            } else {
                $input[$field] = (string) $number;
            }
        }

        if ((int) $input['davon_an_der_marienschule'] > (int) $input['anzahl_geschwister']) {
            $errors[] = 'Die Geschwister an der Marienschule dürfen nicht mehr als alle Geschwister sein.';
        }

        $grades = [];
        foreach (['deutsch', 'mathematik', 'sachkunde', 'av', 'sv'] as $field) {
            if ($input[$field] === '') {
                $grades[$field] = null;
                continue;
            }

            $grade = filter_var(
                $input[$field],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 6]]
            );
            if ($grade === false) {
                $errors[] = 'Noten müssen leer bleiben oder eine ganze Zahl von 1 bis 6 sein.';
            } else {
                $grades[$field] = $grade;
            }
        }

        foreach ([
            'religion_id' => ['religionen', true],
            'staatsangehoerigkeit_id' => ['staatsangehoerigkeiten', true],
            'grundschule_id' => ['grundschulen', true],
        ] as $field => [$table, $optional]) {
            if ($input[$field] === '' && $optional) {
                $input[$field] = null;
                continue;
            }

            $id = filter_var($input[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $errors[] = $field === 'staatsangehoerigkeit_id'
                    ? 'Bitte wähle eine Staatsangehörigkeit aus.'
                    : 'Eine ausgewählte Katalogangabe ist ungültig.';
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

        if (!array_key_exists($input['status'], $statuses)) {
            $errors[] = 'Bitte wähle einen gültigen Status.';
        }

        if (!array_key_exists($input['schulform'], $schoolForms)) {
            $errors[] = 'Bitte wähle eine gültige Schulform.';
        }

        if (!array_key_exists($input['klasse'], $classes)) {
            $errors[] = 'Bitte wähle eine gültige Klasse.';
        }

        if (!in_array($input['warteliste'], ['0', '1'], true)) {
            $errors[] = 'Bitte wähle einen gültigen Wartelistenstatus.';
        }

        if ($errors === []) {
            $values = [
                'vorname' => $input['vorname'],
                'nachname' => $input['nachname'],
                'geburtsdatum' => $input['geburtsdatum'] === '' ? null : $input['geburtsdatum'],
                'strasse' => $input['strasse'],
                'plz' => $input['plz'] === '' ? null : $input['plz'],
                'ort' => $input['ort'],
                'geburtsort' => $input['geburtsort'],
                'religion_id' => $input['religion_id'],
                'staatsangehoerigkeit_id' => $input['staatsangehoerigkeit_id'],
                'grundschule_id' => $input['grundschule_id'],
                'anzahl_geschwister' => (int) $input['anzahl_geschwister'],
                'davon_an_der_marienschule' => (int) $input['davon_an_der_marienschule'],
                'freundin' => $input['freundin'],
                'deutsch' => $grades['deutsch'],
                'mathematik' => $grades['mathematik'],
                'sachkunde' => $grades['sachkunde'],
                'av' => $grades['av'],
                'sv' => $grades['sv'],
                'status' => $input['status'],
                'schulform' => $input['schulform'],
                'klasse' => $input['klasse'],
                'warteliste' => (int) $input['warteliste'],
            ];

            if ($editId === null) {
                $statement = $database->prepare(
                    'INSERT INTO schueler (
                        vorname, nachname, geburtsdatum, strasse, plz, ort, geburtsort,
                        religion_id, staatsangehoerigkeit_id, grundschule_id,
                        anzahl_geschwister, davon_an_der_marienschule, freundin,
                        deutsch, mathematik, sachkunde, av, sv, status, schulform, klasse, warteliste
                    ) VALUES (
                        :vorname, :nachname, :geburtsdatum, :strasse, :plz, :ort, :geburtsort,
                        :religion_id, :staatsangehoerigkeit_id, :grundschule_id,
                        :anzahl_geschwister, :davon_an_der_marienschule, :freundin,
                        :deutsch, :mathematik, :sachkunde, :av, :sv, :status, :schulform, :klasse, :warteliste
                    )'
                );
            } else {
                $statement = $database->prepare(
                    'UPDATE schueler SET
                        vorname = :vorname,
                        nachname = :nachname,
                        geburtsdatum = :geburtsdatum,
                        strasse = :strasse,
                        plz = :plz,
                        ort = :ort,
                        geburtsort = :geburtsort,
                        religion_id = :religion_id,
                        staatsangehoerigkeit_id = :staatsangehoerigkeit_id,
                        grundschule_id = :grundschule_id,
                        anzahl_geschwister = :anzahl_geschwister,
                        davon_an_der_marienschule = :davon_an_der_marienschule,
                        freundin = :freundin,
                        deutsch = :deutsch,
                        mathematik = :mathematik,
                        sachkunde = :sachkunde,
                        av = :av,
                        sv = :sv,
                        status = :status,
                        schulform = :schulform,
                        klasse = :klasse,
                        warteliste = :warteliste
                     WHERE id = :id'
                );
                $values['id'] = $editId;
            }

            $statement->execute($values);

            header('Location: /schueler/');
            exit;
        }
    }
}

$csrfToken = Auth::csrfToken();
$enteredGrades = [];
foreach (['deutsch', 'mathematik', 'sachkunde'] as $field) {
    if (preg_match('/\A[1-6]\z/', $input[$field]) === 1) {
        $enteredGrades[] = (int) $input[$field];
    }
}
$average = $enteredGrades === []
    ? null
    : number_format(array_sum($enteredGrades) / count($enteredGrades), 2, ',', '.');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= escape($csrfToken) ?>">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/catalog-add.js" defer></script>
    <script src="/assets/grade-average.js" defer></script>
    <title><?= $editId === null ? 'Schülerin anlegen' : 'Schülerinnendatensatz bearbeiten' ?> – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/schueler/">Zur Schülerinnenübersicht</a></p>
    <p><a href="/kataloge/">Auswahllisten pflegen</a></p>
    <h1><?= $editId === null ? 'Schülerin anlegen' : 'Schülerinnendatensatz bearbeiten' ?></h1>
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
                <legend>Stammdaten</legend>
                <p><label for="vorname">Vorname</label>
                    <input id="vorname" name="vorname" maxlength="100" required value="<?= escape($input['vorname']) ?>"></p>
                <p><label for="nachname">Nachname</label>
                    <input id="nachname" name="nachname" maxlength="100" required value="<?= escape($input['nachname']) ?>"></p>
                <p><label for="geburtsdatum">Geburtsdatum</label>
                    <input id="geburtsdatum" name="geburtsdatum" type="date" value="<?= escape($input['geburtsdatum']) ?>"></p>
                <p><label for="strasse">Straße und Hausnummer</label>
                    <input id="strasse" name="strasse" maxlength="200" value="<?= escape($input['strasse']) ?>"></p>
                <div class="form-grid form-grid--postal">
                    <p><label for="plz">Postleitzahl</label>
                        <input id="plz" name="plz" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" value="<?= escape($input['plz']) ?>"></p>
                    <p><label for="ort">Wohnort</label>
                        <input id="ort" name="ort" maxlength="100" value="<?= escape($input['ort']) ?>"></p>
                </div>
                <p><label for="geburtsort">Geburtsort</label>
                    <input id="geburtsort" name="geburtsort" maxlength="100" value="<?= escape($input['geburtsort']) ?>"></p>
                <p><label for="religion_id">Religion</label>
                    <select id="religion_id" name="religion_id">
                        <option value="">Keine Angabe</option>
                        <?php foreach ($religions as $religion): ?>
                            <option value="<?= (int) $religion['id'] ?>" <?= $input['religion_id'] === (string) $religion['id'] ? 'selected' : '' ?>><?= escape($religion['name']) ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <div data-catalog-add data-catalog-type="religionen" data-select-id="religion_id" data-csrf-token="<?= escape($csrfToken) ?>">
                    <label for="neue-religion">Religion direkt ergänzen</label>
                    <input id="neue-religion" name="name" maxlength="50">
                    <button type="button" data-catalog-submit>Hinzufügen</button>
                    <span data-catalog-status role="status" aria-live="polite"></span>
                </div>
                <p><label for="staatsangehoerigkeit_id">Staatsangehörigkeit</label>
                    <select id="staatsangehoerigkeit_id" name="staatsangehoerigkeit_id">
                        <option value="">Keine Angabe</option>
                        <?php foreach ($nationalities as $nationality): ?>
                            <option value="<?= (int) $nationality['id'] ?>" <?= $input['staatsangehoerigkeit_id'] === (string) $nationality['id'] ? 'selected' : '' ?>><?= escape($nationality['name']) ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <div data-catalog-add data-catalog-type="staatsangehoerigkeiten" data-select-id="staatsangehoerigkeit_id" data-csrf-token="<?= escape($csrfToken) ?>">
                    <label for="neue-staatsangehoerigkeit">Staatsangehörigkeit direkt ergänzen</label>
                    <input id="neue-staatsangehoerigkeit" name="name" maxlength="100">
                    <button type="button" data-catalog-submit>Hinzufügen</button>
                    <span data-catalog-status role="status" aria-live="polite"></span>
                </div>
                <p><label for="grundschule_id">Grundschule</label>
                    <select id="grundschule_id" name="grundschule_id">
                        <option value="">Keine Angabe</option>
                        <?php foreach ($schools as $school): ?>
                            <?php $schoolLabel = $school['ort'] === '' ? $school['name'] : $school['name'] . ' (' . $school['ort'] . ')'; ?>
                            <option value="<?= (int) $school['id'] ?>" <?= $input['grundschule_id'] === (string) $school['id'] ? 'selected' : '' ?>><?= escape($schoolLabel) ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <div data-catalog-add data-catalog-type="grundschulen" data-select-id="grundschule_id" data-csrf-token="<?= escape($csrfToken) ?>">
                    <label for="neue-grundschule">Grundschule direkt ergänzen</label>
                    <input id="neue-grundschule" name="name" maxlength="200" placeholder="Name">
                    <label for="neuer-grundschulort">Ort (optional)</label>
                    <input id="neuer-grundschulort" name="katalog_ort" maxlength="100">
                    <button type="button" data-catalog-submit>Hinzufügen</button>
                    <span data-catalog-status role="status" aria-live="polite"></span>
                </div>
                <div class="form-grid form-grid--two">
                    <p><label for="anzahl_geschwister">Anzahl Geschwister</label>
                        <input id="anzahl_geschwister" name="anzahl_geschwister" type="number" min="0" max="4294967295" value="<?= escape($input['anzahl_geschwister']) ?>"></p>
                    <p><label for="davon_an_der_marienschule">Davon an der Marienschule</label>
                        <input id="davon_an_der_marienschule" name="davon_an_der_marienschule" type="number" min="0" max="4294967295" value="<?= escape($input['davon_an_der_marienschule']) ?>"></p>
                </div>
                <p><label for="freundin">Freundin/Freund</label>
                    <input id="freundin" name="freundin" maxlength="100" value="<?= escape($input['freundin']) ?>"></p>
            </fieldset>

            <fieldset>
                <legend>Noten (optional)</legend>
                <div class="form-grid form-grid--four">
                <?php foreach (['deutsch' => 'Deutsch', 'mathematik' => 'Mathematik', 'sachkunde' => 'Sachkunde'] as $field => $label): ?>
                    <p><label for="<?= $field ?>"><?= $label ?></label>
                        <input id="<?= $field ?>" name="<?= $field ?>" type="number" min="1" max="6" value="<?= escape($input[$field]) ?>"></p>
                <?php endforeach; ?>
                    <p class="grade-average">
                        <label for="durchschnitt">Durchschnitt</label>
                        <output id="durchschnitt" aria-live="polite"><?= escape($average ?? '–') ?></output>
                    </p>
                </div>
                <div class="form-grid form-grid--two">
                <?php foreach (['av' => 'Arbeitsverhalten', 'sv' => 'Sozialverhalten'] as $field => $label): ?>
                    <p><label for="<?= $field ?>"><?= $label ?></label>
                        <input id="<?= $field ?>" name="<?= $field ?>" type="number" min="1" max="6" value="<?= escape($input[$field]) ?>"></p>
                <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset>
                <legend>Interne Angaben</legend>
                <p><label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= escape($value) ?>" <?= $input['status'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <p><label for="schulform">Schulform</label>
                    <select id="schulform" name="schulform">
                        <?php foreach ($schoolForms as $value => $label): ?>
                            <option value="<?= escape($value) ?>" <?= $input['schulform'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <p><label for="klasse">Klasse</label>
                    <select id="klasse" name="klasse">
                        <?php foreach ($classes as $value => $label): ?>
                            <option value="<?= escape($value) ?>" <?= $input['klasse'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                        <?php endforeach; ?>
                    </select></p>
                <p><label for="warteliste">Warteliste</label>
                    <select id="warteliste" name="warteliste">
                        <option value="0" <?= $input['warteliste'] === '0' ? 'selected' : '' ?>>Nein</option>
                        <option value="1" <?= $input['warteliste'] === '1' ? 'selected' : '' ?>>Ja</option>
                    </select></p>
            </fieldset>
            <button type="submit"><?= $editId === null ? 'Schülerin anlegen' : 'Änderungen speichern' ?></button>
        </form>
</main>
</body>
</html>
