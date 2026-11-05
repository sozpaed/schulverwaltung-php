<?php

declare(strict_types=1);

use Schulverwaltung\Catalogs;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Catalogs.php';

if ($user === null) {
    header('Location: /');
    exit;
}

$typeValue = $_GET['typ'] ?? null;
$type = is_string($typeValue) ? $typeValue : '';
$definition = Catalogs::definition($type);

if ($definition === null) {
    http_response_code(404);
    exit('Der Auswahllistentyp wurde nicht gefunden.');
}

$columns = $definition['has_location'] ? 'id, name, ort' : 'id, name';
$entries = $database
    ->query(sprintf('SELECT %s FROM %s ORDER BY name, id', $columns, $definition['table']))
    ->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title><?= escape($definition['title']) ?> – Schulverwaltung</title>
</head>
<body>
<main>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <p><a href="/kataloge/">Zurück zu den Auswahllisten</a></p>
    <h1><?= escape($definition['title']) ?></h1>
    <?= renderAccountMenu($user) ?>
    <p><a href="/kataloge/bearbeiten.php?typ=<?= rawurlencode($type) ?>">Eintrag hinzufügen</a></p>

    <?php if ($entries === []): ?>
        <p>Noch keine Einträge vorhanden.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th scope="col">Name</th>
                <?php if ($definition['has_location']): ?>
                    <th scope="col">Ort</th>
                <?php endif; ?>
                <th scope="col">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= escape($entry['name']) ?></td>
                    <?php if ($definition['has_location']): ?>
                        <td><?= escape($entry['ort']) ?></td>
                    <?php endif; ?>
                    <td>
                        <?= renderEditLink(
                            '/kataloge/bearbeiten.php?typ=' . rawurlencode($type) . '&id=' . (int) $entry['id'],
                            $definition['singular'] . ' bearbeiten'
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
