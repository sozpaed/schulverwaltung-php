<?php

declare(strict_types=1);

namespace Schulverwaltung;

final class GuardianCsvImport
{
    private const HEADERS = [
        'E-Mail-Adresse Erziehungsberechtigter' => 'email',
        'Vorname Erziehungsberechtigter' => 'vorname',
        'Nachname Erziehungsberechtigter' => 'nachname',
        'Mobil Erziehungsberechtigter' => 'mobil',
        'Straße und Hausnummer Erziehungsberechtigter' => 'strasse',
        'PLZ Erziehungsberechtigter' => 'plz',
        'Wohnort Erziehungsberechtigter' => 'ort',
        'Vorname Schülerin' => 'schuelerin_vorname',
        'Nachname Schülerin' => 'schuelerin_nachname',
        'Beziehung' => 'beziehung',
    ];

    private const RELATIONSHIPS = [
        'mutter' => 'mutter',
        'vater' => 'vater',
        'vormund' => 'vormund',
        'pflegeeltern' => 'pflegeeltern',
        'sonstiges' => 'sonstiges',
    ];

    public static function parse(string $contents): array
    {
        if (preg_match('//u', $contents) !== 1) {
            return ['headerErrors' => ['Die CSV-Datei muss als UTF-8 gespeichert sein.'], 'rows' => []];
        }

        $contents = preg_replace('/\A\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Der CSV-Inhalt konnte nicht gelesen werden.');
        }

        fwrite($stream, $contents);
        rewind($stream);
        $header = fgetcsv($stream, null, ';', '"', '');
        if ($header === false) {
            fclose($stream);
            return ['headerErrors' => ['Die CSV-Datei ist leer.'], 'rows' => []];
        }

        $headerIndexes = [];
        foreach ($header as $index => $column) {
            if (!is_string($column)) {
                continue;
            }
            $normalized = strtolower(trim($column));
            foreach (self::HEADERS as $label => $field) {
                if ($normalized === strtolower($label)) {
                    $headerIndexes[$field] = $index;
                    break;
                }
            }
        }

        $missingHeaders = [];
        foreach (self::HEADERS as $label => $field) {
            if (!array_key_exists($field, $headerIndexes)) {
                $missingHeaders[] = $label;
            }
        }
        if ($missingHeaders !== []) {
            fclose($stream);
            return [
                'headerErrors' => ['Fehlende Spalten: ' . implode(', ', $missingHeaders) . '.'],
                'rows' => [],
            ];
        }

        $rows = [];
        $line = 1;
        while (($columns = fgetcsv($stream, null, ';', '"', '')) !== false) {
            $line++;
            if ($columns === [null] || $columns === []) {
                continue;
            }
            if (count($rows) >= 500) {
                fclose($stream);
                return [
                    'headerErrors' => ['Die CSV-Datei darf höchstens 500 Datensätze enthalten.'],
                    'rows' => [],
                ];
            }

            $values = [];
            foreach ($headerIndexes as $field => $index) {
                $value = $columns[$index] ?? '';
                $values[$field] = is_string($value) ? trim($value) : '';
            }
            $errors = self::validate($values);
            $rows[] = [
                'line' => $line,
                'values' => $values,
                'errors' => $errors,
            ];
        }
        fclose($stream);

        if ($rows === []) {
            return ['headerErrors' => ['Die CSV-Datei enthält keine Datensätze.'], 'rows' => []];
        }

        return ['headerErrors' => [], 'rows' => $rows];
    }

    public static function addressesAreConsistent(array $rows): bool
    {
        $addressesByStudent = [];
        foreach ($rows as [$values, $studentId]) {
            $address = [$values['strasse'], $values['plz'], $values['ort']];
            if (isset($addressesByStudent[$studentId]) && $addressesByStudent[$studentId] !== $address) {
                return false;
            }
            $addressesByStudent[$studentId] = $address;
        }

        return true;
    }

    public static function studentGroupKey(array $values): string
    {
        return hash('sha256', json_encode(
            [$values['schuelerin_vorname'], $values['schuelerin_nachname']],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
    }

    private static function validate(array &$values): array
    {
        $errors = [];
        foreach (['vorname', 'nachname', 'schuelerin_vorname', 'schuelerin_nachname', 'beziehung'] as $required) {
            if ($values[$required] === '') {
                $errors[] = 'Pflichtangabe fehlt: ' . $required . '.';
            }
        }

        $limits = [
            'email' => 254,
            'vorname' => 100,
            'nachname' => 100,
            'mobil' => 50,
            'strasse' => 200,
            'plz' => 5,
            'ort' => 100,
            'schuelerin_vorname' => 100,
            'schuelerin_nachname' => 100,
        ];
        foreach ($limits as $field => $limit) {
            $length = preg_match_all('/./us', $values[$field]);
            if ($length === false || $length > $limit) {
                $errors[] = 'Ein Feld überschreitet die zulässige Länge: ' . $field . '.';
            }
        }

        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        }
        if ($values['plz'] !== '' && preg_match('/\A[0-9]{5}\z/', $values['plz']) !== 1) {
            $errors[] = 'Die PLZ muss leer sein oder genau fünf Ziffern enthalten.';
        }

        $relationship = strtolower($values['beziehung']);
        if (!array_key_exists($relationship, self::RELATIONSHIPS)) {
            $errors[] = 'Die Beziehung muss Mutter, Vater, Vormund, Pflegeeltern oder Sonstiges sein.';
        } else {
            $values['beziehung'] = self::RELATIONSHIPS[$relationship];
        }

        return $errors;
    }
}
