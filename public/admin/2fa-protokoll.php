<?php

declare(strict_types=1);

use Schulverwaltung\Admin;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';
Admin::requireAdministrator($user);

$entries = $database->query(
    "SELECT erstellt_am, administrator_name, benutzername FROM admin_protokoll
     WHERE aktion = '2fa_reset' ORDER BY erstellt_am DESC, id DESC LIMIT 200"
)->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>2FA-Protokoll – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>2FA-Rücksetzungen</h1>
    <?= renderAccountMenu($user) ?>
    <p>Die letzten 200 Rücksetzungen; die Zeiten entsprechen der Zeitzone des Datenbankservers.</p>
    <?php if ($entries === []): ?>
        <p>Es wurden keine Rücksetzungen protokolliert.</p>
    <?php else: ?>
        <table>
            <thead><tr><th scope="col">Zeit</th><th scope="col">Administrator</th><th scope="col">Benutzer</th></tr></thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= escape($entry['erstellt_am']) ?></td>
                    <td><?= escape($entry['administrator_name']) ?></td>
                    <td><?= escape($entry['benutzername']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
