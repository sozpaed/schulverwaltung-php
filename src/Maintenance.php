<?php

declare(strict_types=1);

namespace Schulverwaltung;

final class Maintenance
{
    public static function studentDefinitions(): array
    {
        return [
            'adresse' => [
                'label' => 'Adresse',
                'condition' => "(TRIM(s.strasse) = '' OR s.plz IS NULL)",
            ],
            'wohnort' => [
                'label' => 'Wohnort',
                'condition' => "TRIM(s.ort) = ''",
            ],
            'geburtsdatum' => [
                'label' => 'Geburtsdatum',
                'condition' => 's.geburtsdatum IS NULL',
            ],
            'geburtsort' => [
                'label' => 'Geburtsort',
                'condition' => "TRIM(s.geburtsort) = ''",
            ],
            'religion' => [
                'label' => 'Religion',
                'condition' => 's.religion_id IS NULL',
            ],
            'grundschule' => [
                'label' => 'Grundschule',
                'condition' => 's.grundschule_id IS NULL',
            ],
            'staatsangehoerigkeit' => [
                'label' => 'Staatsangehörigkeit',
                'condition' => 's.staatsangehoerigkeit_id IS NULL',
            ],
            'noten' => [
                'label' => 'Noten',
                'condition' => '(s.deutsch IS NULL OR s.mathematik IS NULL OR s.sachkunde IS NULL OR s.av IS NULL OR s.sv IS NULL)',
            ],
            'schulform' => [
                'label' => 'Schulform',
                'condition' => "s.schulform = ''",
            ],
            'klasse' => [
                'label' => 'Klasse',
                'condition' => "s.klasse = ''",
            ],
        ];
    }

    public static function guardianDefinitions(): array
    {
        return [
            'adresse' => [
                'label' => 'Adresse',
                'condition' => "((e.adresse_wie_schuelerin = 1 AND (TRIM(s.strasse) = '' OR s.plz IS NULL))
                    OR (e.adresse_wie_schuelerin = 0 AND (TRIM(e.strasse) = '' OR e.plz IS NULL)))",
            ],
            'wohnort' => [
                'label' => 'Wohnort',
                'condition' => "((e.adresse_wie_schuelerin = 1 AND TRIM(s.ort) = '')
                    OR (e.adresse_wie_schuelerin = 0 AND TRIM(e.ort) = ''))",
            ],
            'erziehungsberechtigt' => [
                'label' => 'Erziehungsberechtigt',
                'condition' => 'e.erziehungsberechtigt = 0',
            ],
            'religion' => [
                'label' => 'Religion',
                'condition' => '((e.religion_wie_schuelerin = 1 AND s.religion_id IS NULL)
                    OR (e.religion_wie_schuelerin = 0 AND e.religion_id IS NULL))',
            ],
            'staatsangehoerigkeit' => [
                'label' => 'Staatsangehörigkeit',
                'condition' => '((e.staatsangehoerigkeit_wie_schuelerin = 1 AND s.staatsangehoerigkeit_id IS NULL)
                    OR (e.staatsangehoerigkeit_wie_schuelerin = 0 AND e.staatsangehoerigkeit_id IS NULL))',
            ],
            'email' => [
                'label' => 'E-Mail',
                'condition' => "TRIM(e.email) = ''",
            ],
            'telefon' => [
                'label' => 'Mobil und Telefon',
                'condition' => "(TRIM(e.telefon) = '' AND TRIM(e.mobil) = '')",
            ],
            'klasse' => [
                'label' => 'Klasse',
                'condition' => "TRIM(s.klasse) = ''",
            ],
        ];
    }

    public static function definition(string $type, string $key): ?array
    {
        $definitions = match ($type) {
            'schueler' => self::studentDefinitions(),
            'erziehungsberechtigte' => self::guardianDefinitions(),
            default => [],
        };

        return $definitions[$key] ?? null;
    }
}
