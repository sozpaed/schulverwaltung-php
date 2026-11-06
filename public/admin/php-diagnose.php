<?php

declare(strict_types=1);

use Schulverwaltung\Admin;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';

Admin::requireAdministrator($user);

$capabilities = [
    'GD-Erweiterung' => extension_loaded('gd'),
    'GD: Bilder erstellen' => function_exists('imagecreatetruecolor'),
    'GD: PNG ausgeben' => function_exists('imagepng'),
    'Imagick-Erweiterung' => extension_loaded('imagick'),
    'zlib-Erweiterung' => extension_loaded('zlib'),
    'mbstring-Erweiterung' => extension_loaded('mbstring'),
];
$settings = [
    'Speicherlimit (memory_limit)' => ini_get('memory_limit'),
    'Maximale Ausführungszeit (max_execution_time)' => ini_get('max_execution_time'),
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>PHP-Diagnose – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>PHP-Diagnose</h1>
    <?= renderAccountMenu($user) ?>
    <p>Diese begrenzte Übersicht unterstützt die Planung der QR-Code-Funktion.
        Sie enthält keine vollständige phpinfo-Ausgabe oder Konfigurationsdatei.</p>
    <table>
        <thead>
        <tr><th scope="col">Angabe</th><th scope="col">Wert</th></tr>
        </thead>
        <tbody>
        <tr><td>PHP-Version</td><td><?= escape(PHP_VERSION) ?></td></tr>
        <?php foreach ($capabilities as $label => $available): ?>
            <tr><td><?= escape($label) ?></td><td><?= $available ? 'Verfügbar' : 'Nicht verfügbar' ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($settings as $label => $value): ?>
            <tr><td><?= escape($label) ?></td><td><?= $value === false ? 'Nicht verfügbar' : escape($value) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
