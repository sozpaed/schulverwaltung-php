<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$searchValue = $_GET['q'] ?? '';
$pageInput = $_GET['seite'] ?? 1;
if (!is_string($searchValue) || (!is_string($pageInput) && !is_int($pageInput))) {
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
$page = filter_var($pageInput, FILTER_VALIDATE_INT);
if ($page === false || $page < 1) {
    http_response_code(400);
    exit('Ungültige Seitennummer.');
}

$likeSearch = '%' . $search . '%';
$countStatement = $database->prepare(
    'SELECT COUNT(*)
     FROM gespraeche g
     JOIN schueler s ON s.id = g.schueler_id
     WHERE (:search = \'\'
        OR s.vorname LIKE :first_name
        OR s.nachname LIKE :last_name
        OR g.gewuenschte_schulform LIKE :school_form)'
);
$countStatement->execute([
    'search' => $search,
    'first_name' => $likeSearch,
    'last_name' => $likeSearch,
    'school_form' => $likeSearch,
]);
$total = (int) $countStatement->fetchColumn();
$pageSize = 25;
$pageCount = max(1, (int) ceil($total / $pageSize));
if ($page > $pageCount) {
    http_response_code(404);
    exit('Diese Seite existiert nicht.');
}

$statement = $database->prepare(
    'SELECT g.id, g.schueler_id, g.datum, g.gewuenschte_schulform,
            g.aufnahmeempfehlung, s.vorname, s.nachname, u.username
     FROM gespraeche g
     JOIN schueler s ON s.id = g.schueler_id
     LEFT JOIN app_users u ON u.id = g.protokollant_id
     WHERE (:search = \'\'
        OR s.vorname LIKE :first_name
        OR s.nachname LIKE :last_name
        OR g.gewuenschte_schulform LIKE :school_form)
     ORDER BY g.datum DESC, g.id DESC
     LIMIT :page_size OFFSET :offset'
);
$statement->bindValue('search', $search);
$statement->bindValue('first_name', $likeSearch);
$statement->bindValue('last_name', $likeSearch);
$statement->bindValue('school_form', $likeSearch);
$statement->bindValue('page_size', $pageSize, PDO::PARAM_INT);
$statement->bindValue('offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$statement->execute();
$conversations = $statement->fetchAll();

$schoolForms = [
    'gymnasium' => 'Gymnasium',
    'foerderstufe_realschule' => 'Förderstufe/Realschule',
    'unklar' => 'Noch unklar',
];
$recommendations = [
    '' => 'Offen',
    'ja' => 'Ja',
    'vielleicht' => 'Vielleicht',
    'nein' => 'Nein',
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Gespräche – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Aufnahmegespräche</h1>
    <?= renderAccountMenu($user) ?>
    <p>Ein neues Gespräch wird in der zugehörigen Schülerinnenakte angelegt.</p>

    <form method="get">
        <label for="q">Nach Vorname, Nachname oder gewünschter Schulform suchen</label>
        <input id="q" name="q" type="search" maxlength="100" value="<?= escape($search) ?>">
        <button type="submit">Suchen</button>
        <?php if ($search !== ''): ?>
            <a href="/gespraeche/">Suche zurücksetzen</a>
        <?php endif; ?>
    </form>

    <p><?= $total ?> Gespräche</p>
    <?php if ($conversations === []): ?>
        <p>Keine Gespräche gefunden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Datum</th>
                <th scope="col">Schülerin</th>
                <th scope="col">Gewünschte Schulform</th>
                <th scope="col">Empfehlung</th>
                <th scope="col">Protokollant</th>
                <th scope="col">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($conversations as $conversation): ?>
                <tr>
                    <td><?= escape(date('d.m.Y H:i', strtotime($conversation['datum']))) ?></td>
                    <td><?= escape($conversation['nachname'] . ', ' . $conversation['vorname']) ?></td>
                    <td><?= escape($schoolForms[$conversation['gewuenschte_schulform']] ?? 'Unbekannt') ?></td>
                    <td><?= escape($recommendations[$conversation['aufnahmeempfehlung']] ?? 'Unbekannt') ?></td>
                    <td><?= escape($conversation['username'] ?? 'Unbekannt') ?></td>
                    <td>
                        <span class="student-actions">
                            <?= renderEditLink(
                                '/schueler/gespraech.php?schueler_id=' . (int) $conversation['schueler_id'] . '&id=' . (int) $conversation['id'],
                                'Gespräch bearbeiten'
                            ) ?>
                            <?= renderStudentDetailLink(
                                (int) $conversation['schueler_id'],
                                $conversation['vorname'] . ' ' . $conversation['nachname']
                            ) ?>
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
                <a href="?q=<?= rawurlencode($search) ?>&amp;seite=<?= $page - 1 ?>">Zurück</a>
            <?php endif; ?>
            <span>Seite <?= $page ?> von <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?>
                <a href="?q=<?= rawurlencode($search) ?>&amp;seite=<?= $page + 1 ?>">Weiter</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
