<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);

$username = '';
$isActive = true;
$isSuperuser = false;
$canExport = false;
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

            if ($usernameLength === false || $usernameLength < 1 || $usernameLength > 150) {
                $errors[] = 'Der Benutzername muss zwischen 1 und 150 Zeichen lang sein.';
            }

            $passwordLength = preg_match_all('/./us', $password);
            if ($passwordLength === false || $passwordLength < 12 || strlen($password) > 72) {
                $errors[] = 'Das Passwort muss mindestens 12 Zeichen lang sein und darf höchstens 72 Bytes umfassen.';
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

        if ($errors === []) {
            $duplicateStatement = $database->prepare(
                'SELECT COUNT(*) FROM app_users WHERE username = :username'
            );
            $duplicateStatement->execute(['username' => $username]);
            if ((int) $duplicateStatement->fetchColumn() > 0) {
                $errors[] = 'Dieser Benutzername ist bereits vergeben.';
            }
        }

        if ($errors === []) {
            $insertStatement = $database->prepare(
                'INSERT INTO app_users (username, password_hash, is_active, is_superuser, can_export)
                 VALUES (:username, :password_hash, :is_active, :is_superuser, :can_export)'
            );
            try {
                $insertStatement->execute([
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'is_active' => (int) $isActive,
                    'is_superuser' => (int) $isSuperuser,
                    'can_export' => (int) $canExport,
                ]);
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
    <link rel="stylesheet" href="/assets/app.css">
    <title>Benutzer anlegen – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <?= renderAccountMenu($user) ?>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>Benutzer anlegen</h1>
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
            <label for="password">Passwort (mindestens 12 Zeichen)</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
        </p>
        <p>
            <label for="password_confirmation">Passwort wiederholen</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
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
        <button type="submit">Benutzer anlegen</button>
    </form>
</main>
</body>
</html>
