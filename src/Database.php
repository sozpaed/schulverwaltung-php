<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(): PDO
    {
        $configuration = self::configuration();
        return self::connectWithConfiguration($configuration);
    }

    public static function configuration(): array
    {
        $configuration = self::configurationFromFile();
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
            $environmentValue = getenv($name);
            if ($environmentValue !== false) {
                $configuration[$name] = $environmentValue;
            }
        }

        return $configuration;
    }

    public static function isValidDatabaseName(string $name): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]+\z/', $name) === 1;
    }

    public static function supportedServerType(string $version): ?string
    {
        if (stripos($version, 'MariaDB') !== false) {
            if (preg_match('/(?:^|[-\s])(\d+\.\d+\.\d+)-MariaDB\b/i', $version, $match) === 1
                && version_compare($match[1], '10.5.0', '>=')) {
                return 'mariadb';
            }

            return null;
        }

        if (preg_match('/\A(8\.4\.\d+)(?:[-+][A-Za-z0-9._+-]+)?\z/', $version) === 1) {
            return 'mysql';
        }

        return null;
    }

    public static function connectWithConfiguration(array $configuration): PDO
    {
        $host = self::requiredSetting('DB_HOST', $configuration);
        $database = self::requiredSetting('DB_NAME', $configuration);
        $username = self::requiredSetting('DB_USER', $configuration);
        $password = $configuration['DB_PASSWORD'] ?? null;

        if (!is_string($password)) {
            throw new RuntimeException('DB_PASSWORD fehlt in der Datenbankkonfiguration.');
        }

        if (!preg_match('/\A[A-Za-z0-9.:-]+\z/', $host)) {
            throw new RuntimeException('DB_HOST enthält einen ungültigen Wert.');
        }

        if (!self::isValidDatabaseName($database)) {
            throw new RuntimeException('DB_NAME enthält einen ungültigen Wert.');
        }

        $portValue = $configuration['DB_PORT'] ?? null;
        $port = $portValue === null || $portValue === ''
            ? 3306
            : filter_var(
                $portValue,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 65535]]
            );

        if ($port === false) {
            throw new RuntimeException('DB_PORT muss eine Zahl zwischen 1 und 65535 sein.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function configurationFromFile(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($path)) {
            return [];
        }

        $configuration = require $path;
        if (!is_array($configuration)) {
            throw new RuntimeException('config.php muss ein PHP-Array zurückgeben.');
        }

        return $configuration;
    }

    private static function requiredSetting(string $name, array $configuration): string
    {
        $value = $configuration[$name] ?? null;

        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                '%s fehlt in config.php und in den Umgebungsvariablen.',
                $name
            ));
        }

        return $value;
    }
}
