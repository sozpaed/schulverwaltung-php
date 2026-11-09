<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;

final class StudentNavigation
{
    public const STUDENT_ORDER = 's.nachname, s.vorname, s.id';

    public static function studentFilter(array $filter): array
    {
        $search = $filter['q'] ?? '';
        $conditions = ['(:search = \'\' OR s.vorname LIKE :first_name OR s.nachname LIKE :last_name OR s.ort LIKE :search_city)'];
        $parameters = [
            'search' => $search,
            'first_name' => '%' . $search . '%',
            'last_name' => '%' . $search . '%',
            'search_city' => '%' . $search . '%',
        ];
        if (($filter['status'] ?? '') !== '') {
            $conditions[] = 's.status = :status';
            $parameters['status'] = $filter['status'];
        }
        if (($filter['klasse'] ?? '') !== '') {
            $conditions[] = 's.klasse = :class';
            $parameters['class'] = $filter['klasse'] === '__leer' ? '' : $filter['klasse'];
        }
        if (($filter['grundschule'] ?? '') === '__leer') {
            $conditions[] = 's.grundschule_id IS NULL';
        } elseif (($filter['grundschule'] ?? '') !== '') {
            $conditions[] = 's.grundschule_id = :school_id';
            $parameters['school_id'] = $filter['grundschule'];
        }
        if (($filter['wartung'] ?? '') !== '') {
            $definition = Maintenance::definition('schueler', $filter['wartung']);
            if ($definition === null) {
                throw new \InvalidArgumentException('Ungültiger Wartungsfilter.');
            }
            $conditions[] = '(' . $definition['condition'] . ')';
        }
        return [implode(' AND ', $conditions), $parameters];
    }

    public static function guardianFilter(array $filter): array
    {
        $search = $filter['q'] ?? '';
        $like = '%' . $search . '%';
        $conditions = ['(:search = \'\'
            OR e.vorname LIKE :guardian_first_name
            OR e.nachname LIKE :guardian_last_name
            OR s.vorname LIKE :student_first_name
            OR s.nachname LIKE :student_last_name
            OR e.email LIKE :email)'];
        $parameters = [
            'search' => $search,
            'guardian_first_name' => $like,
            'guardian_last_name' => $like,
            'student_first_name' => $like,
            'student_last_name' => $like,
            'email' => $like,
        ];
        if (($filter['wartung'] ?? '') !== '') {
            $definition = Maintenance::definition('erziehungsberechtigte', $filter['wartung']);
            if ($definition === null) {
                throw new \InvalidArgumentException('Ungültiger Wartungsfilter.');
            }
            $conditions[] = '(' . $definition['condition'] . ')';
        }
        return [implode(' AND ', $conditions), $parameters];
    }

    public static function neighbors(
        PDO $database,
        array $filter,
        int $id,
        bool $guardian = false,
        ?int $studentId = null,
        bool $guardianList = false
    ): array {
        [$where, $parameters] = $guardian && $guardianList
            ? self::guardianFilter($filter)
            : self::studentFilter($filter);
        if ($guardian && $studentId !== null) {
            $where = 's.id = :navigation_student';
            $parameters = ['navigation_student' => $studentId];
        }
        $from = $guardian ? 'schueler s JOIN erziehungsberechtigte e ON e.schueler_id = s.id' : 'schueler s';
        $idColumn = $guardian ? 'e.id' : 's.id';
        $table = $guardian ? 'erziehungsberechtigte' : 'schueler';
        $order = $guardian && $studentId === null
            ? ($guardianList ? 'e.nachname, e.vorname, e.id' : self::STUDENT_ORDER . ', e.nachname, e.vorname, e.id')
            : ($guardian ? 'e.nachname, e.vorname, e.id' : self::STUDENT_ORDER);
        $studentColumns = $guardian ? ', p.schueler_id AS previous_student_id, n.schueler_id AS next_student_id' : '';
        $statement = $database->prepare(
            "SELECT p.id AS previous_id, p.vorname AS previous_first, p.nachname AS previous_last,
                    n.id AS next_id, n.vorname AS next_first, n.nachname AS next_last $studentColumns
             FROM (
                 SELECT $idColumn AS id,
                        LAG($idColumn) OVER (ORDER BY $order) AS previous_id,
                        LEAD($idColumn) OVER (ORDER BY $order) AS next_id
                 FROM $from WHERE $where
             ) navigation_rows
             LEFT JOIN $table p ON p.id = navigation_rows.previous_id
             LEFT JOIN $table n ON n.id = navigation_rows.next_id
             WHERE navigation_rows.id = :navigation_id"
        );
        $parameters['navigation_id'] = $id;
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $neighbors = ['previous' => null, 'next' => null];
        if ($row !== false) {
            foreach (array_keys($neighbors) as $position) {
                if ($row[$position . '_id'] !== null) {
                    $neighbors[$position] = [
                        'id' => $row[$position . '_id'],
                        'vorname' => $row[$position . '_first'],
                        'nachname' => $row[$position . '_last'],
                    ];
                    if ($guardian) {
                        $neighbors[$position]['schueler_id'] = $row[$position . '_student_id'];
                    }
                }
            }
        }
        return $neighbors;
    }
}
