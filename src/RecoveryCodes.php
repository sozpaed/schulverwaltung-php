<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;
use RuntimeException;

final class RecoveryCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const COUNT = 10;

    public static function replace(PDO $database, int $userId): array
    {
        if (!$database->inTransaction()) {
            throw new RuntimeException('Wiederherstellungscodes benötigen eine Datenbanktransaktion.');
        }

        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        $delete = $database->prepare('DELETE FROM totp_wiederherstellungscodes WHERE benutzer_id = :id');
        $delete->execute(['id' => $userId]);
        $insert = $database->prepare(
            'INSERT INTO totp_wiederherstellungscodes (benutzer_id, code_hash) VALUES (:id, :hash)'
        );
        foreach ($codes as $code) {
            $insert->execute(['id' => $userId, 'hash' => password_hash($code, PASSWORD_DEFAULT)]);
        }
        return array_map(static fn (string $code): string => substr($code, 0, 5) . '-' . substr($code, 5), $codes);
    }

    public static function consume(PDO $database, int $userId, int $version, mixed $input): bool
    {
        if (!is_string($input)) {
            return false;
        }
        $code = strtoupper(str_replace('-', '', trim($input)));
        if (preg_match('/\A[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{10}\z/', $code) !== 1) {
            return false;
        }
        $statement = $database->prepare(
            'SELECT id, code_hash FROM totp_wiederherstellungscodes
             WHERE benutzer_id = :id AND verwendet_am IS NULL ORDER BY id'
        );
        $statement->execute(['id' => $userId]);
        foreach ($statement->fetchAll() as $row) {
            if (password_verify($code, $row['code_hash'])) {
                $update = $database->prepare(
                    'UPDATE totp_wiederherstellungscodes
                     SET verwendet_am = NOW()
                     WHERE id = :id AND verwendet_am IS NULL
                       AND EXISTS (SELECT 1 FROM app_users
                                   WHERE id = :user_id AND is_active = 1 AND totp_enabled = 1
                                     AND sitzungs_version = :version)'
                );
                $update->execute(['id' => $row['id'], 'user_id' => $userId, 'version' => $version]);
                return $update->rowCount() === 1;
            }
        }
        return false;
    }
}
