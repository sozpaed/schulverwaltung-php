<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;

final class Catalogs
{
    private const DEFINITIONS = [
        'staatsangehoerigkeiten' => [
            'title' => 'Staatsangehörigkeiten',
            'singular' => 'Staatsangehörigkeit',
            'table' => 'staatsangehoerigkeiten',
            'has_location' => false,
            'name_limit' => 100,
            'location_limit' => 0,
        ],
        'religionen' => [
            'title' => 'Religionen',
            'singular' => 'Religion',
            'table' => 'religionen',
            'has_location' => false,
            'name_limit' => 50,
            'location_limit' => 0,
        ],
        'grundschulen' => [
            'title' => 'Grundschulen',
            'singular' => 'Grundschule',
            'table' => 'grundschulen',
            'has_location' => true,
            'name_limit' => 200,
            'location_limit' => 100,
        ],
        'marketing_kanaele' => [
            'title' => 'Marketingkanäle',
            'singular' => 'Marketingkanal',
            'table' => 'marketing_kanaele',
            'has_location' => false,
            'name_limit' => 100,
            'location_limit' => 0,
        ],
    ];

    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    public static function definition(string $type): ?array
    {
        return self::DEFINITIONS[$type] ?? null;
    }

    public static function label(array $entry, array $definition): string
    {
        if ($definition['has_location'] && ($entry['ort'] ?? '') !== '') {
            return $entry['name'] . ' (' . $entry['ort'] . ')';
        }

        return $entry['name'];
    }

    public static function findDuplicate(
        PDO $database,
        array $definition,
        string $name,
        string $location,
        ?int $excludeId = null
    ): bool {
        $sql = sprintf('SELECT 1 FROM %s WHERE name = :name', $definition['table']);
        $parameters = ['name' => $name];

        if ($definition['has_location']) {
            $sql .= ' AND ort = :ort';
            $parameters['ort'] = $location;
        }

        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $excludeId;
        }

        $statement = $database->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public static function create(PDO $database, array $definition, string $name, string $location): int
    {
        $sql = $definition['has_location']
            ? sprintf('INSERT INTO %s (name, ort) VALUES (:name, :ort)', $definition['table'])
            : sprintf('INSERT INTO %s (name) VALUES (:name)', $definition['table']);
        $statement = $database->prepare($sql);
        $parameters = ['name' => $name];

        if ($definition['has_location']) {
            $parameters['ort'] = $location;
        }

        $statement->execute($parameters);

        return (int) $database->lastInsertId();
    }

    public static function update(
        PDO $database,
        array $definition,
        int $id,
        string $name,
        string $location
    ): void {
        $sql = $definition['has_location']
            ? sprintf('UPDATE %s SET name = :name, ort = :ort WHERE id = :id', $definition['table'])
            : sprintf('UPDATE %s SET name = :name WHERE id = :id', $definition['table']);
        $statement = $database->prepare($sql);
        $parameters = ['id' => $id, 'name' => $name];

        if ($definition['has_location']) {
            $parameters['ort'] = $location;
        }

        $statement->execute($parameters);
    }
}
