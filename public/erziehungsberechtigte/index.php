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
if (!is_string($searchValue)
    || (!is_string($pageInput) && !is_int($pageInput))
    || !is_string($maintenanceValue)) {
    http_response_code(400);
    exit('Ungültige Suchanfrage.');
}

$maintenance = null;
if ($maintenanceValue !== '') {
    $maintenance = Maintenance::definition('erziehungsberechtigte', $maintenanceValue);
    if ($maintenance === null) {
        http_response_code(400);
        exit('Ungültiger Wartungsfilter.');
    }
}
$maintenanceCondition = $maintenance === null ? '' : ' AND (' . $maintenance['condition'] . ')';

$searchValue = trim($searchValue);
if (preg_match('//u', $searchValue) !== 1) {
    http_response_code(400);
    exit('Die Suchanfrage enthält ungültige Zeichen.');
}

preg_match('/\A.{0,100}/us', $searchValue, $searchMatches);
$search = $searchMatches[0];
$page = filter_var($pageInput, FILTER_VALIDATE_INT);
if ($page === false || $page < 1) {
    http_response_code(400);
    exit('Ungültige Seitennummer.');
}

$likeSearch = '%' . $search . '%';
$countStatement = $database->prepare(
    'SELECT COUNT(*)
     FROM erziehungsberechtigte e
     JOIN schueler s ON s.id = e.schueler_id
     WHERE (:search = \'\'
        OR e.vorname LIKE :guardian_first_name
        OR e.nachname LIKE :guardian_last_name
        OR s.vorname LIKE :student_first_name
        OR s.nachname LIKE :student_last_name
        OR e.email LIKE :email)' . $maintenanceCondition
);
$countStatement->execute([
    'search' => $search,
    'guardian_first_name' => $likeSearch,
    'guardian_last_name' => $likeSearch,
    'student_first_name' => $likeSearch,
    'student_last_name' => $likeSearch,
    'email' => $likeSearch,
]);
$total = (int) $countStatement->fetchColumn();
$pageSize = 25;
$pageCount = max(1, (int) ceil($total / $pageSize));
if ($page > $pageCount) {
    http_response_code(404);
    exit('Diese Seite existiert nicht.');
}

$statement = $database->prepare(
    'SELECT e.id, e.schueler_id, e.vorname, e.nachname, e.beziehung,
            e.erziehungsberechtigt, e.telefon, e.mobil, e.email,
            s.vorname AS schueler_vorname, s.nachname AS schueler_nachname
     FROM erziehungsberechtigte e
     JOIN schueler s ON s.id = e.schueler_id
     WHERE (:search = \'\'
        OR e.vorname LIKE :guardian_first_name
        OR e.nachname LIKE :guardian_last_name
        OR s.vorname LIKE :student_first_name
        OR s.nachname LIKE :student_last_name
        OR e.email LIKE :email)' . $maintenanceCondition . '
     ORDER BY e.nachname, e.vorname, e.id
     LIMIT :page_size OFFSET :offset'
);
$statement->bindValue('search', $search);
$statement->bindValue('guardian_first_name', $likeSearch);
$statement->bindValue('guardian_last_name', $likeSearch);
$statement->bindValue('student_first_name', $likeSearch);
$statement->bindValue('student_last_name', $likeSearch);
$statement->bindValue('email', $likeSearch);
$statement->bindValue('page_size', $pageSize, PDO::PARAM_INT);
$statement->bindValue('offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$statement->execute();
$guardians = $statement->fetchAll();

$relationships = guardianRelationships();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Erziehungsberechtigte – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Erziehungsberechtigte und Kontaktpersonen</h1>
    <?= renderAccountMenu($user) ?>
    <?php if ($maintenance !== null): ?>
        <p>Wartung: <strong><?= escape($maintenance['label']) ?></strong>
            – nur Datensätze mit fehlender Angabe werden angezeigt.
            <a href="/wartung/">Zur Wartungsübersicht</a></p>
    <?php endif; ?>
    <p>Neue Kontaktpersonen werden weiterhin in der jeweiligen Schülerinnenakte angelegt.</p>

    <form method="get">
        <?php if ($maintenance !== null): ?>
            <input type="hidden" name="wartung" value="<?= escape($maintenanceValue) ?>">
        <?php endif; ?>
        <label for="q">Nach Name, zugehöriger Schülerin oder E-Mail suchen</label>
        <input id="q" name="q" type="search" maxlength="100" value="<?= escape($search) ?>">
        <button type="submit">Suchen</button>
        <?php if ($search !== ''): ?>
            <a href="<?= $maintenance === null ? '/erziehungsberechtigte/' : '/erziehungsberechtigte/?wartung=' . rawurlencode($maintenanceValue) ?>">Suche zurücksetzen</a>
        <?php endif; ?>
    </form>

    <p><?= $total ?> Kontaktpersonen</p>
    <?php if ($guardians === []): ?>
        <p>Keine Kontaktpersonen gefunden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Beziehung</th>
                <th scope="col">Erziehungsberechtigt</th>
                <th scope="col">Schülerin</th>
                <th scope="col">Telefon</th>
                <th scope="col">Mobil</th>
                <th scope="col">E-Mail</th>
                <th scope="col">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($guardians as $guardian): ?>
                <tr>
                    <td><?= escape($guardian['nachname'] . ', ' . $guardian['vorname']) ?></td>
                    <td><?= escape($relationships[$guardian['beziehung']] ?? 'Unbekannt') ?></td>
                    <td><?= (bool) $guardian['erziehungsberechtigt'] ? 'Ja' : 'Nein' ?></td>
                    <td>
                        <a href="/schueler/zeigen.php?id=<?= (int) $guardian['schueler_id'] ?>">
                            <?= escape($guardian['schueler_nachname'] . ', ' . $guardian['schueler_vorname']) ?>
                        </a>
                    </td>
                    <td><?= escape($guardian['telefon']) ?></td>
                    <td><?= escape($guardian['mobil']) ?></td>
                    <td><?= escape($guardian['email']) ?></td>
                    <td>
                        <span class="student-actions">
                            <?= renderEditLink(
                                '/schueler/erziehungsberechtigter.php?schueler_id=' . (int) $guardian['schueler_id'] . '&id=' . (int) $guardian['id'],
                                'Kontaktperson bearbeiten'
                            ) ?>
                            <?php if ($user['can_delete_students']): ?>
                                <?= renderDeleteLink(
                                    '/erziehungsberechtigte/loeschen.php?id=' . (int) $guardian['id'] . '&von=liste',
                                    'Kontaktperson löschen: ' . $guardian['vorname'] . ' ' . $guardian['nachname']
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
        <nav aria-label="Seitennavigation">
            <?php if ($page > 1): ?>
                <a href="?q=<?= rawurlencode($search) ?><?= $maintenance === null ? '' : '&amp;wartung=' . rawurlencode($maintenanceValue) ?>&amp;seite=<?= $page - 1 ?>">Zurück</a>
            <?php endif; ?>
            <span>Seite <?= $page ?> von <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?>
                <a href="?q=<?= rawurlencode($search) ?><?= $maintenance === null ? '' : '&amp;wartung=' . rawurlencode($maintenanceValue) ?>&amp;seite=<?= $page + 1 ?>">Weiter</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
