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
    exit('Ungültige Schüler-ID.');
}

$studentStatement = $database->prepare('SELECT id, vorname, nachname FROM schueler WHERE id = :id');
$studentStatement->execute(['id' => $studentId]);
$student = $studentStatement->fetch();

if ($student === false) {
    http_response_code(404);
    exit('Der Schülerdatensatz wurde nicht gefunden.');
}

$conversationId = null;
$existingConversation = null;
if (array_key_exists('id', $_GET)) {
    $conversationIdValue = $_GET['id'];
    $conversationId = is_string($conversationIdValue)
        ? filter_var($conversationIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if ($conversationId === false) {
        http_response_code(400);
        exit('Ungültige Gesprächs-ID.');
    }

    $conversationStatement = $database->prepare(
        'SELECT * FROM gespraeche WHERE id = :id AND schueler_id = :schueler_id'
    );
    $conversationStatement->execute([
        'id' => $conversationId,
        'schueler_id' => $studentId,
    ]);
    $existingConversation = $conversationStatement->fetch();

    if ($existingConversation === false) {
        http_response_code(404);
        exit('Das Gespräch wurde für diesen Schüler nicht gefunden.');
    }
}

$fields = [
    'datum' => '',
    'gewuenschte_schulform' => 'unklar',
    'offenheit_christlicher_ru' => '',
    'anwesend_ausser_tochter' => 'mutter',
    'erstkontakt_tdot' => '0',
    'erstkontakt_forscherinnennachmittag' => '0',
    'erstkontakt_workshop_jakoby' => '0',
    'hobbys_lieblingsfaecher_ags' => '',
    'warum_diese_schule' => '',
    'kirchliche_sozialisation' => '',
    'sonstiges' => '',
    'foerderbedarf_lrs' => '0',
    'foerderbedarf_dyskalkulie' => '0',
    'foerderbedarf_tha' => '0',
    'elternengagement' => '',
    'aufnahmeempfehlung' => '',
];
$schoolForms = [
    'gymnasium' => 'Gymnasium',
    'foerderstufe_realschule' => 'Förderstufe/Realschule',
    'unklar' => 'Noch unklar',
];
$religiousEducationOptions = [
    '' => 'Keine Angabe',
    'katholisch' => 'Katholisch',
    'evangelisch' => 'Evangelisch',
    'nein' => 'Nein',
];
$attendees = [
    'mutter' => 'Mutter',
    'vater' => 'Vater',
    'mutter_vater' => 'Mutter und Vater',
    'sonstige' => 'Sonstige',
];
$recommendations = [
    '' => 'Noch keine Empfehlung',
    'ja' => 'Ja',
    'vielleicht' => 'Vielleicht',
    'nein' => 'Nein',
];
$checkboxFields = [
    'erstkontakt_tdot' => 'Tag der offenen Tür',
    'erstkontakt_forscherinnennachmittag' => 'Forscherinnennachmittag',
    'erstkontakt_workshop_jakoby' => 'Workshop Jakoby',
    'foerderbedarf_lrs' => 'LRS',
    'foerderbedarf_dyskalkulie' => 'Dyskalkulie',
    'foerderbedarf_tha' => 'THA',
];
$textFields = [
    'hobbys_lieblingsfaecher_ags' => 'Hobbys, Lieblingsfächer und AGs',
    'warum_diese_schule' => 'Warum diese Schule?',
    'kirchliche_sozialisation' => 'Kirchliche Sozialisation',
    'sonstiges' => 'Sonstiges',
    'elternengagement' => 'Elternengagement',
];
$channels = $database->query('SELECT id, name FROM marketing_kanaele ORDER BY name, id')->fetchAll();
$selectedChannelIds = [];
$errors = [];

if ($existingConversation !== null) {
    foreach (array_keys($fields) as $field) {
        if ($field === 'datum') {
            $fields[$field] = str_replace(' ', 'T', substr($existingConversation[$field], 0, 16));
        } else {
            $fields[$field] = (string) $existingConversation[$field];
        }
    }

    $channelStatement = $database->prepare(
        'SELECT marketing_kanal_id
         FROM gespraech_marketing_kanaele
         WHERE gespraech_id = :gespraech_id'
    );
    $channelStatement->execute(['gespraech_id' => $conversationId]);
    $selectedChannelIds = array_map('strval', $channelStatement->fetchAll(PDO::FETCH_COLUMN));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedChannelIds = [];

    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        foreach (array_keys($fields) as $field) {
            $isCheckbox = array_key_exists($field, $checkboxFields);
            $value = $_POST[$field] ?? ($isCheckbox ? '0' : '');

            if (!is_string($value)) {
                $errors[] = 'Ungültige Formulardaten.';
                continue;
            }

            $fields[$field] = trim($value);
            if (preg_match('//u', $fields[$field]) !== 1) {
                $errors[] = 'Ein Textfeld enthält ungültige Zeichen.';
            }
        }

        foreach (array_keys($checkboxFields) as $field) {
            if (!in_array($fields[$field], ['0', '1'], true)) {
                $errors[] = 'Ungültige Auswahl bei den Kontrollkästchen.';
            }
        }

        if (
            preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})\z/', $fields['datum'], $dateParts) !== 1
            || !checkdate((int) ($dateParts[2] ?? 0), (int) ($dateParts[3] ?? 0), (int) ($dateParts[1] ?? 0))
            || (int) ($dateParts[4] ?? 24) > 23
            || (int) ($dateParts[5] ?? 60) > 59
        ) {
            $errors[] = 'Bitte gib ein gültiges Datum und eine gültige Uhrzeit an.';
        }

        if (!array_key_exists($fields['gewuenschte_schulform'], $schoolForms)) {
            $errors[] = 'Bitte wähle eine gültige gewünschte Schulform.';
        }
        if (!array_key_exists($fields['offenheit_christlicher_ru'], $religiousEducationOptions)) {
            $errors[] = 'Bitte wähle eine gültige Angabe zur Offenheit für christlichen Religionsunterricht.';
        }
        if (!array_key_exists($fields['anwesend_ausser_tochter'], $attendees)) {
            $errors[] = 'Bitte wähle eine gültige Angabe zu den anwesenden Personen.';
        }
        if (!array_key_exists($fields['aufnahmeempfehlung'], $recommendations)) {
            $errors[] = 'Bitte wähle eine gültige Aufnahmeempfehlung.';
        }

        $channelInput = $_POST['marketing_kanal_ids'] ?? [];
        if (!is_array($channelInput)) {
            $errors[] = 'Ungültige Auswahl bei den Marketingkanälen.';
        } else {
            foreach ($channelInput as $channelInputId) {
                if (!is_string($channelInputId)) {
                    $errors[] = 'Ungültige Auswahl bei den Marketingkanälen.';
                    continue;
                }

                $channelId = filter_var(
                    $channelInputId,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($channelId === false) {
                    $errors[] = 'Ungültige Auswahl bei den Marketingkanälen.';
                    continue;
                }

                $selectedChannelIds[] = (string) $channelId;
            }
            $selectedChannelIds = array_values(array_unique($selectedChannelIds));

            $availableChannelIds = array_map(
                'strval',
                array_column($channels, 'id')
            );
            if (array_diff($selectedChannelIds, $availableChannelIds) !== []) {
                $errors[] = 'Ein ausgewählter Marketingkanal ist nicht mehr verfügbar.';
            }
        }

        if ($errors === []) {
            $values = [
                'schueler_id' => $studentId,
                'protokollant_id' => $user['id'],
                'datum' => str_replace('T', ' ', $fields['datum']) . ':00',
                'gewuenschte_schulform' => $fields['gewuenschte_schulform'],
                'offenheit_christlicher_ru' => $fields['offenheit_christlicher_ru'],
                'anwesend_ausser_tochter' => $fields['anwesend_ausser_tochter'],
                'erstkontakt_tdot' => (int) $fields['erstkontakt_tdot'],
                'erstkontakt_forscherinnennachmittag' => (int) $fields['erstkontakt_forscherinnennachmittag'],
                'erstkontakt_workshop_jakoby' => (int) $fields['erstkontakt_workshop_jakoby'],
                'hobbys_lieblingsfaecher_ags' => $fields['hobbys_lieblingsfaecher_ags'],
                'warum_diese_schule' => $fields['warum_diese_schule'],
                'kirchliche_sozialisation' => $fields['kirchliche_sozialisation'],
                'sonstiges' => $fields['sonstiges'],
                'foerderbedarf_lrs' => (int) $fields['foerderbedarf_lrs'],
                'foerderbedarf_dyskalkulie' => (int) $fields['foerderbedarf_dyskalkulie'],
                'foerderbedarf_tha' => (int) $fields['foerderbedarf_tha'],
                'elternengagement' => $fields['elternengagement'],
                'aufnahmeempfehlung' => $fields['aufnahmeempfehlung'],
            ];

            $database->beginTransaction();
            try {
                if ($conversationId === null) {
                    $statement = $database->prepare(
                        'INSERT INTO gespraeche (
                            schueler_id, protokollant_id, datum, gewuenschte_schulform,
                            offenheit_christlicher_ru, anwesend_ausser_tochter,
                            erstkontakt_tdot, erstkontakt_forscherinnennachmittag,
                            erstkontakt_workshop_jakoby, hobbys_lieblingsfaecher_ags,
                            warum_diese_schule, kirchliche_sozialisation, sonstiges,
                            foerderbedarf_lrs, foerderbedarf_dyskalkulie, foerderbedarf_tha,
                            elternengagement, aufnahmeempfehlung
                        ) VALUES (
                            :schueler_id, :protokollant_id, :datum, :gewuenschte_schulform,
                            :offenheit_christlicher_ru, :anwesend_ausser_tochter,
                            :erstkontakt_tdot, :erstkontakt_forscherinnennachmittag,
                            :erstkontakt_workshop_jakoby, :hobbys_lieblingsfaecher_ags,
                            :warum_diese_schule, :kirchliche_sozialisation, :sonstiges,
                            :foerderbedarf_lrs, :foerderbedarf_dyskalkulie, :foerderbedarf_tha,
                            :elternengagement, :aufnahmeempfehlung
                        )'
                    );
                    $statement->execute($values);
                    $conversationId = (int) $database->lastInsertId();
                } else {
                    $statement = $database->prepare(
                        'UPDATE gespraeche SET
                            protokollant_id = :protokollant_id,
                            datum = :datum,
                            gewuenschte_schulform = :gewuenschte_schulform,
                            offenheit_christlicher_ru = :offenheit_christlicher_ru,
                            anwesend_ausser_tochter = :anwesend_ausser_tochter,
                            erstkontakt_tdot = :erstkontakt_tdot,
                            erstkontakt_forscherinnennachmittag = :erstkontakt_forscherinnennachmittag,
                            erstkontakt_workshop_jakoby = :erstkontakt_workshop_jakoby,
                            hobbys_lieblingsfaecher_ags = :hobbys_lieblingsfaecher_ags,
                            warum_diese_schule = :warum_diese_schule,
                            kirchliche_sozialisation = :kirchliche_sozialisation,
                            sonstiges = :sonstiges,
                            foerderbedarf_lrs = :foerderbedarf_lrs,
                            foerderbedarf_dyskalkulie = :foerderbedarf_dyskalkulie,
                            foerderbedarf_tha = :foerderbedarf_tha,
                            elternengagement = :elternengagement,
                            aufnahmeempfehlung = :aufnahmeempfehlung
                         WHERE id = :id AND schueler_id = :schueler_id'
                    );
                    $values['id'] = $conversationId;
                    $statement->execute($values);
                }

                $deleteChannels = $database->prepare(
                    'DELETE FROM gespraech_marketing_kanaele WHERE gespraech_id = :gespraech_id'
                );
                $deleteChannels->execute(['gespraech_id' => $conversationId]);

                if ($selectedChannelIds !== []) {
                    $insertChannel = $database->prepare(
                        'INSERT INTO gespraech_marketing_kanaele (gespraech_id, marketing_kanal_id)
                         VALUES (:gespraech_id, :marketing_kanal_id)'
                    );
                    foreach ($selectedChannelIds as $channelId) {
                        $insertChannel->execute([
                            'gespraech_id' => $conversationId,
                            'marketing_kanal_id' => (int) $channelId,
                        ]);
                    }
                }

                $database->commit();
            } catch (Throwable $exception) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                throw $exception;
            }

            header('Location: /schueler/zeigen.php?id=' . $studentId);
            exit;
        }
    }
}

