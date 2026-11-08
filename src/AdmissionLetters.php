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
        'keine' => 'Keine Schulform',
        'beide' => 'Förderstufe und Gymnasium',
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
        $schoolCondition = $filters['school_form'] === 'beide'
            ? "s.schulform IN ('foerderstufe', 'gymnasium')"
            : 's.schulform = :school_form';
        $statement = $database->prepare(
            'SELECT s.id, s.vorname, s.nachname, s.schulform, s.klasse, s.strasse, s.plz, s.ort,
                    e.id AS guardian_id, e.vorname AS guardian_vorname,
                    e.nachname AS guardian_nachname, e.beziehung AS guardian_beziehung, e.adresse_wie_schuelerin,
                    e.strasse AS guardian_strasse, e.plz AS guardian_plz,
                    e.ort AS guardian_ort
             FROM schueler s
             LEFT JOIN erziehungsberechtigte e
                 ON e.schueler_id = s.id AND e.erziehungsberechtigt = 1
             WHERE ' . $schoolCondition . ' AND ' . $condition . '
             ORDER BY s.nachname, s.vorname, s.id, e.nachname, e.vorname, e.id'
        );
        $parameters = [];
        if ($filters['school_form'] !== 'beide') {
            $parameters['school_form'] = $filters['school_form'] === 'keine' ? '' : $filters['school_form'];
        }
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
                    'school_form' => $row['schulform'],
                    'class' => $row['klasse'],
                    'address' => $address,
                    'recipients' => [],
                    'salutations_by_relationship' => ['mutter' => [], 'vater' => [], 'andere' => []],
                    'surnames_by_relationship' => ['mutter' => [], 'vater' => [], 'andere' => []],
                ];
            }

            if ($hasGuardian) {
                $guardianName = trim($row['guardian_vorname'] . ' ' . $row['guardian_nachname']);
                if ($guardianName !== '' && !in_array($guardianName, $groups[$key]['recipients'], true)) {
                    $groups[$key]['recipients'][] = $guardianName;
                }
                $relationship = $row['guardian_beziehung'];
                $surname = trim($row['guardian_nachname']);
                $salutation = match (true) {
                    $relationship === 'mutter' && $surname !== '' => 'Sehr geehrte Frau ' . $surname . ',',
                    $relationship === 'vater' && $surname !== '' => 'Sehr geehrter Herr ' . $surname . ',',
                    default => 'Sehr geehrte Erziehungsberechtigte,',
                };
                $category = in_array($relationship, ['mutter', 'vater'], true) && $surname !== ''
                    ? $relationship
                    : 'andere';
                if (!in_array($salutation, $groups[$key]['salutations_by_relationship'][$category], true)) {
                    $groups[$key]['salutations_by_relationship'][$category][] = $salutation;
                }
                if ($surname !== '' && !in_array($surname, $groups[$key]['surnames_by_relationship'][$category], true)) {
                    $groups[$key]['surnames_by_relationship'][$category][] = $surname;
                }
            }
        }

        foreach ($groups as &$group) {
            if ($group['recipients'] === []) {
                $group['recipients'][] = 'Erziehungsberechtigte der Schülerin ' . $group['student_name'];
            }
            $group['salutations'] = array_merge(...array_values($group['salutations_by_relationship']));
            $group['guardian_salutation'] = implode("\n", $group['salutations']);
            $group['guardian_surname'] = implode(' und ', array_unique(array_merge(...array_values($group['surnames_by_relationship']))));
            if ($group['salutations'] === []) {
                $group['salutations'][] = 'Sehr geehrte Erziehungsberechtigte,';
            }
            unset($group['salutations_by_relationship'], $group['surnames_by_relationship']);
        }
        unset($group);

        return array_values($groups);
    }

    public static function filtersForRecipient(array $filters, array $recipient): array
    {
        $schoolForm = $recipient['school_form'] === '' ? 'keine' : $recipient['school_form'];
        return self::validateFilters($schoolForm, $filters['filter_type'], $filters['filter_value']);
    }

    public static function letterContent(array $filters, string $studentName, string $schoolName): array
    {
        $schoolForm = $filters['school_label'];
        [$schoolAccusative, $schoolLocation, $schoolAdmission] = match ($filters['school_form']) {
            'gymnasium' => ['das Gymnasium', 'am Gymnasium', 'in das Gymnasium'],
            'foerderstufe' => ['die Förderstufe', 'in der Förderstufe', 'in die Förderstufe'],
            'keine' => ['einen Schulplatz', 'an unserer Schule', 'an unserer Schule'],
            default => throw new InvalidArgumentException('Ungültige Schulform für den Serienbrief.'),
        };
        $waitlistLocation = $filters['school_form'] === 'keine'
            ? $schoolLocation . ' ' . $schoolName
            : $schoolLocation . ' an unserer Schule ' . $schoolName;

        if ($filters['filter_type'] === 'warteliste') {
            return [
                'subject' => 'Aufnahmeverfahren – Warteliste ' . $schoolForm,
                'paragraphs' => [
                    'Für ' . $studentName . ' können wir derzeit noch keinen festen Platz ' . $waitlistLocation
                        . ' anbieten. Wir haben ' . $studentName . ' auf die Warteliste gesetzt.',
                    'Sollte ein Platz frei werden, melden wir uns bei Ihnen. Bitte verstehen Sie dieses Schreiben '
                        . 'nicht als verbindliche Aufnahmezusage.',
                ],
            ];
        }

        return match ($filters['filter_value']) {
            'in_pruefung' => [
                'subject' => 'Aufnahmeverfahren – ' . $schoolForm,
                'paragraphs' => [
                    'Vielen Dank für Ihr Interesse an unserer Schule ' . $schoolName . '. Die Prüfung der Bewerbung von '
                        . $studentName . ' für ' . $schoolAccusative . ' ist noch nicht abgeschlossen.',
                    'Sobald eine Entscheidung getroffen wurde, erhalten Sie eine weitere Nachricht. Dieses '
                        . 'Schreiben ist keine Aufnahmezusage.',
                ],
            ],
            'angenommen' => [
                'subject' => $filters['school_form'] === 'keine' ? 'Aufnahme an unserer Schule' : 'Aufnahme in ' . $schoolAccusative,
                'paragraphs' => [
                    'Wir freuen uns, Ihnen mitteilen zu können, dass wir ' . $studentName . ' '
                        . $schoolAdmission
                        . ($filters['school_form'] === 'keine' ? ' ' : ' an unserer Schule ')
                        . $schoolName . ' aufnehmen können.',
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
