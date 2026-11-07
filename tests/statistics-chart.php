<?php

declare(strict_types=1);

use Schulverwaltung\StatisticsChart;

require_once __DIR__ . '/../src/StatisticsChart.php';

function expectChart(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$series = [
    'total' => ['a' => 8, 'b' => 2],
    'waitlist' => ['a' => 3, 'b' => 1],
    'adjusted' => ['a' => 5, 'b' => 1],
];
$colors = ['a' => '#e31c79', 'b' => '#203348'];
$svg = StatisticsChart::renderRing('Nach Status', $series, $colors, 'test-chart');

expectChart(str_contains($svg, 'role="img"'), 'Das Ringdiagramm hat keine barrierefreie Bildrolle.');
expectChart(str_contains($svg, '#e31c79'), 'Die erste Kategorie fehlt im Diagramm.');
expectChart(str_contains($svg, '#203348'), 'Die zweite Kategorie fehlt im Diagramm.');
expectChart(substr_count($svg, 'stroke-dasharray=') === 6, 'Die drei Ringe enthalten nicht alle Diagrammsegmente.');
expectChart(
    str_contains($svg, 'Außen: Gesamt. Mitte: Warteliste. Innen: Bereinigt.'),
    'Die Bedeutung der drei Ringe wird nicht barrierefrei erläutert.'
);

$emptySvg = StatisticsChart::renderRing(
    'Leere Statistik',
    ['total' => ['a' => 0], 'waitlist' => ['a' => 0], 'adjusted' => ['a' => 0]],
    ['a' => '#e31c79'],
    'empty-chart'
);
expectChart(substr_count($emptySvg, 'stroke="#dce3e9"') === 3, 'Leere Ringe werden nicht neutral angezeigt.');

fwrite(STDOUT, "Statistik-Ringdiagramme erfolgreich geprüft." . PHP_EOL);
