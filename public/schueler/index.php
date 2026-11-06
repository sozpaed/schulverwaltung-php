<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$searchValue = $_GET['q'] ?? '';
$pageInput = $_GET['seite'] ?? 1;

if (!is_string($searchValue) || !is_string($pageInput) && !is_int($pageInput)) {
    http_response_code(400);
    exit('Ungültige Suchanfrage.');
}

$searchValue = trim($searchValue);

if (preg_match('//u', $searchValue) !== 1) {
    http_response_code(400);
    exit('Die Suchanfrage enthält ungültige Zeichen.');
}

preg_match('/\A.{0,100}/us', $searchValue, $searchMatches);
$search = $searchMatches[0];
$pageValue = filter_var($pageInput, FILTER_VALIDATE_INT);

if ($pageValue === false || $pageValue < 1) {
    http_response_code(400);
    exit('Ungültige Seitennummer.');
}

$pageSize = 25;
$countStatement = $database->prepare(
    'SELECT COUNT(*)
     FROM schueler s
     WHERE (:search = \'\'
        OR s.vorname LIKE :first_name
        OR s.nachname LIKE :last_name
        OR s.ort LIKE :city)'
);
$likeSearch = '%' . $search . '%';
$countStatement->execute([
    'search' => $search,
    'first_name' => $likeSearch,
    'last_name' => $likeSearch,
    'city' => $likeSearch,
]);
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
     WHERE (:search = \'\'
        OR s.vorname LIKE :first_name
        OR s.nachname LIKE :last_name
        OR s.ort LIKE :city)
     ORDER BY s.nachname, s.vorname, s.id
     LIMIT :page_size OFFSET :offset'
);
$statement->bindValue('search', $search);
$statement->bindValue('first_name', $likeSearch);
$statement->bindValue('last_name', $likeSearch);
$statement->bindValue('city', $likeSearch);
$statement->bindValue('page_size', $pageSize, PDO::PARAM_INT);
$statement->bindValue('offset', $offset, PDO::PARAM_INT);
$statement->execute();
$students = $statement->fetchAll();

$statusLabels = [
    'abgelehnt' => 'Abgelehnt',
    'angenommen' => 'Angenommen',
    'in_pruefung' => 'In Prüfung',
    'verzicht' => 'Verzicht',
];
$csrfToken = Auth::csrfToken();
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
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Schülerinnen</h1>
    <?= renderAccountMenu($user) ?>
    <p><a href="/schueler/neu.php">Schülerin anlegen</a></p>

    <form method="get">
        <label for="q">Name oder Wohnort suchen</label>
        <input id="q" name="q" type="search" maxlength="100" value="<?= escape($search) ?>">
        <button type="submit">Suchen</button>
        <?php if ($search !== ''): ?>
            <a href="/schueler/">Suche zurücksetzen</a>
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
                                <?php $deleteLabel = 'Schülerin löschen: ' . $student['vorname'] . ' ' . $student['nachname']; ?>
                                <a class="delete-link" href="/schueler/loeschen.php?id=<?= (int) $student['id'] ?>"
                                   aria-label="<?= escape($deleteLabel) ?>" title="<?= escape($deleteLabel) ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-3 6h12l-1 11H7L6 9Zm4 2v7h2v-7h-2Zm3 0v7h2v-7h-2Z"></path>
                                    </svg>
                                </a>
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
            <?php if ($pageValue > 1): ?>
                <a href="?q=<?= rawurlencode($search) ?>&amp;seite=<?= $pageValue - 1 ?>">Zurück</a>
            <?php endif; ?>
            <span>Seite <?= $pageValue ?> von <?= $pageCount ?></span>
            <?php if ($pageValue < $pageCount): ?>
                <a href="?q=<?= rawurlencode($search) ?>&amp;seite=<?= $pageValue + 1 ?>">Weiter</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <form method="post" action="/">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
