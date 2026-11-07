<?php

declare(strict_types=1);

use Schulverwaltung\StatisticsChart;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/StatisticsChart.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_export']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Statistiken einzusehen.');
}

$statusLabels = [
    'in_pruefung' => 'In Prüfung',
    'angenommen' => 'Angenommen',
    'abgelehnt' => 'Abgelehnt',
    'verzicht' => 'Verzicht',
    'sonstige' => 'Sonstige',
];
$statusColors = [
    'in_pruefung' => '#e3a008',
    'angenommen' => '#32805a',
    'abgelehnt' => '#b94343',
    'verzicht' => '#64748b',
    'sonstige' => '#94a3b8',
];
$classLabels = [
    '5a' => '5a',
    '5b' => '5b',
    '5c' => '5c',
    '5d' => '5d',
    '5e' => '5e',
    '' => 'Nicht festgelegt',
];
$classColors = [
    '5a' => '#e31c79',
    '5b' => '#203348',
    '5c' => '#32805a',
    '5d' => '#e3a008',
    '5e' => '#7047a3',
    '' => '#94a3b8',
];
$schoolFormLabels = [
    'foerderstufe' => 'Förderstufe',
    'gymnasium' => 'Gymnasium',
    '' => 'Nicht festgelegt',
];
$schoolFormColors = [
    'foerderstufe' => '#e31c79',
    'gymnasium' => '#203348',
    '' => '#94a3b8',
];
$distributionDefinitions = [
    'status' => [
        'title' => 'Nach Status',
        'column' => 'status',
        'labels' => $statusLabels,
        'colors' => $statusColors,
    ],
    'class' => [
        'title' => 'Nach Klasse',
        'column' => 'klasse',
        'labels' => $classLabels,
        'colors' => $classColors,
    ],
    'school_form' => [
        'title' => 'Nach Schulform',
        'column' => 'schulform',
        'labels' => $schoolFormLabels,
        'colors' => $schoolFormColors,
    ],
];
$distributions = [];
foreach ($distributionDefinitions as $key => $definition) {
    $distributions[$key] = [
        'definition' => $definition,
        'total' => array_fill_keys(array_keys($definition['labels']), 0),
        'waitlist' => array_fill_keys(array_keys($definition['labels']), 0),
    ];
}
$totalStudents = 0;
$waitlistStudents = 0;

foreach ($database->query(
    'SELECT status, klasse, schulform, warteliste, COUNT(*) AS anzahl
     FROM schueler GROUP BY status, klasse, schulform, warteliste'
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $count = (int) $row['anzahl'];
    $totalStudents += $count;
    $isWaitlisted = (bool) $row['warteliste'];
    if ($isWaitlisted) {
        $waitlistStudents += $count;
    }

    foreach ($distributionDefinitions as $key => $definition) {
        $value = $row[$definition['column']];
        $category = array_key_exists($value, $definition['labels'])
            ? $value
            : array_key_last($definition['labels']);
        $distributions[$key]['total'][$category] += $count;
        if ($isWaitlisted) {
            $distributions[$key]['waitlist'][$category] += $count;
        }
    }
}

$furtherValues = [];
foreach ([
    'Gespräche' => 'gespraeche',
    'Erziehungsberechtigte' => 'erziehungsberechtigte',
] as $label => $table) {
    $counts = $database->query(
        'SELECT COUNT(*) AS gesamt,
                COALESCE(SUM(s.warteliste = 1), 0) AS warteliste
         FROM ' . $table . ' d
         JOIN schueler s ON s.id = d.schueler_id'
    )->fetch(PDO::FETCH_ASSOC);
    $furtherValues[$label] = [
        'total' => (int) $counts['gesamt'],
        'waitlist' => (int) $counts['warteliste'],
    ];
}
$furtherValues['Schülerinnen'] = [
    'total' => $totalStudents,
    'waitlist' => $waitlistStudents,
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Statistik – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Statistik</h1>
    <?= renderAccountMenu($user) ?>
    <p>Übersicht über die aktuellen Datensätze. Es werden ausschließlich zusammengefasste Zahlen angezeigt.</p>
    <section class="statistics-grid" aria-label="Statistik nach Status, Klasse und Schulform">
        <?php foreach ($distributions as $key => $distribution): ?>
            <?php
            $definition = $distribution['definition'];
            $adjusted = [];
            foreach ($definition['labels'] as $category => $_label) {
                $adjusted[$category] = $distribution['total'][$category] - $distribution['waitlist'][$category];
            }
            $series = [
                'total' => $distribution['total'],
                'waitlist' => $distribution['waitlist'],
                'adjusted' => $adjusted,
            ];
            ?>
            <article class="statistics-panel">
                <h2><?= escape($definition['title']) ?></h2>
                <div class="statistics-table-wrapper">
                    <table class="statistics-table">
                        <thead><tr>
                            <th scope="col"><?= escape($definition['title']) ?></th>
                            <th scope="col">Anzahl Gesamt</th>
                            <th scope="col">Davon Warteliste</th>
                            <th scope="col">Bereinigt</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($definition['labels'] as $category => $label): ?>
                            <tr>
                                <th scope="row">
                                    <span class="legend-swatch" aria-hidden="true"
                                          data-color="<?= escape($definition['colors'][$category]) ?>"></span>
                                    <?= escape($label) ?>
                                </th>
                                <td><?= number_format($distribution['total'][$category], 0, ',', '.') ?></td>
                                <td><?= number_format($distribution['waitlist'][$category], 0, ',', '.') ?></td>
                                <td><?= number_format($adjusted[$category], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="statistics-total">
                                <th scope="row">Summe</th>
                                <td><?= number_format(array_sum($distribution['total']), 0, ',', '.') ?></td>
                                <td><?= number_format(array_sum($distribution['waitlist']), 0, ',', '.') ?></td>
                                <td><?= number_format(array_sum($adjusted), 0, ',', '.') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?= StatisticsChart::renderRing(
                    $definition['title'] . ' – außen Gesamt, Mitte Warteliste, innen bereinigt',
                    $series,
                    $definition['colors'],
                    'statistics-' . $key
                ) ?>
                <p class="ring-description">Außen: Gesamt · Mitte: Warteliste · Innen: Bereinigt</p>
            </article>
        <?php endforeach; ?>
    </section>
    <h2>Weitere Werte</h2>
    <p>Bei Erziehungsberechtigten und Gesprächen wird die Warteliste anhand der zugeordneten Schülerin gezählt.</p>
    <div class="table-wrapper">
        <table class="statistics-table">
            <thead><tr>
                <th scope="col">Wert</th>
                <th scope="col">Anzahl Gesamt</th>
                <th scope="col">Davon Warteliste</th>
                <th scope="col">Bereinigt</th>
            </tr></thead>
            <tbody>
            <?php foreach ($furtherValues as $label => $counts): ?>
                <tr>
                    <th scope="row"><?= escape($label) ?></th>
                    <td><?= number_format($counts['total'], 0, ',', '.') ?></td>
                    <td><?= number_format($counts['waitlist'], 0, ',', '.') ?></td>
                    <td><?= number_format($counts['total'] - $counts['waitlist'], 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p>„Bereinigt“ bedeutet Anzahl Gesamt abzüglich der Datensätze auf der Warteliste.</p>
</main>
</body>
</html>
