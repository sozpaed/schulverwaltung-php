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
    exit('Du bist nicht berechtigt, Daten zu exportieren.');
}

$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Datenexport – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Datenexport</h1>
    <?= renderAccountMenu($user) ?>
    <h2>Weitere Funktionen</h2>
    <nav class="export-actions" aria-label="Exportfunktionen">
        <a href="/exporte/serienbriefe.php">Serienbriefe</a>
        <a href="/exporte/statistik.php">Statistik</a>
    </nav>
    <h2>CSV-Exporte</h2>
    <p>Die CSV-Dateien enthalten alle Datensätze des jeweiligen Bereichs, ohne Filter.</p>
    <ul>
        <li><a href="/exporte/download.php?bereich=schueler">Schülerinnen exportieren</a></li>
        <li><a href="/exporte/download.php?bereich=erziehungsberechtigte">Erziehungsberechtigte exportieren</a></li>
        <li><a href="/exporte/download.php?bereich=gespraeche">Gespräche exportieren</a></li>
    </ul>
    <p>Die Dateien sind UTF-8-kodiert und verwenden Semikolons als Trennzeichen.</p>
    <form method="post" action="/">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
