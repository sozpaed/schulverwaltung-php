<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\SchoolSettings;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/SchoolSettings.php';

if ($user === null) {
    header('Location: /');
    exit;
}

if (!$user['can_export']) {
    http_response_code(403);
    exit('Du bist nicht berechtigt, Serienbriefe zu erstellen.');
}

$csrfToken = Auth::csrfToken();
$settings = SchoolSettings::get($database);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Serienbriefe – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Serienbriefe als PDF</h1>
    <?= renderAccountMenu($user) ?>
    <p>Die Texte sind zunächst Muster und müssen vor einem Versand geprüft werden.
        Die Schulform wird jeweils zuerst ausgewählt.</p>
    <?php if ($settings['school_name'] === '' || $settings['head_name'] === ''): ?>
        <p role="alert">Name der Schule und Name der Schulleitung müssen vor der PDF-Erstellung in der Administration hinterlegt werden.</p>
    <?php endif; ?>
    <form method="post" action="/exporte/serienbrief.php">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <input type="hidden" name="filter_type" value="status">
        <h2>Nach Status filtern</h2>
        <p><label for="school_form_status">Schulform</label>
            <select id="school_form_status" name="school_form" required>
                <option value="">Bitte auswählen</option>
                <option value="foerderstufe">Förderstufe</option>
                <option value="gymnasium">Gymnasium</option>
            </select></p>
        <p><label for="status">Status</label>
            <select id="status" name="filter_value" required>
                <option value="">Bitte auswählen</option>
                <option value="in_pruefung">In Prüfung</option>
                <option value="angenommen">Angenommen</option>
                <option value="abgelehnt">Abgelehnt</option>
            </select></p>
        <button type="submit">Serienbrief-PDF nach Status laden</button>
    </form>
    <form method="post" action="/exporte/serienbrief.php">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <input type="hidden" name="filter_type" value="warteliste">
        <input type="hidden" name="filter_value" value="ja">
        <h2>Nach Warteliste filtern</h2>
        <p><label for="school_form_waitlist">Schulform</label>
            <select id="school_form_waitlist" name="school_form" required>
                <option value="">Bitte auswählen</option>
                <option value="foerderstufe">Förderstufe</option>
                <option value="gymnasium">Gymnasium</option>
            </select></p>
        <p>Filter: Warteliste (Ja)</p>
        <button type="submit">Serienbrief-PDF für die Warteliste laden</button>
    </form>
</main>
</body>
</html>
