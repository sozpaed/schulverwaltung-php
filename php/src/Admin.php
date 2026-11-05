<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;

final class Admin
{
    public static function requireAdministrator(?array $user): void
    {
        if ($user === null) {
            header('Location: /');
            exit;
        }

        if (!$user['is_superuser']) {
            http_response_code(403);
            exit('Du bist nicht berechtigt, die Benutzerverwaltung aufzurufen.');
        }
    }

    public static function hasOtherActiveAdministrator(PDO $database, int $userId): bool
    {
        $statement = $database->prepare(
            'SELECT COUNT(*)
             FROM app_users
             WHERE id <> :id AND is_active = 1 AND is_superuser = 1'
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() > 0;
    }
}
