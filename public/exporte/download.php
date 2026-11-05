<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_export']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Daten zu exportieren.');
}

$area = $_GET['bereich'] ?? null;
if (!is_string($area)) {
    http_response_code(400);
    exit('Ungültiger Exportbereich.');
}

$exports = [
    'schueler' => [
        'filename' => 'schueler.csv',
        'headers' => [
            'ID', 'Vorname', 'Nachname', 'Geburtsdatum', 'Straße', 'PLZ', 'Wohnort',
            'Geburtsort', 'Religion', 'Staatsangehörigkeit', 'Grundschule',
            'Anzahl Geschwister', 'Geschwister an der Marienschule', 'Freundin/Freund',
            'Deutsch', 'Mathematik', 'Sachkunde', 'AV', 'SV', 'Status', 'Schulform',
            'Klasse', 'Warteliste', 'Erstellt am', 'Aktualisiert am',
            'Erziehungsberechtigte',
        ],
        'query' => 'SELECT s.id, s.vorname, s.nachname, s.geburtsdatum, s.strasse,
                           s.plz, s.ort, s.geburtsort, r.name, sa.name, g.name,
                           s.anzahl_geschwister, s.davon_an_der_marienschule, s.freundin,
                           s.deutsch, s.mathematik, s.sachkunde, s.av, s.sv, s.status,
                           s.schulform, s.klasse, s.warteliste, s.erstellt_am, s.aktualisiert_am,
                           (SELECT GROUP_CONCAT(
                                       CONCAT(e.vorname, \' \', e.nachname)
                                       ORDER BY e.nachname, e.vorname, e.id
                                       SEPARATOR \', \')
                            FROM erziehungsberechtigte e
                            WHERE e.schueler_id = s.id)
                    FROM schueler s
                    LEFT JOIN religionen r ON r.id = s.religion_id
                    JOIN staatsangehoerigkeiten sa ON sa.id = s.staatsangehoerigkeit_id
                    LEFT JOIN grundschulen g ON g.id = s.grundschule_id
                    ORDER BY s.id',
    ],
    'erziehungsberechtigte' => [
        'filename' => 'erziehungsberechtigte.csv',
        'headers' => [
            'ID', 'Schüler-ID', 'Vorname', 'Nachname', 'Geburtsdatum', 'Religion',
            'Staatsangehörigkeit', 'Religion wie Schülerin/Schüler',
            'Staatsangehörigkeit wie Schülerin/Schüler', 'Beziehung',
            'Erziehungsberechtigt', 'Straße', 'PLZ', 'Wohnort',
            'Adresse wie Schülerin/Schüler', 'Telefon', 'Mobil', 'E-Mail',
            'Erstellt am', 'Aktualisiert am', 'Vorname Schülerin/Schüler',
            'Nachname Schülerin/Schüler', 'Name Schülerin/Schüler',
        ],
        'query' => 'SELECT e.id, e.schueler_id, e.vorname, e.nachname, e.geburtsdatum,
                           r.name, sa.name, e.religion_wie_schuelerin,
                           e.staatsangehoerigkeit_wie_schuelerin, e.beziehung,
                           e.erziehungsberechtigt, e.strasse, e.plz, e.ort,
                           e.adresse_wie_schuelerin, e.telefon, e.mobil, e.email,
                           e.erstellt_am, e.aktualisiert_am, s.vorname, s.nachname,
                           CONCAT(s.vorname, \' \', s.nachname)
                    FROM erziehungsberechtigte e
                    JOIN schueler s ON s.id = e.schueler_id
                    LEFT JOIN religionen r ON r.id = e.religion_id
                    LEFT JOIN staatsangehoerigkeiten sa ON sa.id = e.staatsangehoerigkeit_id
                    ORDER BY e.id',
    ],
    'gespraeche' => [
        'filename' => 'gespraeche.csv',
        'headers' => [
            'ID', 'Schüler-ID', 'Protokollant-ID', 'Datum', 'Gewünschte Schulform',
            'Offenheit christlicher Religionsunterricht', 'Anwesend außer Tochter',
            'Erstkontakt Tag der offenen Tür', 'Erstkontakt Forscherinnennachmittag',
            'Erstkontakt Workshop Jakoby', 'Hobbys, Lieblingsfächer und AGs',
            'Warum diese Schule?', 'Kirchliche Sozialisation', 'Sonstiges',
            'Förderbedarf LRS', 'Förderbedarf Dyskalkulie', 'Förderbedarf THA',
            'Elternengagement', 'Aufnahmeempfehlung', 'Erstellt am',
            'Aktualisiert am', 'Schülerin/Schüler Vorname', 'Schülerin/Schüler Nachname',
            'Name Schülerin/Schüler', 'Protokollant', 'Marketingkanäle',
            'Erziehungsberechtigte',
        ],
        'query' => 'SELECT g.id, g.schueler_id, g.protokollant_id, g.datum,
                           g.gewuenschte_schulform, g.offenheit_christlicher_ru,
                           g.anwesend_ausser_tochter, g.erstkontakt_tdot,
                           g.erstkontakt_forscherinnennachmittag,
                           g.erstkontakt_workshop_jakoby,
                           g.hobbys_lieblingsfaecher_ags, g.warum_diese_schule,
                           g.kirchliche_sozialisation, g.sonstiges,
                           g.foerderbedarf_lrs, g.foerderbedarf_dyskalkulie,
                           g.foerderbedarf_tha, g.elternengagement,
                           g.aufnahmeempfehlung, g.erstellt_am, g.aktualisiert_am,
                           s.vorname, s.nachname, CONCAT(s.vorname, \' \', s.nachname),
                           u.username,
                           (SELECT GROUP_CONCAT(k.name ORDER BY k.name SEPARATOR \', \')
                            FROM gespraech_marketing_kanaele gm
                            JOIN marketing_kanaele k ON k.id = gm.marketing_kanal_id
                            WHERE gm.gespraech_id = g.id),
                           (SELECT GROUP_CONCAT(
                                       CONCAT(e.vorname, \' \', e.nachname)
                                       ORDER BY e.nachname, e.vorname, e.id
                                       SEPARATOR \', \')
                            FROM erziehungsberechtigte e
                            WHERE e.schueler_id = s.id)
                    FROM gespraeche g
                    JOIN schueler s ON s.id = g.schueler_id
                    LEFT JOIN app_users u ON u.id = g.protokollant_id
                    ORDER BY g.id',
    ],
];

if (!array_key_exists($area, $exports)) {
    http_response_code(400);
    exit('Ungültiger Exportbereich.');
}

$export = $exports[$area];
$rows = $database->query($export['query'])->fetchAll(PDO::FETCH_NUM);

$logStatement = $database->prepare(
    'INSERT INTO export_protokolle (benutzer_id, bereich, datensaetze, format)
     VALUES (:benutzer_id, :bereich, :datensaetze, :format)'
);
$logStatement->execute([
    'benutzer_id' => $user['id'],
    'bereich' => $area,
    'datensaetze' => count($rows),
    'format' => 'csv',
]);

$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Die CSV-Ausgabe konnte nicht geöffnet werden.');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

echo "\xEF\xBB\xBF";
if (fputcsv($output, $export['headers'], ';', '"', '', "\r\n") === false) {
    throw new RuntimeException('Die CSV-Kopfzeile konnte nicht geschrieben werden.');
}

foreach ($rows as $row) {
    $safeRow = array_map(
        static function (mixed $value): string {
            $cell = $value === null ? '' : (string) $value;
            if (preg_match('/\A[\p{Z}\x00-\x20]*[=+\-@]/u', $cell) === 1) {
                return "'" . $cell;
            }

            return $cell;
        },
        $row
    );

    if (fputcsv($output, $safeRow, ';', '"', '', "\r\n") === false) {
        throw new RuntimeException('Eine CSV-Datenzeile konnte nicht geschrieben werden.');
    }
}

fclose($output);
