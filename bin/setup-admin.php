<?php

declare(strict_types=1);

use Schulverwaltung\Database;

require_once __DIR__ . '/../src/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript darf nur über die Kommandozeile ausgeführt werden.\n");
    exit(1);
}

$action = $argv[1] ?? '';
$username = $argv[2] ?? '';

if (!in_array($action, ['create', 'promote'], true) || $username === '' || strlen($username) > 150) {
    fwrite(STDERR, "Aufruf: php bin/setup-admin.php create|promote BENUTZERNAME\n");
    exit(1);
}

$database = Database::connect();
$findUser = $database->prepare('SELECT id, is_active FROM app_users WHERE username = :username');
$findUser->execute(['username' => $username]);
$existingUser = $findUser->fetch();

if ($action === 'promote') {
    if ($existingUser === false) {
        fwrite(STDERR, sprintf("Der Benutzer %s wurde nicht gefunden.\n", $username));
        exit(1);
    }

    if (!(bool) $existingUser['is_active']) {
        fwrite(STDERR, sprintf(
            "Der Benutzer %s ist deaktiviert. Aktiviere das Konto zuerst in der Datenbank oder über einen anderen Administrator.\n",
            $username
        ));
        exit(1);
    }

    $statement = $database->prepare(
        'UPDATE app_users
         SET sitzungs_version = sitzungs_version + (
                 is_superuser <> 1 OR can_export <> 1
                 OR can_view_maintenance <> 1 OR can_view_statistics <> 1
             ),
             is_superuser = 1, can_export = 1,
             can_view_maintenance = 1, can_view_statistics = 1
         WHERE id = :id'
    );
    $statement->execute(['id' => $existingUser['id']]);
    fwrite(STDOUT, sprintf(
        "Administrator- und Exportrechte wurden für %s gesetzt. Das Passwort blieb unverändert.\n",
        $username
    ));
    exit(0);
}

if ($existingUser !== false) {
    fwrite(STDERR, sprintf(
        "Der Benutzer %s existiert bereits. Verwende für ein bestehendes Konto den Modus promote.\n",
        $username
    ));
    exit(1);
}

$password = getenv('APP_ADMIN_PASSWORD');
$passwordLength = $password === false ? false : preg_match_all('/./us', $password);

if ($password === false || $passwordLength === false || $passwordLength < 12 || strlen($password) > 72) {
    fwrite(STDERR, "APP_ADMIN_PASSWORD muss mindestens 12 Zeichen und darf höchstens 72 Bytes umfassen.\n");
    exit(1);
}

$statement = $database->prepare(
    'INSERT INTO app_users (
        username, password_hash, is_active, is_superuser, can_export,
        can_view_maintenance, can_view_statistics
     ) VALUES (:username, :password_hash, 1, 1, 1, 1, 1)'
);

try {
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
} catch (PDOException $exception) {
    if ($exception->getCode() !== '23000') {
        throw $exception;
    }

    fwrite(STDERR, sprintf("Der Benutzername %s ist bereits vergeben.\n", $username));
    exit(1);
}

fwrite(STDOUT, sprintf("Administratorkonto %s wurde mit Exportrecht angelegt.\n", $username));
