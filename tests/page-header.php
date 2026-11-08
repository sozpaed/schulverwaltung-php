<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/helpers.php';

if (renderPageHeader('Beispielschule') !== '<div class="page-header">Beispielschule - Schulverwaltung</div>') {
    throw new RuntimeException('Der Seitenkopf enthält nicht den Schulnamen vor Schulverwaltung.');
}
if (renderPageHeader('') !== '<div class="page-header">Schulverwaltung</div>') {
    throw new RuntimeException('Der Seitenkopf ohne Schulnamen ist ungültig.');
}
if (renderPageHeader('Schule <Test> & Partner') !== '<div class="page-header">Schule &lt;Test&gt; &amp; Partner - Schulverwaltung</div>') {
    throw new RuntimeException('Der Schulname im Seitenkopf wurde nicht korrekt maskiert.');
}

fwrite(STDOUT, "Seitenkopf erfolgreich geprüft.\n");
