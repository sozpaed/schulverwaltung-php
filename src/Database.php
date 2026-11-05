<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(): PDO
    {
        $host = self::requiredEnvironmentVariable('DB_HOST');
        $database = self::requiredEnvironmentVariable('DB_NAME');
        $username = self::requiredEnvironmentVariable('DB_USER');
        $password = getenv('DB_PASSWORD');

        if ($password === false) {
            throw new RuntimeException('Die Umgebungsvariable DB_PASSWORD ist nicht gesetzt.');
        }

        if (!preg_match('/\A[A-Za-z0-9.:-]+\z/', $host)) {
            throw new RuntimeException('DB_HOST enthält einen ungültigen Wert.');
        }

        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $database)) {
            throw new RuntimeException('DB_NAME enthält einen ungültigen Wert.');
        }

        $portValue = getenv('DB_PORT');
        $port = $portValue === false || $portValue === ''
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

    private static function requiredEnvironmentVariable(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Die Umgebungsvariable %s ist nicht gesetzt.', $name));
        }

        return $value;
    }
}
