<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_delete_students']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Kontaktpersonen zu löschen.');
}

$idValue = $_GET['id'] ?? null;
$id = is_string($idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($id === false) {
    http_response_code(400);
    exit('Ungültige Kontaktpersonen-ID.');
}

$origin = $_GET['von'] ?? 'liste';
if (!is_string($origin) || !in_array($origin, ['liste', 'akte'], true)) {
    http_response_code(400);
    exit('Ungültige Herkunft.');
}

$statement = $database->prepare(
    'SELECT e.id, e.vorname, e.nachname, e.schueler_id,
            s.vorname AS schueler_vorname, s.nachname AS schueler_nachname
     FROM erziehungsberechtigte e
     JOIN schueler s ON s.id = e.schueler_id
     WHERE e.id = :id'
);
$statement->execute(['id' => $id]);
$guardian = $statement->fetch();
if ($guardian === false) {
    http_response_code(404);
    exit('Die Kontaktperson wurde nicht gefunden.');
}

$returnUrl = $origin === 'akte'
    ? '/schueler/zeigen.php?id=' . (int) $guardian['schueler_id']
    : '/erziehungsberechtigte/';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        $delete = $database->prepare('DELETE FROM erziehungsberechtigte WHERE id = :id');
        $delete->execute(['id' => $id]);
        if ($delete->rowCount() !== 1) {
            http_response_code(404);
            exit('Die Kontaktperson wurde nicht gefunden.');
        }
        header('Location: ' . $returnUrl, true, 303);
        exit;
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
    <title>Kontaktperson löschen – Schulverwaltung</title>
</head>
<body>
<main>
    <?= renderSchoolHeader($database) ?>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <?= renderAccountMenu($user) ?>
    <p><a href="<?= escape($returnUrl) ?>">Zurück</a></p>
    <h1>Kontaktperson löschen</h1>
    <?php if ($error !== ''): ?>
        <div role="alert"><?= escape($error) ?></div>
    <?php endif; ?>
    <p>Soll die Kontaktperson <strong><?= escape($guardian['vorname'] . ' ' . $guardian['nachname']) ?></strong>
        für <?= escape($guardian['schueler_vorname'] . ' ' . $guardian['schueler_nachname']) ?> wirklich dauerhaft gelöscht werden?</p>
    <p>Der Datensatz der Schülerin und ihre Gespräche bleiben erhalten.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Kontaktperson endgültig löschen</button>
        <a href="<?= escape($returnUrl) ?>">Abbrechen</a>
    </form>
</main>
</body>
</html>
