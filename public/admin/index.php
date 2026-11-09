<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\PermissionGroups;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';
require_once __DIR__ . '/../../src/PermissionGroups.php';

Admin::requireAdministrator($user);

$users = $database->query(
    'SELECT id, username, is_active, is_superuser, can_export, can_edit_letter_templates, can_view_maintenance, can_view_statistics, can_delete_catalogs, can_delete_students, erstellt_am
     FROM app_users
     ORDER BY username, id'
)->fetchAll();
$groupNames = PermissionGroups::listForUsers($database, $users);
foreach ($users as &$listedUser) {
    $listedUser = PermissionGroups::effectivePermissions($database, $listedUser);
}
unset($listedUser);
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
    <?= renderSchoolHeader($database) ?>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <p><a href="/administration/">Zur Administration</a></p>
    <h1>Benutzerverwaltung</h1>
    <?= renderAccountMenu($user) ?>
    <p><a href="/admin/neu.php">Benutzer anlegen</a></p>
    <p><a href="/admin/gruppen.php">Berechtigungsgruppen verwalten</a></p>
    <p><a href="/admin/anmeldungen.php">Anmeldeprotokoll anzeigen</a></p>
    <p><a href="/admin/2fa-protokoll.php">2FA-Rücksetzungen anzeigen</a></p>
    <?php if ($users === []): ?>
        <p>Es sind keine Benutzer vorhanden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Benutzername</th>
                <th scope="col">Status</th>
                <th scope="col">Administrator</th>
                <th scope="col">Berechtigungsgruppen</th>
                <th scope="col">Exportrecht (effektiv)</th>
                <th scope="col">Briefvorlagen bearbeiten (effektiv)</th>
                <th scope="col">Wartung ansehen (effektiv)</th>
                <th scope="col">Statistik ansehen (effektiv)</th>
                <th scope="col">Auswahllisten löschen (effektiv)</th>
                <th scope="col">Schülerinnen löschen (effektiv)</th>
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
                    <td><?= escape(implode(', ', $groupNames[(int) $listedUser['id']] ?? [])) ?: '–' ?></td>
                    <td><?= (bool) $listedUser['can_export'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= (bool) $listedUser['can_edit_letter_templates'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= (bool) $listedUser['can_view_maintenance'] ? 'Ja' : 'Nein' ?></td>
                    <td><?= (bool) $listedUser['can_view_statistics'] ? 'Ja' : 'Nein' ?></td>
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
</main>
</body>
</html>
