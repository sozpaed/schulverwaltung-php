<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);

$users = $database->query(
    'SELECT id, username, is_active, is_superuser, can_export, can_delete_catalogs, can_delete_students, erstellt_am
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
    <link rel="stylesheet" href="/assets/app.css">
    <title>Benutzerverwaltung – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Benutzerverwaltung</h1>
    <?= renderAccountMenu($user) ?>
    <p><a href="/admin/neu.php">Benutzer anlegen</a></p>
    <p><a href="/admin/anmeldungen.php">Anmeldeprotokoll anzeigen</a></p>
    <p><a href="/admin/2fa-protokoll.php">2FA-Rücksetzungen anzeigen</a></p>
    <p><a href="/admin/php-diagnose.php">PHP-Diagnose anzeigen</a></p>
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
                <th scope="col">Auswahllisten löschen</th>
                <th scope="col">Schülerinnen löschen</th>
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
                    <td><?= (bool) $listedUser['can_delete_catalogs'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= (bool) $listedUser['can_delete_students'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= escape($listedUser['erstellt_am']) ?></td>
                    <td>
                        <span class="student-actions">
                            <?= renderEditLink(
                                '/admin/bearbeiten.php?id=' . (int) $listedUser['id'],
                                'Benutzer bearbeiten'
                            ) ?>
                            <?php if ((int) $listedUser['id'] !== $user['id']): ?>
                                <?= renderDeleteLink(
                                    '/admin/loeschen.php?id=' . (int) $listedUser['id'],
                                    'Benutzer löschen: ' . $listedUser['username']
                                ) ?>
                            <?php endif; ?>
                        </span>
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
