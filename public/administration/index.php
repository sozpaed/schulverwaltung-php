<?php

declare(strict_types=1);

use Schulverwaltung\Admin;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Administration – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Administration</h1>
    <?= renderAccountMenu($user) ?>
    <nav class="dashboard-links" aria-label="Administration">
        <ul class="dashboard-links-secondary">
            <li><a href="/administration/schule.php">Angaben zur Schule</a></li>
            <li><a href="/admin/">Benutzerverwaltung</a></li>
            <li><a href="/import/">Import</a></li>
        </ul>
    </nav>
</main>
</body>
</html>
