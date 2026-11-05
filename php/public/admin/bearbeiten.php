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

if ($targetId === false) {
    http_response_code(400);
    exit('Ungültige Benutzer-ID.');
}

$userStatement = $database->prepare(
    'SELECT id, username, is_active, is_superuser, can_export FROM app_users WHERE id = :id'
);
$userStatement->execute(['id' => $targetId]);
$targetUser = $userStatement->fetch();

if ($targetUser === false) {
    http_response_code(404);
    exit('Der Benutzer wurde nicht gefunden.');
}

$username = $targetUser['username'];
$isActive = (bool) $targetUser['is_active'];
$isSuperuser = (bool) $targetUser['is_superuser'];
$canExport = (bool) $targetUser['can_export'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        $usernameValue = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;
        $passwordConfirmation = $_POST['password_confirmation'] ?? null;

        if (
            !is_string($usernameValue)
            || !is_string($password)
            || !is_string($passwordConfirmation)
        ) {
            $errors[] = 'Ungültige Formulardaten.';
        } else {
            $username = trim($usernameValue);
            $usernameLength = preg_match_all('/./us', $username);
            $passwordLength = $password === '' ? 0 : preg_match_all('/./us', $password);

            if ($usernameLength === false || $usernameLength < 1 || $usernameLength > 150) {
                $errors[] = 'Der Benutzername muss zwischen 1 und 150 Zeichen lang sein.';
            }
            if ($password !== '' && ($passwordLength === false || $passwordLength < 12 || strlen($password) > 72)) {
                $errors[] = 'Ein neues Passwort muss mindestens 12 Zeichen lang sein und darf höchstens 72 Bytes umfassen.';
            }
            if ($password !== $passwordConfirmation) {
                $errors[] = 'Die Passwörter stimmen nicht überein.';
            }
        }

        $isActive = !array_key_exists('is_active', $_POST)
            ? false
            : ($_POST['is_active'] === '1' ? true : null);
        $isSuperuser = !array_key_exists('is_superuser', $_POST)
            ? false
            : ($_POST['is_superuser'] === '1' ? true : null);
        $canExport = !array_key_exists('can_export', $_POST)
            ? false
            : ($_POST['can_export'] === '1' ? true : null);

        if ($isActive === null || $isSuperuser === null || $canExport === null) {
            $errors[] = 'Ungültige Berechtigungsauswahl.';
        }

        if (
            $targetId === $user['id']
            && (!$isActive || !$isSuperuser)
        ) {
            $errors[] = 'Du kannst dein eigenes Konto weder deaktivieren noch die Administratorrechte entziehen.';
        }

        if (
            $errors === []
            && (bool) $targetUser['is_active']
            && (bool) $targetUser['is_superuser']
            && (!$isActive || !$isSuperuser)
            && !Admin::hasOtherActiveAdministrator($database, $targetId)
        ) {
            $errors[] = 'Der letzte aktive Administrator kann nicht deaktiviert oder herabgestuft werden.';
        }

        if ($errors === []) {
            $duplicateStatement = $database->prepare(
                'SELECT COUNT(*) FROM app_users WHERE username = :username AND id <> :id'
            );
            $duplicateStatement->execute([
                'username' => $username,
                'id' => $targetId,
            ]);
            if ((int) $duplicateStatement->fetchColumn() > 0) {
                $errors[] = 'Dieser Benutzername ist bereits vergeben.';
            }
        }

        if ($errors === []) {
            $parameters = [
                'username' => $username,
                'is_active' => (int) $isActive,
                'is_superuser' => (int) $isSuperuser,
                'can_export' => (int) $canExport,
                'id' => $targetId,
            ];
            $passwordSql = '';
            if ($password !== '') {
                $passwordSql = ', password_hash = :password_hash';
                $parameters['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $updateStatement = $database->prepare(
                'UPDATE app_users
                 SET username = :username, is_active = :is_active,
                     is_superuser = :is_superuser, can_export = :can_export' . $passwordSql . '
                 WHERE id = :id'
            );
            try {
                $updateStatement->execute($parameters);
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }

                $errors[] = 'Dieser Benutzername ist bereits vergeben.';
            }
        }

        if ($errors === []) {
            header('Location: /admin/');
            exit;
        }
    }
}

$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzer bearbeiten – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>Benutzer bearbeiten</h1>
    <?php if ($errors !== []): ?>
        <div role="alert">
            <ul>
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <p>
            <label for="username">Benutzername</label>
            <input id="username" name="username" maxlength="150" required value="<?= escape($username) ?>">
        </p>
        <p>
            <label for="password">Neues Passwort (leer lassen, um es nicht zu ändern)</label>
            <input id="password" name="password" type="password" autocomplete="new-password">
        </p>
        <p>
            <label for="password_confirmation">Neues Passwort wiederholen</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
        </p>
        <fieldset>
            <legend>Berechtigungen</legend>
            <p>
                <input id="is_active" name="is_active" type="checkbox" value="1"<?= $isActive ? ' checked' : '' ?>>
                <label for="is_active">Benutzerkonto aktiv</label>
            </p>
            <p>
                <input id="is_superuser" name="is_superuser" type="checkbox" value="1"<?= $isSuperuser ? ' checked' : '' ?>>
                <label for="is_superuser">Administratorrechte</label>
            </p>
            <p>
                <input id="can_export" name="can_export" type="checkbox" value="1"<?= $canExport ? ' checked' : '' ?>>
                <label for="can_export">Datenexport erlauben</label>
            </p>
        </fieldset>
        <button type="submit">Änderungen speichern</button>
    </form>
</main>
</body>
</html>
