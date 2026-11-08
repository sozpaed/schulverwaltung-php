<?php

declare(strict_types=1);

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderPageHeader(string $schoolName): string
{
    $label = $schoolName === '' ? 'Schulverwaltung' : $schoolName . ' - Schulverwaltung';
    return '<div class="page-header">' . escape($label) . '</div>';
}

function studentOverviewUrl(array $filter): string
{
    $parameters = array_filter($filter, static fn (string $value): bool => $value !== '');
    return '/schueler/' . ($parameters === [] ? '' : '?' . http_build_query($parameters));
}

function renderRecordNavigation(array $current, array $neighbors, string $page, bool $guardian = false, bool $fromList = false): string
{
    $html = '<nav class="record-navigation" aria-label="Datensatznavigation">';
    foreach (['previous', 'current', 'next'] as $position) {
        if ($position === 'current') {
            $html .= '<strong class="record-navigation-current">' . escape($current['vorname'] . ' ' . $current['nachname']) . '</strong>';
            continue;
        }
        $record = $neighbors[$position];
        if ($record === null) {
            $html .= '<span class="record-navigation-empty" aria-hidden="true"></span>';
            continue;
        }
        $parameters = $guardian
            ? ['schueler_id' => $record['schueler_id'], 'id' => $record['id']]
            : ['id' => $record['id']];
        if ($guardian && $fromList) {
            $parameters['von'] = 'liste';
        }
        $name = $record['vorname'] . ' ' . $record['nachname'];
        $label = ($position === 'previous' ? 'Vorheriger Datensatz: ' : 'Nächster Datensatz: ') . $name;
        $text = $position === 'previous' ? '← ' . $name : $name . ' →';
        $html .= '<a class="button-link" href="' . escape($page . '?' . http_build_query($parameters))
            . '" aria-label="' . escape($label) . '">' . escape($text) . '</a>';
    }
    return $html . '</nav>';
}
