<?php

declare(strict_types=1);

use Schulverwaltung\StudentNavigation;

require_once __DIR__ . '/../src/Maintenance.php';
require_once __DIR__ . '/../src/StudentNavigation.php';
require_once __DIR__ . '/../src/helpers.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Für diesen Test wird die PDO-SQLite-Erweiterung benötigt.\n");
    exit(1);
}

function expectNavigation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function neighborId(array $neighbors, string $position): ?int
{
    return $neighbors[$position] === null ? null : (int) $neighbors[$position]['id'];
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE schueler (
    id INTEGER PRIMARY KEY, vorname TEXT NOT NULL, nachname TEXT NOT NULL,
    ort TEXT NOT NULL, status TEXT NOT NULL, klasse TEXT NOT NULL,
    grundschule_id INTEGER, geburtsdatum TEXT
)');
$db->exec('CREATE TABLE erziehungsberechtigte (
    id INTEGER PRIMARY KEY, schueler_id INTEGER NOT NULL, vorname TEXT NOT NULL, nachname TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT \'\'
)');
$insertStudent = $db->prepare('INSERT INTO schueler VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
for ($id = 1; $id <= 30; $id++) {
    $insertStudent->execute([$id, 'Anna', sprintf('Name%02d', $id), 'Bonn', 'in_pruefung', '5a', 1, '2015-01-01']);
}
$insertStudent->execute([31, 'Anna', 'Name25', 'Köln', 'angenommen', '', null, null]);
$insertStudent->execute([32, 'Anna', 'Name25', 'Bonn', 'in_pruefung', '5a', 1, null]);
$insertGuardian = $db->prepare('INSERT INTO erziehungsberechtigte (id, schueler_id, vorname, nachname) VALUES (?, ?, ?, ?)');
foreach ([[1, 25, 'Alex', 'Z'], [2, 25, 'Alex', 'Z'], [3, 32, 'Berta', 'A'], [4, 26, 'Clara', 'A'], [5, 31, 'Dora', 'A']] as $guardian) {
    $insertGuardian->execute($guardian);
}

$filter = ['q' => 'Bonn', 'status' => 'in_pruefung', 'klasse' => '5a', 'grundschule' => '1', 'wartung' => ''];
$neighbors = StudentNavigation::neighbors($db, $filter, 25);
expectNavigation(neighborId($neighbors, 'previous') === 24 && neighborId($neighbors, 'next') === 32, 'Schülerinnenreihenfolge oder Seitengrenze falsch.');
$neighbors = StudentNavigation::neighbors($db, $filter, 32);
expectNavigation(neighborId($neighbors, 'previous') === 25 && neighborId($neighbors, 'next') === 26, 'Gleiche Namen werden nicht nach ID sortiert.');
expectNavigation(StudentNavigation::neighbors($db, $filter, 31) === ['previous' => null, 'next' => null], 'Ein ausgeschlossener Datensatz hat Nachbarn.');
expectNavigation(neighborId(StudentNavigation::neighbors($db, $filter, 1), 'previous') === null, 'Erster Datensatz hat einen Vorgänger.');
expectNavigation(neighborId(StudentNavigation::neighbors($db, $filter, 30), 'next') === null, 'Letzter Datensatz hat einen Nachfolger.');

$empty = ['klasse' => '__leer', 'grundschule' => '__leer', 'wartung' => 'geburtsdatum'];
expectNavigation(StudentNavigation::neighbors($db, $empty, 31) === ['previous' => null, 'next' => null], 'Kombination aus leeren Feldern und Wartungsfilter falsch.');
expectNavigation(neighborId(StudentNavigation::neighbors($db, ['wartung' => 'geburtsdatum'], 31), 'next') === 32, 'Wartungsfilter falsch angewendet.');

$neighbors = StudentNavigation::neighbors($db, $filter, 2, true);
expectNavigation(neighborId($neighbors, 'previous') === 1 && neighborId($neighbors, 'next') === 3, 'Globale Kontaktpersonenreihenfolge falsch.');
expectNavigation((int) $neighbors['next']['schueler_id'] === 32, 'Zugehörige Schülerin des Nachfolgers fehlt.');
$neighbors = StudentNavigation::neighbors($db, $filter, 2, true, 25);
expectNavigation(neighborId($neighbors, 'previous') === 1 && neighborId($neighbors, 'next') === null, 'Navigation aus der Schülerinnenakte überschreitet die Schülerin.');
expectNavigation(StudentNavigation::neighbors($db, $filter, 5, true) === ['previous' => null, 'next' => null], 'Kontaktperson einer ausgeschlossenen Schülerin hat Nachbarn.');
$neighbors = StudentNavigation::neighbors($db, ['q' => 'Name25'], 1, true, null, true);
expectNavigation(neighborId($neighbors, 'previous') === 5 && neighborId($neighbors, 'next') === 2, 'Navigation der Kontaktpersonenliste falsch.');
expectNavigation(neighborId(StudentNavigation::neighbors($db, ['q' => 'Dora'], 5, true, null, true), 'previous') === null, 'Suchfilter der Kontaktpersonenliste falsch.');

$html = renderRecordNavigation(
    ['vorname' => '<Anna>', 'nachname' => '&'],
    ['previous' => null, 'next' => ['id' => 3, 'schueler_id' => 32, 'vorname' => '<Berta>', 'nachname' => '"A"']],
    '/schueler/erziehungsberechtigter.php',
    true,
    true
);
expectNavigation(str_contains($html, '&lt;Anna&gt; &amp;') && !str_contains($html, '<Berta>'), 'Namen werden nicht korrekt maskiert.');
expectNavigation(str_contains($html, 'schueler_id=32&amp;id=3&amp;von=liste'), 'Navigationskontext oder URL-Maskierung fehlt.');
expectNavigation(studentOverviewUrl(['q' => 'A & B', 'klasse' => '']) === '/schueler/?q=A+%26+B', 'Übersichtsfilter wurden nicht erhalten.');

try {
    StudentNavigation::studentFilter(['wartung' => 'unbekannt']);
    expectNavigation(false, 'Ungültiger Wartungsfilter wurde akzeptiert.');
} catch (InvalidArgumentException $exception) {
    expectNavigation($exception->getMessage() !== '', 'Fehlermeldung zum Wartungsfilter fehlt.');
}

fwrite(STDOUT, "Datensatznavigation erfolgreich geprüft.\n");
