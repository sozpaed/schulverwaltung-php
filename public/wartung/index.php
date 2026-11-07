<?php

declare(strict_types=1);

use Schulverwaltung\Maintenance;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Maintenance.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$studentDefinitions = Maintenance::studentDefinitions();
$guardianDefinitions = Maintenance::guardianDefinitions();
$studentSelect = ['COUNT(*) AS total'];
$guardianSelect = ['COUNT(*) AS total'];
foreach ($studentDefinitions as $key => $definition) {
    $studentSelect[] = 'COALESCE(SUM(' . $definition['condition'] . '), 0) AS `' . $key . '`';
}
foreach ($guardianDefinitions as $key => $definition) {
    $guardianSelect[] = 'COALESCE(SUM(' . $definition['condition'] . '), 0) AS `' . $key . '`';
}
$studentCounts = $database->query(
    'SELECT ' . implode(', ', $studentSelect) . ' FROM schueler s'
)->fetch(PDO::FETCH_ASSOC);
$guardianCounts = $database->query(
    'SELECT ' . implode(', ', $guardianSelect) . '
     FROM erziehungsberechtigte e
     JOIN schueler s ON s.id = e.schueler_id'
)->fetch(PDO::FETCH_ASSOC);
$csrfToken = \Schulverwaltung\Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Wartung – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Wartung</h1>
    <?= renderAccountMenu($user) ?>
    <p>Die Tabellen zeigen Datensätze mit fehlenden Angaben. Wähle eine Zeile, um die passenden Einträge zu öffnen.</p>

    <h2>Schülerinnen</h2>
    <table>
        <thead><tr><th scope="col">Fehlend</th><th scope="col">Treffer</th><th scope="col">Zur Übersicht</th></tr></thead>
        <tbody>
        <?php foreach ($studentDefinitions as $key => $definition): ?>
            <tr>
                <th scope="row"><?= escape($definition['label']) ?></th>
                <?php $count = (int) $studentCounts[$key]; ?>
                <td><?= number_format($count, 0, ',', '.') ?></td>
                <td>
                    <?php if ($count > 0): ?>
                        <a class="maintenance-open-link"
                           href="/schueler/?wartung=<?= rawurlencode($key) ?>"
                           aria-label="<?= escape($definition['label'] . ': ' . $count . ' Treffer anzeigen') ?>"
                           title="Treffer anzeigen">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M7 17 17 7M7 7h10v10"></path>
                            </svg>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Erziehungsberechtigte</h2>
    <table>
        <thead><tr><th scope="col">Fehlend</th><th scope="col">Treffer</th><th scope="col">Zur Übersicht</th></tr></thead>
        <tbody>
        <?php foreach ($guardianDefinitions as $key => $definition): ?>
            <tr>
                <th scope="row"><?= escape($definition['label']) ?></th>
                <?php $count = (int) $guardianCounts[$key]; ?>
                <td><?= number_format($count, 0, ',', '.') ?></td>
                <td>
                    <?php if ($count > 0): ?>
                        <a class="maintenance-open-link"
                           href="/erziehungsberechtigte/?wartung=<?= rawurlencode($key) ?>"
                           aria-label="<?= escape($definition['label'] . ': ' . $count . ' Treffer anzeigen') ?>"
                           title="Treffer anzeigen">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M7 17 17 7M7 7h10v10"></path>
                            </svg>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" action="/">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
