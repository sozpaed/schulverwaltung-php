<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);

$users = $database->query(
    'SELECT id, username, is_active, is_superuser, can_export, erstellt_am
     FROM app_users
     ORDER BY username, id'
)->fetchAll();
$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzerverwaltung – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Benutzerverwaltung</h1>
    <p>Angemeldet als <?= escape($user['username']) ?>.</p>
    <p><a href="/admin/neu.php">Benutzer anlegen</a></p>
    <?php if ($users === []): ?>
        <p>Es sind keine Benutzer vorhanden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Benutzername</th>
                <th scope="col">Status</th>
                <th scope="col">Administrator</th>
                <th scope="col">Exportrecht</th>
                <th scope="col">Erstellt am</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $listedUser): ?>
                <tr>
                    <td><?= escape($listedUser['username']) ?></td>
                    <td><?= (bool) $listedUser['is_active'] ? 'Aktiv' : 'Deaktiviert' ?></td>
                    <td><?= (bool) $listedUser['is_superuser'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= (bool) $listedUser['can_export'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= escape($listedUser['erstellt_am']) ?></td>
                    <td>
                        <a href="/admin/bearbeiten.php?id=<?= (int) $listedUser['id'] ?>">Bearbeiten</a>
                        <?php if ((int) $listedUser['id'] !== $user['id']): ?>
                            <a href="/admin/loeschen.php?id=<?= (int) $listedUser['id'] ?>">Löschen</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <form method="post" action="/">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <button type="submit">Abmelden</button>
    </form>
</main>
</body>
</html>
