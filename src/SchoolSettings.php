<?php

declare(strict_types=1);

namespace Schulverwaltung;

use PDO;

final class SchoolSettings
{
    public const FIELDS = [
        'school_name' => ['Name der Schule', 150],
        'head_name' => ['Name der Schulleitung', 150],
        'street' => ['Straße und Hausnummer', 200],
        'postal_code' => ['Postleitzahl', 20],
        'city' => ['Ort', 100],
        'phone' => ['Telefon', 50],
        'email' => ['E-Mail', 254],
        'website' => ['Website', 200],
    ];

    public static function get(PDO $database): array
    {
        $settings = $database->query('SELECT * FROM schuleinstellungen WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($settings !== false) {
            return $settings;
        }

        return array_fill_keys(array_keys(self::FIELDS), '') + ['logo_data' => null, 'logo_type' => null];
    }

    public static function schoolName(PDO $database): string
    {
        $name = $database->query('SELECT school_name FROM schuleinstellungen WHERE id = 1')->fetchColumn();
        return $name === false ? '' : $name;
    }

    public static function validate(array $input): array
    {
        $errors = [];
        foreach (self::FIELDS as $field => [$label, $maximum]) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                $errors[] = $label . ': ungültiger Wert.';
                continue;
            }
            $length = preg_match_all('/./us', $input[$field]);
            if ($length === false || $length > $maximum || preg_match('/[\x00-\x1f\x7f]/', $input[$field]) === 1) {
                $errors[] = $label . ': ungültiger oder zu langer Wert.';
            }
        }
        if (isset($input['email']) && is_string($input['email'])
            && $input['email'] !== '' && filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        return $errors;
    }

    public static function save(PDO $database, array $settings): void
    {
        $statement = $database->prepare(
            'INSERT INTO schuleinstellungen
                (id, school_name, head_name, street, postal_code, city, phone, email, website, logo_data, logo_type)
             VALUES
                (1, :school_name, :head_name, :street, :postal_code, :city, :phone, :email, :website, :logo_data, :logo_type)
             ON DUPLICATE KEY UPDATE school_name = VALUES(school_name), head_name = VALUES(head_name),
                street = VALUES(street), postal_code = VALUES(postal_code), city = VALUES(city),
                phone = VALUES(phone), email = VALUES(email), website = VALUES(website),
                logo_data = VALUES(logo_data), logo_type = VALUES(logo_type)'
        );
        $statement->execute($settings);
    }
}
