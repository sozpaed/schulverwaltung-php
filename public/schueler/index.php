<?php

declare(strict_types=1);

use Schulverwaltung\Maintenance;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Maintenance.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$searchValue = $_GET['q'] ?? '';
$pageInput = $_GET['seite'] ?? 1;
$maintenanceValue = $_GET['wartung'] ?? '';
$statusValue = $_GET['status'] ?? '';
$classValue = $_GET['klasse'] ?? '';
$schoolValue = $_GET['grundschule'] ?? '';

if (!is_string($searchValue)
    || (!is_string($pageInput) && !is_int($pageInput))
    || !is_string($maintenanceValue)
    || !is_string($statusValue)
    || !is_string($classValue)
    || !is_string($schoolValue)) {
    http_response_code(400);
    exit('Ungültige Suchanfrage.');
}

$maintenance = null;
if ($maintenanceValue !== '') {
    $maintenance = Maintenance::definition('schueler', $maintenanceValue);
    if ($maintenance === null) {
        http_response_code(400);
        exit('Ungültiger Wartungsfilter.');
    }
}
$maintenanceCondition = $maintenance === null ? '' : ' AND (' . $maintenance['condition'] . ')';

$statusLabels = [
    'abgelehnt' => 'Abgelehnt',
    'angenommen' => 'Angenommen',
    'in_pruefung' => 'In Prüfung',
    'verzicht' => 'Verzicht',
];
$classes = [
    '5a' => '5a',
    '5b' => '5b',
    '5c' => '5c',
    '5d' => '5d',
    '5e' => '5e',
];
if (($statusValue !== '' && !array_key_exists($statusValue, $statusLabels))
    || ($classValue !== '' && $classValue !== '__leer' && !array_key_exists($classValue, $classes))) {
    http_response_code(400);
    exit('Ungültiger Status- oder Klassenfilter.');
}

$searchValue = trim($searchValue);

if (preg_match('//u', $searchValue) !== 1) {
    http_response_code(400);
    exit('Die Suchanfrage enthält ungültige Zeichen.');
}

preg_match('/\A.{0,100}/us', $searchValue, $searchMatches);
$search = $searchMatches[0];
$schools = $database->query('SELECT id, name, ort FROM grundschulen ORDER BY name, ort, id')->fetchAll();
$schoolIds = array_map(static fn (array $school): string => (string) $school['id'], $schools);
if ($schoolValue !== '' && $schoolValue !== '__leer' && !in_array($schoolValue, $schoolIds, true)) {
    http_response_code(400);
    exit('Ungültiger Grundschulfilter.');
}

$pageValue = filter_var($pageInput, FILTER_VALIDATE_INT);

if ($pageValue === false || $pageValue < 1) {
    http_response_code(400);
    exit('Ungültige Seitennummer.');
}

$pageSize = 25;
$conditions = [
    '(:search = \'\' OR s.vorname LIKE :first_name OR s.nachname LIKE :last_name OR s.ort LIKE :search_city)',
];
$queryParameters = [
    'search' => $search,
    'first_name' => '%' . $search . '%',
    'last_name' => '%' . $search . '%',
    'search_city' => '%' . $search . '%',
];
if ($statusValue !== '') {
    $conditions[] = 's.status = :status';
    $queryParameters['status'] = $statusValue;
}
if ($classValue !== '') {
    $conditions[] = 's.klasse = :class';
    $queryParameters['class'] = $classValue === '__leer' ? '' : $classValue;
}
if ($schoolValue === '__leer') {
    $conditions[] = 's.grundschule_id IS NULL';
} elseif ($schoolValue !== '') {
    $conditions[] = 's.grundschule_id = :school_id';
    $queryParameters['school_id'] = $schoolValue;
}
$whereClause = implode(' AND ', $conditions) . $maintenanceCondition;

$countStatement = $database->prepare(
    'SELECT COUNT(*)
     FROM schueler s
     WHERE ' . $whereClause
);
$countStatement->execute($queryParameters);
$total = (int) $countStatement->fetchColumn();
$pageCount = max(1, (int) ceil($total / $pageSize));

if ($pageValue > $pageCount) {
    http_response_code(404);
    exit('Diese Seite existiert nicht.');
}

$offset = ($pageValue - 1) * $pageSize;
$statement = $database->prepare(
    'SELECT s.id, s.vorname, s.nachname, s.ort, s.klasse, s.status,
            g.name AS grundschule
     FROM schueler s
     LEFT JOIN grundschulen g ON g.id = s.grundschule_id
    WHERE ' . $whereClause . '
    ORDER BY s.nachname, s.vorname, s.id
    LIMIT :page_size OFFSET :offset'
);
foreach ($queryParameters as $name => $value) {
    $statement->bindValue($name, $value);
}
$statement->bindValue('page_size', $pageSize, PDO::PARAM_INT);
$statement->bindValue('offset', $offset, PDO::PARAM_INT);
$statement->execute();
$students = $statement->fetchAll();

