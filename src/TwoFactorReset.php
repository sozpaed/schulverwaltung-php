<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;
use RuntimeException;
use Throwable;

final class TwoFactorReset
{
    public static function reset(
        PDO $database,
        int $targetId,
        ?int $administratorId,
        string $administratorName,
        ?int $administratorVersion = null
    ): void
    {
        if ($administratorId === $targetId) {
            throw new RuntimeException('Das eigene 2FA kann nicht hier zurückgesetzt werden.');
        }
        $database->beginTransaction();
        try {
            if ($administratorId !== null) {
                $admin = $database->prepare(
                    'SELECT username FROM app_users WHERE id = :id AND is_active = 1
                     AND is_superuser = 1 AND sitzungs_version = :version FOR UPDATE'
                );
                $admin->execute(['id' => $administratorId, 'version' => $administratorVersion]);
                $currentName = $admin->fetchColumn();
                if (!is_string($currentName)) {
                    throw new RuntimeException('Die Administratorberechtigung ist nicht mehr gültig.');
                }
                $administratorName = $currentName;
            }
            $select = $database->prepare('SELECT username, totp_enabled FROM app_users WHERE id = :id FOR UPDATE');
            $select->execute(['id' => $targetId]);
            $target = $select->fetch();
            if ($target === false || !(bool) $target['totp_enabled']) {
                throw new RuntimeException('Das Konto wurde nicht gefunden oder 2FA ist nicht aktiviert.');
            }
            $update = $database->prepare(
                'UPDATE app_users SET totp_secret = NULL, totp_enabled = 0, totp_last_counter = NULL,
                 sitzungs_version = sitzungs_version + 1 WHERE id = :id'
            );
            $update->execute(['id' => $targetId]);
            $delete = $database->prepare('DELETE FROM totp_wiederherstellungscodes WHERE benutzer_id = :id');
            $delete->execute(['id' => $targetId]);
            $log = $database->prepare(
                'INSERT INTO admin_protokoll (administrator_id, administrator_name, benutzer_id, benutzername, aktion)
                 VALUES (:admin_id, :admin_name, :user_id, :username, :action)'
            );
            $log->execute([
                'admin_id' => $administratorId, 'admin_name' => $administratorName,
                'user_id' => $targetId, 'username' => $target['username'], 'action' => '2fa_reset',
            ]);
            $database->commit();
        } catch (Throwable $exception) {
            $database->rollBack();
            throw $exception;
        }
    }
}
