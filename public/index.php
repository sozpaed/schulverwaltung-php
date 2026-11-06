<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../src/security_headers.php';

$databaseEnvironmentIsConfigured = true;
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $setting) {
    if (getenv($setting) === false) {
        $databaseEnvironmentIsConfigured = false;
        break;
    }
}

if (!is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php') && !$databaseEnvironmentIsConfigured) {
    header('Location: /setup.php');
    exit;
}

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
        $username = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;
        $twoFactorCode = $_POST['totp_code'] ?? null;

        if (!is_string($username) || !is_string($password) || preg_match('//u', $username) !== 1) {
            http_response_code(400);
            $error = 'Ungültige Formulardaten.';
        } else {
            if (Auth::attempt($database, trim($username), $password, $twoFactorCode)) {
                header('Location: /');
                exit;
            }

            $error = 'Anmeldung fehlgeschlagen. Bitte prüfe deine Anmeldedaten. Nach mehreren Fehlversuchen warte bitte kurz und versuche es erneut.';
        }
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
    <link rel="stylesheet" href="/assets/app.css">
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
            <p>
                <label for="totp_code">Authenticator-Code (falls 2FA eingerichtet ist)</label>
                <input id="totp_code" name="totp_code" type="text" inputmode="numeric"
                       pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
            </p>
            <button type="submit">Anmelden</button>
        </form>
    <?php else: ?>
        <h1>Schulverwaltung</h1>
        <?= renderAccountMenu($user) ?>
        <nav class="dashboard-links" aria-label="Hauptnavigation">
            <ul class="dashboard-links-primary">
                <li><a href="/schueler/">Schülerinnen verwalten</a></li>
                <li><a href="/gespraeche/">Gespräche verwalten</a></li>
                <li><a href="/erziehungsberechtigte/">Erziehungsberechtigte verwalten</a></li>
            </ul>
            <ul class="dashboard-links-secondary">
                <li><a href="/kataloge/">Auswahllisten pflegen</a></li>
                <?php if ($user['is_superuser']): ?>
                    <li><a href="/admin/">Benutzerverwaltung</a></li>
                <?php endif; ?>
                <?php if ($user['can_export']): ?>
                    <li><a href="/exporte/">Daten exportieren</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
