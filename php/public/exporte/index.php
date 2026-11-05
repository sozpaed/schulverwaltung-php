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
    <title>Datenexport – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Datenexport</h1>
    <p>Angemeldet als <?= escape($user['username']) ?>.</p>
    <p>Die CSV-Dateien enthalten alle Datensätze des jeweiligen Bereichs, ohne Filter.</p>
    <ul>
        <li><a href="/exporte/download.php?bereich=schueler">Schülerinnen und Schüler exportieren</a></li>
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
