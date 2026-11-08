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
