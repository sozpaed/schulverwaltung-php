<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);

$targetIdValue = $_GET['id'] ?? null;
$targetId = is_string($targetIdValue)
    ? filter_var($targetIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($targetId === false || $targetId === $user['id']) {
    http_response_code(400);
    exit('Ungültige Benutzer-ID.');
}

$targetStatement = $database->prepare('SELECT id, username FROM app_users WHERE id = :id');
$targetStatement->execute(['id' => $targetId]);
$targetUser = $targetStatement->fetch();

if ($targetUser === false) {
    http_response_code(404);
    exit('Der Benutzer wurde nicht gefunden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.');
    }

    $currentTargetStatement = $database->prepare(
        'SELECT id, is_active, is_superuser FROM app_users WHERE id = :id'
    );
    $currentTargetStatement->execute(['id' => $targetId]);
    $currentTarget = $currentTargetStatement->fetch();

    if ($currentTarget === false) {
        http_response_code(404);
        exit('Der Benutzer wurde nicht gefunden.');
    }

    if (
        (bool) $currentTarget['is_active']
        && (bool) $currentTarget['is_superuser']
        && !Admin::hasOtherActiveAdministrator($database, $targetId)
    ) {
        http_response_code(409);
        exit('Der letzte aktive Administrator kann nicht gelöscht werden.');
    }

    $deleteStatement = $database->prepare('DELETE FROM app_users WHERE id = :id');
    $deleteStatement->execute(['id' => $targetId]);

    header('Location: /admin/');
    exit;
}

$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzer löschen – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>Benutzer löschen</h1>
    <p>Soll das Konto <strong><?= escape($targetUser['username']) ?></strong> wirklich dauerhaft gelöscht werden?</p>
    <p>Gesprächsprotokolle und Exportprotokolle bleiben erhalten; die Benutzerzuordnung wird dort entfernt.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Benutzer endgültig löschen</button>
        <a href="/admin/">Abbrechen</a>
    </form>
</main>
</body>
</html>
