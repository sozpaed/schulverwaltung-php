<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\Catalogs;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Catalogs.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_delete_catalogs']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Auswahllisteneinträge zu löschen.');
}

$typeValue = $_GET['typ'] ?? null;
$type = is_string($typeValue) ? $typeValue : '';
$definition = Catalogs::definition($type);
if ($definition === null) {
    http_response_code(404);
    exit('Der Auswahllistentyp wurde nicht gefunden.');
}

$idValue = $_GET['id'] ?? null;
$id = is_string($idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($id === false) {
    http_response_code(400);
    exit('Ungültige Eintrags-ID.');
}

$columns = $definition['has_location'] ? 'id, name, ort' : 'id, name';
$statement = $database->prepare(
    sprintf('SELECT %s FROM %s WHERE id = :id', $columns, $definition['table'])
);
$statement->execute(['id' => $id]);
$entry = $statement->fetch();
if ($entry === false) {
    http_response_code(404);
    exit('Der Auswahllisteneintrag wurde nicht gefunden.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        $delete = $database->prepare(sprintf('DELETE FROM %s WHERE id = :id', $definition['table']));
        try {
            $delete->execute(['id' => $id]);
            if ($delete->rowCount() !== 1) {
                http_response_code(404);
                exit('Der Auswahllisteneintrag wurde nicht gefunden.');
            }
            header('Location: /kataloge/liste.php?typ=' . rawurlencode($type), true, 303);
            exit;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1451) {
                throw $exception;
            }
            http_response_code(409);
            $error = 'Dieser Eintrag wird noch verwendet und kann nicht gelöscht werden. Entferne oder ändere zuerst alle zugehörigen Zuordnungen.';
        }
    }
}

$csrfToken = Auth::csrfToken();
$returnUrl = '/kataloge/liste.php?typ=' . rawurlencode($type);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Eintrag löschen – Schulverwaltung</title>
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
    <p><a href="<?= escape($returnUrl) ?>">Zurück zur Auswahlliste</a></p>
    <h1><?= escape($definition['singular']) ?> löschen</h1>
    <?php if ($error !== ''): ?>
        <div role="alert"><?= escape($error) ?></div>
    <?php endif; ?>
    <p>Soll der Eintrag <strong><?= escape(Catalogs::label($entry, $definition)) ?></strong> wirklich dauerhaft gelöscht werden?</p>
    <p>Verwendete Einträge können nicht gelöscht werden.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Eintrag endgültig löschen</button>
        <a href="<?= escape($returnUrl) ?>">Abbrechen</a>
    </form>
</main>
</body>
</html>
