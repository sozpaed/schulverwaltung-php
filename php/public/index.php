<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../src/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        Auth::logout();
        header('Location: /');
        exit;
    } elseif (($_POST['action'] ?? '') === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt($database, $username, $password)) {
            header('Location: /');
            exit;
        }

        $error = 'Anmeldung fehlgeschlagen. Bitte prüfe Benutzername und Passwort.';
    } else {
        http_response_code(400);
        $error = 'Ungültige Formularaktion.';
    }
}

$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $user === null ? 'Anmelden' : 'Schulverwaltung' ?></title>
</head>
<body>
<main>
    <?php if ($user === null): ?>
        <h1>Anmeldung</h1>
        <?php if ($error !== ''): ?>
            <p role="alert"><?= escape($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <p>
                <label for="username">Benutzername</label>
                <input id="username" name="username" type="text" maxlength="150"
                       autocomplete="username" required autofocus>
            </p>
            <p>
                <label for="password">Passwort</label>
                <input id="password" name="password" type="password"
                       autocomplete="current-password" required>
            </p>
            <button type="submit">Anmelden</button>
        </form>
    <?php else: ?>
        <h1>Schulverwaltung</h1>
        <p>Angemeldet als <?= escape($user['username']) ?>.</p>
        <p><a href="/schueler/">Schülerinnen und Schüler verwalten</a></p>
        <p><a href="/gespraeche/">Gespräche verwalten</a></p>
        <p><a href="/erziehungsberechtigte/">Erziehungsberechtigte verwalten</a></p>
        <p><a href="/kataloge/">Auswahllisten pflegen</a></p>
        <?php if ($user['is_superuser']): ?>
            <p><a href="/admin/">Benutzerverwaltung</a></p>
        <?php endif; ?>
        <?php if ($user['can_export']): ?>
            <p><a href="/exporte/">Daten exportieren</a></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <button type="submit">Abmelden</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
