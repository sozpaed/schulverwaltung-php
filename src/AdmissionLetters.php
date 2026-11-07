<?php

declare(strict_types=1);

namespace Schulverwaltung;

use InvalidArgumentException;
use PDO;

final class AdmissionLetters
{
    private const SCHOOL_FORMS = [
        'foerderstufe' => 'Förderstufe',
        'gymnasium' => 'Gymnasium',
    ];

    private const STATUSES = [
        'in_pruefung' => 'In Prüfung',
        'angenommen' => 'Angenommen',
        'abgelehnt' => 'Abgelehnt',
    ];

    public static function validateFilters(mixed $schoolForm, mixed $filterType, mixed $filterValue): array
    {
        if (!is_string($schoolForm) || !array_key_exists($schoolForm, self::SCHOOL_FORMS)) {
            throw new InvalidArgumentException('Ungültige Schulform.');
        }

        if ($filterType === 'status'
            && is_string($filterValue)
            && array_key_exists($filterValue, self::STATUSES)) {
            return [
                'school_form' => $schoolForm,
                'school_label' => self::SCHOOL_FORMS[$schoolForm],
                'filter_type' => 'status',
                'filter_value' => $filterValue,
                'filter_label' => self::STATUSES[$filterValue],
            ];
        }

        if ($filterType === 'warteliste' && $filterValue === 'ja') {
            return [
                'school_form' => $schoolForm,
                'school_label' => self::SCHOOL_FORMS[$schoolForm],
                'filter_type' => 'warteliste',
                'filter_value' => 'ja',
                'filter_label' => 'Warteliste (Ja)',
            ];
        }

        throw new InvalidArgumentException('Ungültige Auswahl für den Serienbrief.');
    }

    public static function findRecipients(PDO $database, array $filters): array
    {
        $condition = $filters['filter_type'] === 'status'
            ? 's.status = :status'
            : 's.warteliste = 1';
        $statement = $database->prepare(
            'SELECT s.id, s.vorname, s.nachname, s.strasse, s.plz, s.ort,
                    e.id AS guardian_id, e.vorname AS guardian_vorname,
                    e.nachname AS guardian_nachname, e.adresse_wie_schuelerin,
                    e.strasse AS guardian_strasse, e.plz AS guardian_plz,
                    e.ort AS guardian_ort
             FROM schueler s
             LEFT JOIN erziehungsberechtigte e
                 ON e.schueler_id = s.id AND e.erziehungsberechtigt = 1
             WHERE s.schulform = :school_form AND ' . $condition . '
             ORDER BY s.nachname, s.vorname, s.id, e.nachname, e.vorname, e.id'
        );
        $parameters = ['school_form' => $filters['school_form']];
        if ($filters['filter_type'] === 'status') {
            $parameters['status'] = $filters['filter_value'];
        }
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function groupRecipients(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $studentName = trim($row['vorname'] . ' ' . $row['nachname']);
            $hasGuardian = $row['guardian_id'] !== null;
            $guardianAddress = $hasGuardian && !(bool) $row['adresse_wie_schuelerin'];
            $guardianStreet = trim((string) ($row['guardian_strasse'] ?? ''));
            $guardianPostalCode = trim((string) ($row['guardian_plz'] ?? ''));
            $guardianCity = trim((string) ($row['guardian_ort'] ?? ''));
            $hasGuardianAddress = $guardianStreet !== '' || $guardianPostalCode !== '' || $guardianCity !== '';

            if ($guardianAddress && $hasGuardianAddress) {
                $street = $guardianStreet;
                $postalCode = $guardianPostalCode;
                $city = $guardianCity;
            } else {
                $street = trim((string) $row['strasse']);
                $postalCode = trim((string) ($row['plz'] ?? ''));
                $city = trim((string) $row['ort']);
            }

            $address = [$street, $postalCode, $city];
            $normalizedAddress = array_map(
                static fn (string $part): string => self::normalizeAddressPart($part),
                $address
            );
            $key = $row['id'] . ':' . hash('sha256', json_encode($normalizedAddress, JSON_THROW_ON_ERROR));

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'student_name' => $studentName,
                    'address' => $address,
                    'recipients' => [],
                ];
            }

            if ($hasGuardian) {
                $guardianName = trim($row['guardian_vorname'] . ' ' . $row['guardian_nachname']);
                if ($guardianName !== '' && !in_array($guardianName, $groups[$key]['recipients'], true)) {
                    $groups[$key]['recipients'][] = $guardianName;
                }
            }
        }

        foreach ($groups as &$group) {
            if ($group['recipients'] === []) {
                $group['recipients'][] = 'Erziehungsberechtigte der Schülerin ' . $group['student_name'];
            }
        }
        unset($group);

        return array_values($groups);
    }

    public static function letterContent(array $filters, string $studentName): array
    {
        $schoolForm = $filters['school_label'];
        $schoolAccusative = $filters['school_form'] === 'gymnasium'
            ? 'das Gymnasium'
            : 'die Förderstufe';
        $schoolLocation = $filters['school_form'] === 'gymnasium'
            ? 'am Gymnasium'
            : 'in der Förderstufe';

        if ($filters['filter_type'] === 'warteliste') {
            return [
                'subject' => 'Aufnahmeverfahren – Warteliste ' . $schoolForm,
                'paragraphs' => [
                    'Für ' . $studentName . ' können wir derzeit noch keinen festen Platz ' . $schoolLocation
                        . ' an der Marienschule anbieten. Wir haben ' . $studentName . ' auf die Warteliste gesetzt.',
                    'Sollte ein Platz frei werden, melden wir uns bei Ihnen. Bitte verstehen Sie dieses Schreiben '
                        . 'nicht als verbindliche Aufnahmezusage.',
                ],
            ];
        }

        return match ($filters['filter_value']) {
            'in_pruefung' => [
                'subject' => 'Aufnahmeverfahren – ' . $schoolForm,
                'paragraphs' => [
                    'Vielen Dank für Ihr Interesse an der Marienschule. Die Prüfung der Bewerbung von '
                        . $studentName . ' für ' . $schoolAccusative . ' ist noch nicht abgeschlossen.',
                    'Sobald eine Entscheidung getroffen wurde, erhalten Sie eine weitere Nachricht. Dieses '
                        . 'Schreiben ist keine Aufnahmezusage.',
                ],
            ],
            'angenommen' => [
                'subject' => 'Aufnahme in ' . $schoolAccusative,
                'paragraphs' => [
                    'Wir freuen uns, Ihnen mitteilen zu können, dass wir ' . $studentName . ' in '
                        . $schoolAccusative
                        . ' an der Marienschule aufnehmen können.',
                    'Weitere Informationen zum Schulbeginn und zu den nächsten Schritten erhalten Sie gesondert.',
                ],
            ],
            'abgelehnt' => [
                'subject' => 'Aufnahmeverfahren – ' . $schoolForm,
                'paragraphs' => [
                    'Nach Prüfung der vorliegenden Unterlagen müssen wir Ihnen leider mitteilen, dass wir '
                        . $studentName . ' im aktuellen Aufnahmeverfahren keinen Platz ' . $schoolLocation
                        . ' anbieten können.',
                    'Wir bedauern, Ihnen keine positive Nachricht geben zu können.',
                ],
            ],
            default => throw new InvalidArgumentException('Ungültiger Status für den Serienbrief.'),
        };
    }

    private static function normalizeAddressPart(string $part): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($part));
        if ($normalized === null) {
            throw new InvalidArgumentException('Eine Adresse enthält ungültige Zeichen.');
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }
}
