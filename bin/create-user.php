<?php

declare(strict_types=1);

use Schulverwaltung\Database;

require_once __DIR__ . '/../src/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript darf nur über die Kommandozeile ausgeführt werden.\n");
    exit(1);
}

$username = $argv[1] ?? '';

if ($username === '' || strlen($username) > 150) {
    fwrite(STDERR, "Aufruf: php bin/create-user.php BENUTZERNAME\n");
    exit(1);
}

$password = getenv('APP_USER_PASSWORD');

if ($password === false || strlen($password) < 12) {
    fwrite(STDERR, "APP_USER_PASSWORD muss gesetzt sein und mindestens 12 Zeichen enthalten.\n");
    exit(1);
}

$database = Database::connect();
$statement = $database->prepare(
    'INSERT INTO app_users (username, password_hash, is_active)
     VALUES (:username, :password_hash, 1)'
);
$statement->execute([
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

fwrite(STDOUT, sprintf("Benutzer %s wurde angelegt.\n", $username));
