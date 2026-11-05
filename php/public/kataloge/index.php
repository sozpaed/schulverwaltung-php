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
    <title>Auswahllisten – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/">Zur Startseite</a></p>
    <h1>Auswahllisten</h1>
    <p>Angemeldet als <?= escape($user['username']) ?>.</p>
    <p>Einträge können bearbeitet, aber nicht gelöscht werden, damit bestehende Zuordnungen erhalten bleiben.</p>

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
