<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\TwoFactor;

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$errors = [];
$success = '';
$pendingSecret = $_SESSION['totp_pending_secret'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        $action = $_POST['action'] ?? null;

        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? null;
            $newPassword = $_POST['new_password'] ?? null;
            $passwordConfirmation = $_POST['password_confirmation'] ?? null;

            if (
                !is_string($currentPassword)
                || !is_string($newPassword)
                || !is_string($passwordConfirmation)
            ) {
                $errors[] = 'Ungültige Formulardaten.';
            } else {
                $passwordLength = preg_match_all('/./us', $newPassword);
                if ($passwordLength === false || $passwordLength < 12 || strlen($newPassword) > 72) {
                    $errors[] = 'Das neue Passwort muss mindestens 12 Zeichen lang sein und darf höchstens 72 Bytes umfassen.';
                }
                if ($newPassword !== $passwordConfirmation) {
                    $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                }

                if ($errors === []) {
                    $passwordStatement = $database->prepare(
                        'SELECT password_hash FROM app_users WHERE id = :id'
                    );
                    $passwordStatement->execute(['id' => $user['id']]);
                    $passwordHash = $passwordStatement->fetchColumn();

                    if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                        $errors[] = 'Das aktuelle Passwort ist nicht korrekt.';
                    } else {
                        $updateStatement = $database->prepare(
                            'UPDATE app_users SET password_hash = :password_hash WHERE id = :id'
                        );
                        $updateStatement->execute([
                            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                            'id' => $user['id'],
                        ]);
                        session_regenerate_id(true);
                        $success = 'Das Passwort wurde geändert.';
                    }
                }
            }
        } elseif ($action === 'start_totp') {
            if ($user['totp_enabled']) {
                $errors[] = 'Die Zwei-Faktor-Anmeldung ist bereits aktiviert.';
            } else {
                $pendingSecret = TwoFactor::generateSecret();
                $_SESSION['totp_pending_secret'] = $pendingSecret;
            }
        } elseif ($action === 'enable_totp') {
            $code = $_POST['totp_code'] ?? null;
            $pendingSecret = $_SESSION['totp_pending_secret'] ?? null;

            if ($user['totp_enabled']) {
                $errors[] = 'Die Zwei-Faktor-Anmeldung ist bereits aktiviert.';
            } elseif (!is_string($pendingSecret) || !is_string($code)) {
                $errors[] = 'Bitte starte die Einrichtung erneut.';
            } else {
                $counter = TwoFactor::matchingCounter($pendingSecret, $code);
                if ($counter === null) {
                    $errors[] = 'Der Code ist ungültig oder abgelaufen. Bitte prüfe die Uhrzeit deines Geräts.';
                } else {
                    $encryptedSecret = TwoFactor::encryptSecret($pendingSecret, $user['id']);
                    $updateStatement = $database->prepare(
                        'UPDATE app_users
                         SET totp_secret = :secret, totp_enabled = 1, totp_last_counter = :counter
                         WHERE id = :id AND totp_enabled = 0'
                    );
                    $updateStatement->execute([
                        'secret' => $encryptedSecret,
                        'counter' => $counter,
                        'id' => $user['id'],
                    ]);

                    if ($updateStatement->rowCount() !== 1) {
                        $errors[] = 'Die Zwei-Faktor-Einrichtung konnte nicht abgeschlossen werden. Bitte lade die Seite neu.';
                    } else {
                        unset($_SESSION['totp_pending_secret']);
                        $pendingSecret = null;
                        $user['totp_enabled'] = true;
                        $_SESSION['user']['totp_enabled'] = true;
                        $success = 'Die Zwei-Faktor-Anmeldung ist jetzt aktiviert.';
                    }
                }
            }
        } elseif ($action === 'disable_totp') {
            $currentPassword = $_POST['current_password'] ?? null;
            $code = $_POST['totp_code'] ?? null;

            if (!is_string($currentPassword) || !is_string($code)) {
                $errors[] = 'Ungültige Formulardaten.';
            } elseif (!$user['totp_enabled']) {
                $errors[] = 'Die Zwei-Faktor-Anmeldung ist nicht aktiviert.';
            } else {
                $credentialsStatement = $database->prepare(
                    'SELECT password_hash, totp_secret, totp_last_counter
                     FROM app_users WHERE id = :id AND totp_enabled = 1'
                );
                $credentialsStatement->execute(['id' => $user['id']]);
                $credentials = $credentialsStatement->fetch();

                if (
                    $credentials === false
                    || !password_verify($currentPassword, $credentials['password_hash'])
                ) {
                    $errors[] = 'Passwort oder Authenticator-Code ist nicht korrekt.';
                } else {
                    $secret = TwoFactor::decryptSecret($credentials['totp_secret'], $user['id']);
                    $counter = TwoFactor::matchingCounter($secret, $code);
                    $lastCounter = $credentials['totp_last_counter'] === null
                        ? null
                        : (int) $credentials['totp_last_counter'];

                    if ($counter === null || ($lastCounter !== null && $counter <= $lastCounter)) {
                        $errors[] = 'Passwort oder Authenticator-Code ist nicht korrekt.';
                    } else {
                        $updateStatement = $database->prepare(
                            'UPDATE app_users
                             SET totp_secret = NULL, totp_enabled = 0, totp_last_counter = NULL
                             WHERE id = :id AND totp_enabled = 1
                               AND (totp_last_counter IS NULL OR totp_last_counter < :counter)'
                        );
                        $updateStatement->execute([
                            'id' => $user['id'],
                            'counter' => $counter,
                        ]);

                        if ($updateStatement->rowCount() !== 1) {
                            $errors[] = 'Die Zwei-Faktor-Anmeldung konnte nicht deaktiviert werden. Bitte versuche es erneut.';
                        } else {
                            unset($_SESSION['totp_pending_secret']);
                            $user['totp_enabled'] = false;
                            $_SESSION['user']['totp_enabled'] = false;
                            $success = 'Die Zwei-Faktor-Anmeldung wurde deaktiviert.';
                        }
                    }
                }
            }
        } else {
            http_response_code(400);
            $errors[] = 'Ungültige Formularaktion.';
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
    <title>Kontoeinstellungen – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <?= renderAccountMenu($user) ?>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Kontoeinstellungen</h1>
    <?php if ($errors !== []): ?>
        <div role="alert">
            <ul>
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <p class="success-message" role="status"><?= escape($success) ?></p>
    <?php endif; ?>

    <section aria-labelledby="password-heading">
        <h2 id="password-heading">Passwort ändern</h2>
        <form method="post">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <p>
                <label for="current_password">Aktuelles Passwort</label>
                <input id="current_password" name="current_password" type="password"
                       autocomplete="current-password" required>
            </p>
            <p>
                <label for="new_password">Neues Passwort</label>
                <input id="new_password" name="new_password" type="password"
                       autocomplete="new-password" minlength="12" maxlength="72" required>
            </p>
            <p>
                <label for="password_confirmation">Neues Passwort wiederholen</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" minlength="12" maxlength="72" required>
            </p>
            <button type="submit">Passwort ändern</button>
        </form>
    </section>

    <section aria-labelledby="totp-heading">
        <h2 id="totp-heading">Zwei-Faktor-Anmeldung (2FA)</h2>
        <?php if ($user['totp_enabled']): ?>
            <p>Die Zwei-Faktor-Anmeldung ist aktiviert.</p>
            <form method="post">
                <input type="hidden" name="action" value="disable_totp">
                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                <p>
                    <label for="disable_current_password">Aktuelles Passwort</label>
                    <input id="disable_current_password" name="current_password" type="password"
                           autocomplete="current-password" required>
                </p>
                <p>
                    <label for="disable_totp_code">Aktueller Authenticator-Code</label>
                    <input id="disable_totp_code" name="totp_code" type="text" inputmode="numeric"
                           pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                </p>
                <button type="submit">2FA deaktivieren</button>
            </form>
        <?php elseif (is_string($pendingSecret)): ?>
            <p>Füge diesen Schlüssel manuell in deiner Authenticator-App als TOTP-Konto hinzu:</p>
            <p class="totp-secret"><code><?= escape($pendingSecret) ?></code></p>
            <p>Gib anschließend den sechsstelligen Code aus der App ein, um die Einrichtung zu bestätigen.</p>
            <form method="post">
                <input type="hidden" name="action" value="enable_totp">
                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                <p>
                    <label for="enable_totp_code">Authenticator-Code</label>
                    <input id="enable_totp_code" name="totp_code" type="text" inputmode="numeric"
                           pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                </p>
                <button type="submit">2FA aktivieren</button>
            </form>
        <?php else: ?>
            <p>Die Zwei-Faktor-Anmeldung ist optional. Du benötigst eine Authenticator-App, die zeitbasierte Einmalcodes (TOTP) unterstützt.</p>
            <form method="post">
                <input type="hidden" name="action" value="start_totp">
                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                <button type="submit">2FA einrichten</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
