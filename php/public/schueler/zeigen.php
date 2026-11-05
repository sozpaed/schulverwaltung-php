<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$idValue = $_GET['id'] ?? null;
$studentId = is_string($idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($studentId === false) {
    http_response_code(400);
    exit('Ungültige Schüler-ID.');
}

$statement = $database->prepare(
    'SELECT s.*, r.name AS religion, n.name AS staatsangehoerigkeit,
            g.name AS grundschule, g.ort AS grundschule_ort
     FROM schueler s
     LEFT JOIN religionen r ON r.id = s.religion_id
     LEFT JOIN staatsangehoerigkeiten n ON n.id = s.staatsangehoerigkeit_id
     LEFT JOIN grundschulen g ON g.id = s.grundschule_id
     WHERE s.id = :id'
);
$statement->execute(['id' => $studentId]);
$student = $statement->fetch();

if ($student === false) {
    http_response_code(404);
    exit('Der Schülerdatensatz wurde nicht gefunden.');
}

$guardianStatement = $database->prepare(
    'SELECT id, vorname, nachname, beziehung, erziehungsberechtigt,
            telefon, mobil, email
     FROM erziehungsberechtigte
     WHERE schueler_id = :schueler_id
     ORDER BY nachname, vorname, id'
);
$guardianStatement->execute(['schueler_id' => $studentId]);
$guardians = $guardianStatement->fetchAll();

$conversationStatement = $database->prepare(
    'SELECT g.id, g.datum, g.gewuenschte_schulform, g.aufnahmeempfehlung, u.username
     FROM gespraeche g
     LEFT JOIN app_users u ON u.id = g.protokollant_id
     WHERE g.schueler_id = :schueler_id
     ORDER BY g.datum DESC, g.id DESC'
);
$conversationStatement->execute(['schueler_id' => $studentId]);
$conversations = $conversationStatement->fetchAll();

$statusLabels = [
    'abgelehnt' => 'Abgelehnt',
    'angenommen' => 'Angenommen',
    'in_pruefung' => 'In Prüfung',
    'verzicht' => 'Verzicht',
];
$schoolFormLabels = [
    '' => 'Nicht zugeordnet',
    'foerderstufe' => 'Förderstufe',
    'gymnasium' => 'Gymnasium',
];
$conversationSchoolFormLabels = [
    'gymnasium' => 'Gymnasium',
    'foerderstufe_realschule' => 'Förderstufe/Realschule',
    'unklar' => 'Noch unklar',
];
$csrfToken = \Schulverwaltung\Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($student['nachname'] . ', ' . $student['vorname']) ?> – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/schueler/">Zur Schülerübersicht</a></p>
    <h1><?= escape($student['vorname'] . ' ' . $student['nachname']) ?></h1>
    <p>Angemeldet als <?= escape($user['username']) ?>.</p>

    <section>
        <h2>Schülerdaten</h2>
        <p><a href="/schueler/bearbeiten.php?id=<?= (int) $student['id'] ?>">Schülerdaten bearbeiten</a></p>
        <dl>
            <dt>Geburtsdatum</dt><dd><?= escape($student['geburtsdatum']) ?></dd>
            <dt>Adresse</dt><dd><?= escape($student['strasse'] . ', ' . $student['plz'] . ' ' . $student['ort']) ?></dd>
            <dt>Geburtsort</dt><dd><?= escape($student['geburtsort']) ?></dd>
            <dt>Religion</dt><dd><?= escape($student['religion'] ?? '') ?></dd>
            <dt>Staatsangehörigkeit</dt><dd><?= escape($student['staatsangehoerigkeit'] ?? '') ?></dd>
            <dt>Grundschule</dt>
            <dd>
                <?php if ($student['grundschule'] !== null): ?>
                    <?= escape($student['grundschule'] . ($student['grundschule_ort'] === '' ? '' : ' (' . $student['grundschule_ort'] . ')')) ?>
                <?php endif; ?>
            </dd>
            <dt>Status</dt><dd><?= escape($statusLabels[$student['status']] ?? 'Unbekannt') ?></dd>
            <dt>Schulform</dt><dd><?= escape($schoolFormLabels[$student['schulform']] ?? 'Unbekannt') ?></dd>
            <dt>Klasse</dt><dd><?= escape($student['klasse']) ?></dd>
            <dt>Warteliste</dt><dd><?= (bool) $student['warteliste'] ? 'Ja' : 'Nein' ?></dd>
        </dl>
    </section>

    <section>
        <h2>Aufnahmegespräche</h2>
        <p><a href="/schueler/gespraech.php?schueler_id=<?= (int) $student['id'] ?>">Gespräch erfassen</a></p>
        <?php if ($conversations === []): ?>
            <p>Für diesen Datensatz sind noch keine Gespräche hinterlegt.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th scope="col">Datum</th>
                    <th scope="col">Gewünschte Schulform</th>
                    <th scope="col">Empfehlung</th>
                    <th scope="col">Protokollant</th>
                    <th scope="col">Aktion</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($conversations as $conversation): ?>
                    <tr>
                        <td><?= escape(date('d.m.Y H:i', strtotime($conversation['datum']))) ?></td>
                        <td><?= escape($conversationSchoolFormLabels[$conversation['gewuenschte_schulform']] ?? 'Unbekannt') ?></td>
                        <td><?= escape($conversation['aufnahmeempfehlung'] === '' ? 'Offen' : ucfirst($conversation['aufnahmeempfehlung'])) ?></td>
                        <td><?= escape($conversation['username'] ?? 'Unbekannt') ?></td>
                        <td><a href="/schueler/gespraech.php?schueler_id=<?= (int) $student['id'] ?>&amp;id=<?= (int) $conversation['id'] ?>">Bearbeiten</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section>
        <h2>Erziehungsberechtigte und Kontaktpersonen</h2>
        <p><a href="/schueler/erziehungsberechtigter.php?schueler_id=<?= (int) $student['id'] ?>">Kontaktperson hinzufügen</a></p>
        <?php if ($guardians === []): ?>
            <p>Für diesen Datensatz sind noch keine Kontaktpersonen hinterlegt.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Beziehung</th>
                    <th scope="col">Erziehungsberechtigt</th>
                    <th scope="col">Telefon</th>
                    <th scope="col">Mobil</th>
                    <th scope="col">E-Mail</th>
                    <th scope="col">Aktion</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($guardians as $guardian): ?>
                    <tr>
                        <td><?= escape($guardian['vorname'] . ' ' . $guardian['nachname']) ?></td>
                        <td><?= escape($guardian['beziehung']) ?></td>
                        <td><?= (bool) $guardian['erziehungsberechtigt'] ? 'Ja' : 'Nein' ?></td>
                        <td><?= escape($guardian['telefon']) ?></td>
                        <td><?= escape($guardian['mobil']) ?></td>
                        <td><?= escape($guardian['email']) ?></td>
                        <td><a href="/schueler/erziehungsberechtigter.php?schueler_id=<?= (int) $student['id'] ?>&amp;id=<?= (int) $guardian['id'] ?>">Bearbeiten</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <form method="post" action="/">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
