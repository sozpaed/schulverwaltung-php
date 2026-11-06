<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;
use RuntimeException;

final class LoginThrottle
{
    public const USER_LIMIT = 5;
    public const IP_LIMIT = 20;
    public const WINDOW_MINUTES = 15;
    public const RETENTION_DAYS = 90;

    private PDO $database;
    private string $username;
    private string $ip;
    private array $locks = [];

    public function __construct(PDO $database, string $username, string $remoteAddress)
    {
        $ip = @inet_pton($remoteAddress);
        if ($ip === false) {
            throw new RuntimeException('Die IP-Adresse des Anmeldeversuchs ist ungültig.');
        }
        if (preg_match('//u', $username) !== 1) {
            throw new RuntimeException('Der Benutzername enthält ungültige Zeichen.');
        }

        $this->database = $database;
        $this->ip = $ip;
        $statement = $database->prepare(
            'SELECT LOWER(LEFT(:username, 150)) AS normalized,
                    HEX(WEIGHT_STRING(LEFT(:weight_username, 150) COLLATE utf8mb4_unicode_ci)) AS weight'
        );
        $statement->execute(['username' => trim($username), 'weight_username' => trim($username)]);
        $normalized = $statement->fetch();
        $this->username = $normalized['normalized'];

        $locks = [
            'login-u-' . substr(hash('sha256', $normalized['weight']), 0, 48),
            'login-i-' . substr(hash('sha256', $ip), 0, 48),
        ];
        sort($locks, SORT_STRING);
        try {
            foreach ($locks as $lock) {
                $statement = $database->prepare('SELECT GET_LOCK(:name, 5)');
                $statement->execute(['name' => $lock]);
                if ((int) $statement->fetchColumn() !== 1) {
                    throw new RuntimeException('Die Anmeldung ist vorübergehend ausgelastet. Bitte versuche es erneut.');
                }
                $this->locks[] = $lock;
            }
        } catch (\Throwable $exception) {
            $this->release();
            throw $exception;
        }
    }

    public function failureCounts(): array
    {
        $window = self::WINDOW_MINUTES;
        $statement = $this->database->prepare(
            "SELECT COUNT(*) FROM login_versuche
             WHERE benutzername = :username AND erfolgreich = 0 AND grund <> 'gesperrt'
               AND erstellt_am > NOW() - INTERVAL {$window} MINUTE"
        );
        $statement->execute(['username' => $this->username]);
        $userCount = (int) $statement->fetchColumn();

        $statement = $this->database->prepare(
            "SELECT COUNT(*) FROM login_versuche
             WHERE ip = :ip AND erfolgreich = 0 AND grund <> 'gesperrt'
               AND erstellt_am > NOW() - INTERVAL {$window} MINUTE"
        );
        $statement->execute(['ip' => $this->ip]);

        return [$userCount, (int) $statement->fetchColumn()];
    }

    public function record(?int $userId, bool $success, ?string $reason): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO login_versuche (benutzername, benutzer_id, ip, erfolgreich, grund)
             VALUES (:username, :user_id, :ip, :success, :reason)'
        );
        $statement->execute([
            'username' => $this->username,
            'user_id' => $userId,
            'ip' => $this->ip,
            'success' => (int) $success,
            'reason' => $reason,
        ]);
    }

    public function release(): void
    {
        foreach (array_reverse($this->locks) as $lock) {
            $statement = $this->database->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => $lock]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('Die Anmeldesperre konnte nicht freigegeben werden.');
            }
        }
        $this->locks = [];
    }

    public static function cleanup(PDO $database): int
    {
        $days = self::RETENTION_DAYS;
        return $database->exec("DELETE FROM login_versuche WHERE erstellt_am < NOW() - INTERVAL {$days} DAY");
    }

    public static function delay(int $failureCount): void
    {
        usleep(min(2000000, max(1, $failureCount) * 200000));
    }
}