$csrfToken = Auth::csrfToken();
$studentName = $student['vorname'] . ' ' . $student['nachname'];
$pageTitle = $conversationId === null
    ? 'Gespräch erfassen für ' . $studentName
    : 'Gespräch bearbeiten für ' . $studentName;
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
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/schueler/zeigen.php?id=<?= (int) $studentId ?>">Zur Schülerakte</a></p>
    <p><a href="/kataloge/">Auswahllisten pflegen</a></p>
    <h1><?= escape($pageTitle) ?></h1>
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
            <legend>Gespräch</legend>
            <p><label for="datum">Datum und Uhrzeit</label>
                <input id="datum" name="datum" type="datetime-local" required value="<?= escape($fields['datum']) ?>"></p>
            <p><label for="gewuenschte_schulform">Gewünschte Schulform</label>
                <select id="gewuenschte_schulform" name="gewuenschte_schulform" required>
                    <?php foreach ($schoolForms as $value => $label): ?>
                        <option value="<?= escape($value) ?>" <?= $fields['gewuenschte_schulform'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <p><label for="offenheit_christlicher_ru">Offenheit für christlichen Religionsunterricht</label>
                <select id="offenheit_christlicher_ru" name="offenheit_christlicher_ru">
                    <?php foreach ($religiousEducationOptions as $value => $label): ?>
                        <option value="<?= escape($value) ?>" <?= $fields['offenheit_christlicher_ru'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
            <p><label for="anwesend_ausser_tochter">Anwesend außer dem Kind</label>
                <select id="anwesend_ausser_tochter" name="anwesend_ausser_tochter" required>
                    <?php foreach ($attendees as $value => $label): ?>
                        <option value="<?= escape($value) ?>" <?= $fields['anwesend_ausser_tochter'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
        </fieldset>

        <fieldset>
            <legend>Erstkontakt und Marketingkanäle</legend>
            <div class="choice-grid choice-grid--three">
            <?php foreach (array_slice($checkboxFields, 0, 3, true) as $field => $label): ?>
                <p><input id="<?= $field ?>" name="<?= $field ?>" type="checkbox" value="1" <?= $fields[$field] === '1' ? 'checked' : '' ?>>
                    <label for="<?= $field ?>"><?= escape($label) ?></label></p>
            <?php endforeach; ?>
            </div>
            <fieldset>
                <legend>Wie auf die Schule aufmerksam geworden?</legend>
                <div id="marketing-kanal-auswahl" class="choice-grid">
                    <?php foreach ($channels as $channel): ?>
                        <p><input id="kanal-<?= (int) $channel['id'] ?>" name="marketing_kanal_ids[]" type="checkbox"
                                   value="<?= (int) $channel['id'] ?>"
                                <?= in_array((string) $channel['id'], $selectedChannelIds, true) ? 'checked' : '' ?>>
                            <label for="kanal-<?= (int) $channel['id'] ?>"><?= escape($channel['name']) ?></label></p>
                    <?php endforeach; ?>
                </div>
                <div data-catalog-add data-catalog-type="marketing_kanaele" data-select-id="marketing-kanal-auswahl" data-csrf-token="<?= escape($csrfToken) ?>">
                    <label for="neuer-marketingkanal">Marketingkanal direkt ergänzen</label>
                    <input id="neuer-marketingkanal" name="name" maxlength="100">
                    <button type="button" data-catalog-submit>Hinzufügen</button>
                    <p data-catalog-empty <?= $channels === [] ? '' : 'hidden' ?>>Noch keine Marketingkanäle vorhanden.</p>
                    <span data-catalog-status role="status" aria-live="polite"></span>
                </div>
            </fieldset>
        </fieldset>

        <fieldset>
            <legend>Gesprächsnotizen</legend>
            <?php foreach ($textFields as $field => $label): ?>
                <p><label for="<?= $field ?>"><?= escape($label) ?></label>
                    <textarea id="<?= $field ?>" name="<?= $field ?>" rows="4"><?= escape($fields[$field]) ?></textarea></p>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>Förderbedarf</legend>
            <div class="choice-grid choice-grid--three">
            <?php foreach (array_slice($checkboxFields, 3, null, true) as $field => $label): ?>
                <p><input id="<?= $field ?>" name="<?= $field ?>" type="checkbox" value="1" <?= $fields[$field] === '1' ? 'checked' : '' ?>>
                    <label for="<?= $field ?>"><?= escape($label) ?></label></p>
            <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>Empfehlung</legend>
            <p><label for="aufnahmeempfehlung">Aufnahmeempfehlung</label>
                <select id="aufnahmeempfehlung" name="aufnahmeempfehlung">
                    <?php foreach ($recommendations as $value => $label): ?>
                        <option value="<?= escape($value) ?>" <?= $fields['aufnahmeempfehlung'] === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
                    <?php endforeach; ?>
                </select></p>
        </fieldset>
        <button type="submit">Gespräch speichern</button>
    </form>
</main>
</body>
</html>
