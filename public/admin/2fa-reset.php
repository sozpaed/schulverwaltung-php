<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;
use Schulverwaltung\TwoFactor;
use Schulverwaltung\TwoFactorReset;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';
require_once __DIR__ . '/../../src/TwoFactorReset.php';
Admin::requireAdministrator($user);

$value = $_GET['id'] ?? null;
$targetId = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($targetId === false || $targetId === $user['id']) {
    http_response_code(400);
    exit('Ungültiges Benutzerkonto.');
}
$statement = $database->prepare('SELECT username, totp_enabled FROM app_users WHERE id = :id');
$statement->execute(['id' => $targetId]);
$target = $statement->fetch();
if ($target === false) {
    http_response_code(404);
    exit('Der Benutzer wurde nicht gefunden.');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $error = 'Das Formular ist abgelaufen. Bitte lade die Seite neu.';
    } else {
        $password = $_POST['password'] ?? null;
        $code = $_POST['totp_code'] ?? null;
        $statement = $database->prepare(
            'SELECT password_hash, totp_enabled, totp_secret FROM app_users
             WHERE id = :id AND is_active = 1 AND is_superuser = 1 AND sitzungs_version = :version'
        );
        $statement->execute(['id' => $user['id'], 'version' => $user['sitzungs_version']]);
        $admin = $statement->fetch();
        if ($admin === false || !is_string($password) || !password_verify($password, $admin['password_hash'])) {
            $error = 'Die Bestätigung ist fehlgeschlagen.';
        } elseif ((bool) $admin['totp_enabled']
            && (($counter = TwoFactor::matchingCounter(TwoFactor::decryptSecret($admin['totp_secret'], $user['id']), $code)) === null
                || !Auth::storeTotpCounter($database, $user['id'], $counter))) {
            $error = 'Die Bestätigung ist fehlgeschlagen.';
        } elseif (!(bool) $target['totp_enabled']) {
            $error = 'Für dieses Konto ist 2FA nicht aktiviert.';
        } else {
            TwoFactorReset::reset($database, $targetId, $user['id'], $user['username'], $user['sitzungs_version']);
            header('Location: /admin/', true, 303);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>2FA zurücksetzen – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>2FA zurücksetzen</h1>
    <?= renderAccountMenu($user) ?>
    <p>2FA für <?= escape($target['username']) ?> zurücksetzen? Das Konto kann sich danach nur mit Passwort anmelden. Alle Wiederherstellungscodes und Sitzungen werden ungültig.</p>
    <p>Prüfe zuvor die Identität der Person über einen unabhängigen Weg.</p>
    <?php if ($error !== ''): ?><p role="alert"><?= escape($error) ?></p><?php endif; ?>
    <?php if ((bool) $target['totp_enabled']): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= escape(Auth::csrfToken()) ?>">
            <p><label for="password">Dein Administrator-Passwort</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required></p>
            <?php if ($user['totp_enabled']): ?>
                <p><label for="totp_code">Dein Authenticator-Code</label>
                    <input id="totp_code" name="totp_code" pattern="[0-9]{6}" maxlength="6" required></p>
            <?php endif; ?>
            <button type="submit">2FA endgültig zurücksetzen</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
