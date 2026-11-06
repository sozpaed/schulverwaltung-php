<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\Database;

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/src/security_headers.php';
$configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
$databaseEnvironmentIsConfigured = true;
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $setting) {
    if (getenv($setting) === false) {
        $databaseEnvironmentIsConfigured = false;
        break;
    }
}

if (is_file($configPath) || $databaseEnvironmentIsConfigured) {
    http_response_code(404);
    exit;
}

require_once $projectRoot . '/src/Auth.php';
require_once $projectRoot . '/src/Database.php';
require_once $projectRoot . '/src/helpers.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_cache_limiter('');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$checks = [
    'PHP 8.1 oder neuer' => PHP_VERSION_ID >= 80100,
    'PDO' => extension_loaded('pdo'),
    'PDO-MySQL' => extension_loaded('pdo_mysql')
        && in_array('mysql', PDO::getAvailableDrivers(), true),
    'OpenSSL' => extension_loaded('openssl')
        && function_exists('openssl_encrypt')
        && function_exists('openssl_decrypt'),
    'PHP-Sessions' => extension_loaded('session'),
    'Sichere Zufallswerte und Passwort-Hashes' => function_exists('random_bytes')
        && function_exists('password_hash')
        && function_exists('password_verify'),
    'Projektverzeichnis für config.php beschreibbar' => is_writable($projectRoot),
    'Datenbankschema und Basisdaten lesbar' => is_readable($projectRoot . '/database/schema.sql')
        && is_readable($projectRoot . '/database/seed.sql'),
];
$compatibilityPassed = !in_array(false, $checks, true);
$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } elseif (!$compatibilityPassed) {
        http_response_code(500);
        $errors[] = 'Die Hosting-Umgebung erfüllt nicht alle Voraussetzungen. Behebe die fehlgeschlagenen Prüfungen und lade die Seite erneut.';
    } else {
        $usernameValue = $_POST['admin_username'] ?? null;
        $password = $_POST['admin_password'] ?? null;
        $passwordConfirmation = $_POST['admin_password_confirmation'] ?? null;
        $host = $_POST['db_host'] ?? null;
        $portValue = $_POST['db_port'] ?? null;
        $databaseName = $_POST['db_name'] ?? null;
        $databaseUser = $_POST['db_user'] ?? null;
        $databasePassword = $_POST['db_password'] ?? null;

        if (
            !is_string($usernameValue)
            || !is_string($password)
            || !is_string($passwordConfirmation)
            || !is_string($host)
            || !is_string($portValue)
            || !is_string($databaseName)
            || !is_string($databaseUser)
            || !is_string($databasePassword)
        ) {
            $errors[] = 'Ungültige Formulardaten.';
        } else {
            $username = trim($usernameValue);
            $usernameLength = preg_match_all('/./us', $username);
            $passwordLength = preg_match_all('/./us', $password);

            if ($usernameLength === false || $usernameLength < 1 || $usernameLength > 150) {
                $errors[] = 'Der Administrator-Benutzername muss zwischen 1 und 150 Zeichen lang sein.';
            }
            if ($passwordLength === false || $passwordLength < 12 || strlen($password) > 72) {
                $errors[] = 'Das Administrator-Passwort muss mindestens 12 Zeichen lang sein und darf höchstens 72 Bytes umfassen.';
            }
            if ($password !== $passwordConfirmation) {
                $errors[] = 'Die Administrator-Passwörter stimmen nicht überein.';
            }
            if ($host === '' || !preg_match('/\A[A-Za-z0-9.:-]+\z/', $host)) {
                $errors[] = 'Der Datenbank-Host enthält einen ungültigen Wert.';
            }
            if (!Database::isValidDatabaseName($databaseName)) {
                $errors[] = 'Der Datenbankname enthält einen ungültigen Wert.';
            }
            if (trim($databaseUser) === '') {
                $errors[] = 'Der Datenbank-Benutzername darf nicht leer sein.';
            }
            $port = filter_var(
                $portValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 65535]]
            );
            if ($port === false) {
                $errors[] = 'Der Datenbank-Port muss eine Zahl zwischen 1 und 65535 sein.';
            }
        }

        if ($errors === []) {
            $configuration = [
                'DB_HOST' => $host,
                'DB_PORT' => $port,
                'DB_NAME' => $databaseName,
                'DB_USER' => $databaseUser,
                'DB_PASSWORD' => $databasePassword,
            ];
            $temporaryConfigPath = null;
            $schemaStarted = false;

            try {
                if (is_file($configPath)) {
                    throw new RuntimeException('Die Installation wurde bereits gestartet oder abgeschlossen.');
                }

                $database = Database::connectWithConfiguration($configuration);
                $serverVersion = (string) $database->query('SELECT VERSION()')->fetchColumn();
                if (Database::supportedServerType($serverVersion) === null) {
                    throw new RuntimeException(
                        'Es wird MariaDB ab Version 10.5 oder MySQL 8.4 benötigt. Erkannte Serverversion: ' . $serverVersion
                    );
                }

                $tableCheck = $database->prepare(
                    'SELECT TABLE_NAME
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()'
                );
                $tableCheck->execute();
                $existingTables = $tableCheck->fetchAll(PDO::FETCH_COLUMN);
                if ($existingTables !== []) {
                    throw new RuntimeException(
                        'Die Datenbank ist nicht leer (' .
                        implode(', ', $existingTables) .
                        '). Der Installer verändert oder löscht keine vorhandenen Tabellen. Verwende eine eigens angelegte, leere Datenbank.'
                    );
                }

                $temporaryConfigPath = $projectRoot
                    . DIRECTORY_SEPARATOR
                    . '.config-setup-'
                    . bin2hex(random_bytes(8))
                    . '.php';
                $previousUmask = umask(0077);
                try {
                    $configHandle = @fopen($temporaryConfigPath, 'x');
                } finally {
                    umask($previousUmask);
                }
                if ($configHandle === false) {
                    throw new RuntimeException('Die temporäre Konfigurationsdatei konnte nicht angelegt werden.');
                }
                $configContent = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
                    . var_export($configuration, true)
                    . ";\n";
                $bytesWritten = fwrite($configHandle, $configContent);
                $flushed = fflush($configHandle);
                $closed = fclose($configHandle);
                if ($bytesWritten !== strlen($configContent) || !$flushed || !$closed) {
                    throw new RuntimeException('Die Konfigurationsdatei konnte nicht vollständig geschrieben werden.');
                }
                if (!chmod($temporaryConfigPath, 0600)) {
                    throw new RuntimeException('Die Zugriffsrechte der Konfigurationsdatei konnten nicht eingeschränkt werden.');
                }

                $schema = file_get_contents($projectRoot . '/database/schema.sql');
                if ($schema === false) {
                    throw new RuntimeException('Das Datenbankschema konnte nicht gelesen werden.');
                }
                $statements = preg_split('/;\s*(?:\R|$)/', trim($schema));
                if ($statements === false || $statements === []) {
                    throw new RuntimeException('Das Datenbankschema ist leer oder ungültig.');
                }

                $schemaStarted = true;
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if ($statement !== '') {
                        $database->exec($statement);
                    }
                }

                $seed = file_get_contents($projectRoot . '/database/seed.sql');
                if ($seed === false) {
                    throw new RuntimeException('Die Basis-Auswahlliste konnte nicht gelesen werden.');
                }
                $database->exec($seed);

                $createAdmin = $database->prepare(
                    'INSERT INTO app_users (username, password_hash, is_active, is_superuser, can_export)
                     VALUES (:username, :password_hash, 1, 1, 1)'
                );
                $createAdmin->execute([
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);

                if (is_file($configPath) || !rename($temporaryConfigPath, $configPath)) {
                    throw new RuntimeException('Die Installation ist angelegt, aber config.php konnte nicht aktiviert werden.');
                }
                $temporaryConfigPath = null;
                session_regenerate_id(true);
                header('Location: /?installed=1', true, 303);
                exit;
            } catch (PDOException | RuntimeException $exception) {
                http_response_code(500);
                $errors[] = 'Installation fehlgeschlagen: ' . $exception->getMessage();
                if ($schemaStarted) {
                    $errors[] = 'MariaDB und MySQL führen CREATE TABLE sofort aus. Falls Tabellen angelegt wurden, prüfe die Datenbank manuell, bevor du den Installer erneut startest. Es wurden keine vorhandenen Tabellen gelöscht.';
                }
                if ($temporaryConfigPath !== null && is_file($temporaryConfigPath)) {
                    if (!unlink($temporaryConfigPath)) {
                        $errors[] = 'Die temporäre Konfiguration konnte nicht entfernt werden: ' . basename($temporaryConfigPath);
                    }
                }
            }
        }
    }
}

