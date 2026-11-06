<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;

final class Auth
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$IfUPGKIx2zLYHIOi454ZE.snvcuK0N7ZQeEB9.Zlk5AIulTZbLyYm';

    public static function attempt(
        PDO $database,
        string $username,
        string $password,
        mixed $twoFactorCode
    ): bool
    {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $throttle = new LoginThrottle($database, $username, $remoteAddress);
        $success = false;
        $failureCount = 0;
        try {
            [$userFailures, $ipFailures] = $throttle->failureCounts();
            $failureCount = max($userFailures, $ipFailures) + 1;
            $statement = $database->prepare(
                'SELECT id, username, password_hash, is_active, is_superuser, can_export, can_delete_catalogs, can_delete_students,
                        totp_secret, totp_enabled, totp_last_counter
                 FROM app_users
                 WHERE username = :username'
            );
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();
            $userId = $user === false ? null : (int) $user['id'];
            $reason = null;

            if ($userFailures >= LoginThrottle::USER_LIMIT || $ipFailures >= LoginThrottle::IP_LIMIT) {
                $reason = 'gesperrt';
            } else {
                $passwordValid = password_verify(
                    $password,
                    $user === false ? self::DUMMY_PASSWORD_HASH : $user['password_hash']
                );
                if ($user === false || !$passwordValid) {
                    $reason = 'passwort';
                } elseif (!(bool) $user['is_active']) {
                    $reason = 'inaktiv';
                } elseif ((bool) $user['totp_enabled']) {
                    $secret = TwoFactor::decryptSecret($user['totp_secret'], $userId);
                    $counter = TwoFactor::matchingCounter($secret, $twoFactorCode);
                    if ($counter === null || !self::storeTotpCounter($database, $userId, $counter)) {
                        $reason = '2fa';
                    }
                }
            }

            $success = $reason === null;
            $throttle->record($userId, $success, $reason);
        } finally {
            $throttle->release();
        }

        if (!$success) {
            LoginThrottle::delay($failureCount);
            return false;
        }

        if (random_int(1, 100) === 1) {
            LoginThrottle::cleanup($database);
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'is_superuser' => (bool) $user['is_superuser'],
            'can_export' => (bool) $user['can_export'],
            'can_delete_catalogs' => (bool) $user['can_delete_catalogs'],
            'can_delete_students' => (bool) $user['can_delete_students'],
        ];

        return true;
    }

    public static function user(PDO $database): ?array
    {
        $sessionUser = $_SESSION['user'] ?? null;

        if (!is_array($sessionUser) || !isset($sessionUser['id'])) {
            return null;
        }

        $statement = $database->prepare(
            'SELECT id, username, is_active, is_superuser, can_export, can_delete_catalogs, can_delete_students, totp_enabled
             FROM app_users
             WHERE id = :id'
        );
        $statement->execute(['id' => $sessionUser['id']]);
        $user = $statement->fetch();

        if ($user === false || !(bool) $user['is_active']) {
            self::logout();
            return null;
        }

        $currentUser = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'is_superuser' => (bool) $user['is_superuser'],
            'can_export' => (bool) $user['can_export'],
            'can_delete_catalogs' => (bool) $user['can_delete_catalogs'],
            'can_delete_students' => (bool) $user['can_delete_students'],
            'totp_enabled' => (bool) $user['totp_enabled'],
        ];
        $_SESSION['user'] = $currentUser;

        return $currentUser;
    }

    public static function storeTotpCounter(PDO $database, int $userId, int $counter): bool
    {
        $statement = $database->prepare(
            'UPDATE app_users
             SET totp_last_counter = :new_counter
             WHERE id = :id
               AND (totp_last_counter IS NULL OR totp_last_counter < :previous_counter)'
        );
        $statement->execute([
            'new_counter' => $counter,
            'previous_counter' => $counter,
            'id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(mixed $token): bool
    {
        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
