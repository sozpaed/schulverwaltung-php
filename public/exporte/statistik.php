<?php

declare(strict_types=1);

use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_export']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Statistiken einzusehen.');
}

function renderPieChart(string $title, array $values, array $colors, string $id): string
{
    $total = array_sum($values);
    $svg = '<svg class="pie-chart" viewBox="0 0 200 200" role="img" aria-labelledby="' . escape($id) . '-title">';
    $svg .= '<title id="' . escape($id) . '-title">' . escape($title) . '</title>';

    if ($total === 0) {
        $svg .= '<circle cx="100" cy="100" r="90" fill="#dce3e9"></circle>';
        $svg .= '</svg>';

        return $svg;
    }

    if (count(array_filter($values, static fn (int $value): bool => $value > 0)) === 1) {
        $color = $colors[array_key_first(array_filter($values, static fn (int $value): bool => $value > 0))];
        $svg .= '<circle cx="100" cy="100" r="90" fill="' . escape($color) . '"></circle></svg>';

        return $svg;
    }

    $startAngle = -M_PI_2;
    foreach ($values as $key => $value) {
        if ($value === 0) {
            continue;
        }

        $endAngle = $startAngle + 2 * M_PI * $value / $total;
        $startX = 100 + 90 * cos($startAngle);
        $startY = 100 + 90 * sin($startAngle);
        $endX = 100 + 90 * cos($endAngle);
        $endY = 100 + 90 * sin($endAngle);
        $largeArc = $endAngle - $startAngle > M_PI ? 1 : 0;
        $path = sprintf(
            'M 100 100 L %.3f %.3f A 90 90 0 %d 1 %.3f %.3f Z',
            $startX,
            $startY,
            $largeArc,
            $endX,
            $endY
        );
        $svg .= '<path d="' . $path . '" fill="' . escape($colors[$key]) . '"></path>';
        $startAngle = $endAngle;
    }

    return $svg . '</svg>';
}

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
$schoolFormCounts = array_fill_keys(array_keys($schoolFormLabels), 0);
$statusCounts = array_fill_keys(array_keys($statusLabels), 0);
$totalStudents = 0;
$waitlistStudents = 0;

foreach ($database->query(
    'SELECT schulform, status, warteliste, COUNT(*) AS anzahl
     FROM schueler
     GROUP BY schulform, status, warteliste'
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $count = (int) $row['anzahl'];
    $form = array_key_exists($row['schulform'], $schoolFormCounts) ? $row['schulform'] : '';
    $status = array_key_exists($row['status'], $statusCounts) ? $row['status'] : 'sonstige';
    $schoolFormCounts[$form] += $count;
    $statusCounts[$status] += $count;
    $totalStudents += $count;
    if ((bool) $row['warteliste']) {
        $waitlistStudents += $count;
    }
}

$guardianCount = (int) $database->query('SELECT COUNT(*) FROM erziehungsberechtigte')->fetchColumn();
$conversationCount = (int) $database->query('SELECT COUNT(*) FROM gespraeche')->fetchColumn();
$csrfToken = Auth::csrfToken();
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
    <p><a href="/exporte/">Zurück zum Datenexport</a></p>
    <h1>Statistik</h1>
    <?= renderAccountMenu($user) ?>
    <p>Übersicht über die aktuellen Datensätze. Es werden ausschließlich zusammengefasste Zahlen angezeigt.</p>
    <section class="stat-cards" aria-label="Gesamtzahlen">
        <article class="stat-card"><h2>Schülerinnen</h2><p><?= $totalStudents ?></p></article>
        <article class="stat-card"><h2>Erziehungsberechtigte</h2><p><?= $guardianCount ?></p></article>
        <article class="stat-card"><h2>Gespräche</h2><p><?= $conversationCount ?></p></article>
        <article class="stat-card"><h2>Warteliste</h2><p><?= $waitlistStudents ?></p></article>
    </section>
    <section class="statistics-grid" aria-label="Verteilungen">
        <article class="statistics-panel">
            <h2>Schulformen</h2>
            <?= renderPieChart('Verteilung nach Schulform', $schoolFormCounts, $schoolFormColors, 'school-forms') ?>
            <ul class="chart-legend">
                <?php foreach ($schoolFormLabels as $key => $label): ?>
                    <li><span class="legend-swatch" aria-hidden="true" data-color="<?= escape($schoolFormColors[$key]) ?>"></span>
                        <?= escape($label) ?>: <?= $schoolFormCounts[$key] ?></li>
                <?php endforeach; ?>
            </ul>
        </article>
        <article class="statistics-panel">
            <h2>Status</h2>
            <?= renderPieChart('Verteilung nach Status', $statusCounts, $statusColors, 'student-status') ?>
            <ul class="chart-legend">
                <?php foreach ($statusLabels as $key => $label): ?>
                    <li><span class="legend-swatch" aria-hidden="true" data-color="<?= escape($statusColors[$key]) ?>"></span>
                        <?= escape($label) ?>: <?= $statusCounts[$key] ?></li>
                <?php endforeach; ?>
            </ul>
        </article>
    </section>
    <form method="post" action="/">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
