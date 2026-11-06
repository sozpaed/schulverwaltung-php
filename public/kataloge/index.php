<?php

declare(strict_types=1);

use Schulverwaltung\Catalogs;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Catalogs.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$definitions = Catalogs::definitions();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Auswahllisten – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Auswahllisten</h1>
    <?= renderAccountMenu($user) ?>
    <p>Mit entsprechender Berechtigung können ungenutzte Einträge gelöscht werden. Verwendete Einträge bleiben zum Schutz bestehender Zuordnungen erhalten.</p>

    <ul>
    <?php foreach ($definitions as $type => $definition): ?>
        <li>
            <a href="/kataloge/liste.php?typ=<?= rawurlencode($type) ?>"><?= escape($definition['title']) ?></a>
        </li>
    <?php endforeach; ?>
    </ul>
</main>
</body>
</html>