$activeFilters = $search !== '' || $statusValue !== '' || $classValue !== '' || $schoolValue !== '';
$resetUrl = $maintenance === null
    ? '/schueler/'
    : '/schueler/?wartung=' . rawurlencode($maintenanceValue);
$paginationParameters = array_filter([
    'q' => $search,
    'status' => $statusValue,
    'klasse' => $classValue,
    'grundschule' => $schoolValue,
    'wartung' => $maintenanceValue,
], static fn (string $value): bool => $value !== '');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Schülerinnen – Schulverwaltung</title>
</head>
<body>
<main>
    <?= renderSchoolHeader($database) ?>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Schülerinnen</h1>
    <?= renderAccountMenu($user) ?>
    <?php if ($maintenance !== null): ?>
        <p>Wartung: <strong><?= escape($maintenance['label']) ?></strong>
            – nur Datensätze mit fehlender Angabe werden angezeigt.
            <a href="/wartung/">Zur Wartungsübersicht</a></p>
    <?php endif; ?>
    <p><a href="/schueler/neu.php">Schülerin anlegen</a></p>

    <form method="get">
        <?php if ($maintenance !== null): ?>
            <input type="hidden" name="wartung" value="<?= escape($maintenanceValue) ?>">
        <?php endif; ?>
        <div class="form-grid form-grid--four">
            <p><label for="q">Name oder Wohnort suchen</label>
                <input id="q" name="q" type="search" maxlength="100" value="<?= escape($search) ?>"></p>
            <p><label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Alle Status</option>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= escape($value) ?>"<?= $statusValue === $value ? ' selected' : '' ?>>
                            <?= escape($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select></p>
            <p><label for="klasse">Klasse</label>
                <select id="klasse" name="klasse">
                    <option value="">Alle Klassen</option>
                    <?php foreach ($classes as $value => $label): ?>
                        <option value="<?= escape($value) ?>"<?= $classValue === $value ? ' selected' : '' ?>>
                            <?= escape($label) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__leer"<?= $classValue === '__leer' ? ' selected' : '' ?>>Nicht zugeordnet</option>
                </select></p>
            <p><label for="grundschule">Grundschule</label>
                <select id="grundschule" name="grundschule">
                    <option value="">Alle Grundschulen</option>
                    <?php foreach ($schools as $school): ?>
                        <?php $schoolLabel = $school['ort'] === '' ? $school['name'] : $school['name'] . ' (' . $school['ort'] . ')'; ?>
                        <option value="<?= (int) $school['id'] ?>"<?= $schoolValue === (string) $school['id'] ? ' selected' : '' ?>>
                            <?= escape($schoolLabel) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__leer"<?= $schoolValue === '__leer' ? ' selected' : '' ?>>Nicht zugeordnet</option>
                </select></p>
        </div>
        <button type="submit">Filtern</button>
        <?php if ($activeFilters): ?>
            <a href="<?= escape($resetUrl) ?>">Filter zurücksetzen</a>
        <?php endif; ?>
    </form>

    <p><?= $total ?> Datensätze</p>
    <?php if ($students === []): ?>
        <p>Keine Schülerinnen gefunden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Nachname</th>
                <th scope="col">Vorname</th>
                <th scope="col">Klasse</th>
                <th scope="col">Status</th>
                <th scope="col">Grundschule</th>
                <th scope="col">Wohnort</th>
                <th scope="col">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td><?= escape($student['nachname']) ?></td>
                    <td><?= escape($student['vorname']) ?></td>
                    <td><?= escape($student['klasse']) ?></td>
                    <td><?= escape($statusLabels[$student['status']] ?? 'Unbekannt') ?></td>
                    <td><?= escape($student['grundschule'] ?? '') ?></td>
                    <td><?= escape($student['ort']) ?></td>
                    <td>
                        <span class="student-actions">
                            <?= renderEditLink('/schueler/bearbeiten.php?id=' . (int) $student['id'], 'Schülerinnendaten bearbeiten') ?>
                            <?= renderStudentDetailLink((int) $student['id'], $student['vorname'] . ' ' . $student['nachname']) ?>
                            <?php if ($user['can_delete_students']): ?>
                                <?= renderDeleteLink(
                                    '/schueler/loeschen.php?id=' . (int) $student['id'],
                                    'Schülerin löschen: ' . $student['vorname'] . ' ' . $student['nachname']
                                ) ?>
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($pageCount > 1): ?>
        <?php $paginationParameters['seite'] = (string) $pageValue; ?>
        <nav aria-label="Seitennavigation">
            <?php if ($pageValue > 1): ?>
                <?php $paginationParameters['seite'] = (string) ($pageValue - 1); ?>
                <a href="?<?= escape(http_build_query($paginationParameters)) ?>">Zurück</a>
            <?php endif; ?>
            <span>Seite <?= $pageValue ?> von <?= $pageCount ?></span>
            <?php if ($pageValue < $pageCount): ?>
                <?php $paginationParameters['seite'] = (string) ($pageValue + 1); ?>
                <a href="?<?= escape(http_build_query($paginationParameters)) ?>">Weiter</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

</main>
</body>
</html>
