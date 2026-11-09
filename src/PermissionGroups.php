<?php

declare(strict_types=1);

namespace Schulverwaltung;

use InvalidArgumentException;
use PDO;

final class PermissionGroups
{
    public const RIGHTS = [
        'can_export' => 'Datenexport',
        'can_edit_letter_templates' => 'Serienbriefvorlagen bearbeiten',
        'can_view_maintenance' => 'Wartungsübersicht ansehen',
        'can_view_statistics' => 'Statistik ansehen',
        'can_delete_catalogs' => 'Auswahllisteneinträge löschen',
        'can_delete_students' => 'Schülerinnen löschen',
    ];

    public static function validateSelection(PDO $database, mixed $selection): array
    {
        if ($selection === null) {
            return [];
        }
        if (!is_array($selection) || count($selection) > 100) {
            throw new InvalidArgumentException('Ungültige Gruppenauswahl.');
        }

        $ids = [];
        foreach ($selection as $value) {
            if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
                throw new InvalidArgumentException('Ungültige Gruppenauswahl.');
            }
            $id = (int) $value;
            if (in_array($id, $ids, true)) {
                throw new InvalidArgumentException('Eine Gruppe wurde mehrfach ausgewählt.');
            }
            $ids[] = $id;
        }
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $database->prepare("SELECT id FROM berechtigungsgruppen WHERE id IN ($placeholders)");
        $statement->execute($ids);
        $existing = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
        sort($existing);
        if ($ids !== $existing) {
            throw new InvalidArgumentException('Eine ausgewählte Berechtigungsgruppe existiert nicht.');
        }
        return $ids;
    }

    public static function replaceMemberships(PDO $database, int $userId, array $groupIds): void
    {
        $delete = $database->prepare('DELETE FROM benutzer_berechtigungsgruppen WHERE benutzer_id = :user_id');
        $delete->execute(['user_id' => $userId]);
        $insert = $database->prepare(
            'INSERT INTO benutzer_berechtigungsgruppen (benutzer_id, gruppe_id) VALUES (:user_id, :group_id)'
        );
        foreach ($groupIds as $groupId) {
            $insert->execute(['user_id' => $userId, 'group_id' => $groupId]);
        }
    }

    public static function forUser(PDO $database, int $userId): array
    {
        $statement = $database->prepare(
            'SELECT gruppe_id FROM benutzer_berechtigungsgruppen WHERE benutzer_id = :user_id ORDER BY gruppe_id'
        );
        $statement->execute(['user_id' => $userId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function validateName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Der Gruppenname ist ungültig.');
        }
        $name = trim($value);
        $length = preg_match_all('/./us', $name);
        if ($length === false || $length < 1 || $length > 100 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new InvalidArgumentException('Der Gruppenname muss zwischen 1 und 100 Zeichen lang sein.');
        }
        return $name;
    }

    public static function validateRights(mixed $input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Ungültige Gruppenauswahl.');
        }
        $rights = array_fill_keys(array_keys(self::RIGHTS), 0);
        foreach ($input as $name => $value) {
            if (!array_key_exists($name, $rights) || $value !== '1') {
                throw new InvalidArgumentException('Ungültige Gruppenauswahl.');
            }
            $rights[$name] = 1;
        }
        return $rights;
    }

    public static function effectivePermissions(PDO $database, array $user): array
    {
        $statement = $database->prepare(
            'SELECT ' . implode(', ', array_map(
                static fn (string $right): string => 'COALESCE(MAX(g.' . $right . '), 0) AS ' . $right,
                array_keys(self::RIGHTS)
            )) . '
             FROM benutzer_berechtigungsgruppen ug
             JOIN berechtigungsgruppen g ON g.id = ug.gruppe_id
             WHERE ug.benutzer_id = :user_id'
        );
        $statement->execute(['user_id' => $user['id']]);
        $groupRights = $statement->fetch(PDO::FETCH_ASSOC);
        foreach (self::RIGHTS as $right => $label) {
            $user[$right] = (bool) ($user[$right] ?? false) || (bool) $groupRights[$right];
        }
        return $user;
    }

    public static function listForUsers(PDO $database, array $users): array
    {
        if ($users === []) {
            return [];
        }
        $ids = array_map(static fn (array $user): int => (int) $user['id'], $users);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $database->prepare(
            'SELECT ug.benutzer_id, g.id, g.name
             FROM benutzer_berechtigungsgruppen ug
             JOIN berechtigungsgruppen g ON g.id = ug.gruppe_id
             WHERE ug.benutzer_id IN (' . $placeholders . ')
             ORDER BY g.name, g.id'
        );
        $statement->execute($ids);
        $groups = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[(int) $row['benutzer_id']][] = $row['name'];
        }
        return $groups;
    }
}