$csrfToken = Auth::csrfToken();
$postedString = static function (string $name, string $default = ''): string {
    $value = $_POST[$name] ?? $default;

    return is_string($value) ? $value : $default;
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Installation – Schulverwaltung</title>
</head>
<body>
<main>
    <h1>Schulverwaltung installieren</h1>
    <p>Der Assistent prüft die Servervoraussetzungen, verbindet sich mit einer eigens angelegten, leeren MariaDB- oder MySQL-Datenbank, richtet das Schema ein und erstellt das erste Administratorkonto.</p>
    <p>Vorhandene Tabellen werden nicht überschrieben oder gelöscht. Die Datenbank muss zuvor bei deinem Hoster angelegt worden und leer sein.</p>

    <section aria-labelledby="requirements-heading">
        <h2 id="requirements-heading">Kompatibilitätsprüfung</h2>
        <ul class="installation-checks">
            <?php foreach ($checks as $label => $passed): ?>
                <li class="<?= $passed ? 'check-passed' : 'check-failed' ?>">
                    <span aria-hidden="true"><?= $passed ? '✓' : '✗' ?></span>
                    <?= escape($label) ?>: <?= $passed ? 'OK' : 'nicht erfüllt' ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p>Erforderlich ist außerdem MariaDB 10.5 oder neuer oder MySQL 8.4; die Serverversion wird beim Verbindungstest geprüft.</p>
    </section>

    <?php if ($errors !== []): ?>
        <div role="alert">
            <ul>
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($compatibilityPassed): ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <fieldset>
                <legend>Datenbankverbindung</legend>
                <p>
                    <label for="db_host">Datenbank-Host</label>
                    <input id="db_host" name="db_host" value="<?= escape($postedString('db_host', 'localhost')) ?>"
                           autocomplete="off" required>
                </p>
                <p>
                    <label for="db_port">Port</label>
                    <input id="db_port" name="db_port" type="number" min="1" max="65535"
                           value="<?= escape($postedString('db_port', '3306')) ?>" required>
                </p>
                <p>
                    <label for="db_name">Datenbankname</label>
                    <input id="db_name" name="db_name" value="<?= escape($postedString('db_name')) ?>"
                           autocomplete="off" required>
                </p>
                <p class="field-hint"><strong>Wichtig:</strong> Verwende nicht <code>root</code> oder ein Administratorkonto des Datenbankservers, sondern einen eigenen Benutzer, der nur Rechte auf diese Datenbank hat.</p>
                <p>
                    <label for="db_user">Datenbank-Benutzername</label>
                    <input id="db_user" name="db_user" value="<?= escape($postedString('db_user')) ?>"
                           autocomplete="username" required>
                </p>
                <p>
                    <label for="db_password">Datenbank-Passwort</label>
                    <input id="db_password" name="db_password" type="password"
                           autocomplete="new-password" required>
                </p>
            </fieldset>
            <fieldset>
                <legend>Erstes Administratorkonto</legend>
                <p>
                    <label for="admin_username">Benutzername</label>
                    <input id="admin_username" name="admin_username" maxlength="150"
                           value="<?= escape($username) ?>" autocomplete="username" required>
                </p>
                <p>
                    <label for="admin_password">Passwort (mindestens 12 Zeichen)</label>
                    <input id="admin_password" name="admin_password" type="password"
                           minlength="12" maxlength="72" autocomplete="new-password" required>
                </p>
                <p>
                    <label for="admin_password_confirmation">Passwort wiederholen</label>
                    <input id="admin_password_confirmation" name="admin_password_confirmation" type="password"
                           minlength="12" maxlength="72" autocomplete="new-password" required>
                </p>
            </fieldset>
            <button type="submit" <?= $compatibilityPassed ? '' : 'disabled' ?>>
                Prüfen und installieren
            </button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
