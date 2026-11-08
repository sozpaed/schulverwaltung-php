<?php

declare(strict_types=1);

use Schulverwaltung\Admin;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);

$filter = $_GET['benutzername'] ?? '';
if (!is_string($filter) || preg_match('//u', $filter) !== 1) {
    http_response_code(400);
    exit('Ungültiger Benutzername.');
}
$filter = trim($filter);
$statement = $database->prepare(
    'SELECT erstellt_am, benutzername, INET6_NTOA(ip) AS ip, erfolgreich, grund
     FROM login_versuche
     WHERE (:empty_filter = 1 OR benutzername = :username)
     ORDER BY erstellt_am DESC, id DESC
     LIMIT 200'
);
$statement->execute(['empty_filter' => (int) ($filter === ''), 'username' => $filter]);
$entries = $statement->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Anmeldeprotokoll – Schulverwaltung</title>
</head>
<body>
<main>
    <?= renderSchoolHeader($database) ?>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>Anmeldeprotokoll</h1>
    <?= renderAccountMenu($user) ?>
    <p>Die letzten 200 Anmeldeversuche; die Zeiten entsprechen der Zeitzone des Datenbankservers.</p>
    <form method="get">
        <label for="benutzername">Benutzername</label>
        <input id="benutzername" name="benutzername" maxlength="150" value="<?= escape($filter) ?>">
        <button type="submit">Filtern</button>
    </form>
    <?php if ($entries === []): ?>
        <p>Keine Anmeldeversuche gefunden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Zeit</th>
                <th scope="col">Benutzername</th>
                <th scope="col">IP-Adresse</th>
                <th scope="col">Erfolg</th>
                <th scope="col">Grund</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= escape($entry['erstellt_am']) ?></td>
                    <td><?= escape($entry['benutzername']) ?></td>
                    <td><?= escape($entry['ip'] ?? '') ?></td>
                    <td><?= (bool) $entry['erfolgreich'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= escape($entry['grund'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
