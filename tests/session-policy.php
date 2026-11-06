<?php

declare(strict_types=1);

use Schulverwaltung\SessionPolicy;

require_once __DIR__ . '/../src/SessionPolicy.php';

$now = 100000;
$cases = [
    [false, $now - 100, $now - 1799],
    [true, $now - 1800, $now - 1800],
    [false, $now - 28799, $now - 1],
    [true, $now - 28800, $now - 1],
    [true, $now + 1, $now],
    [true, $now, $now + 1],
];
foreach ($cases as [$expected, $login, $activity]) {
    if (SessionPolicy::expired($now, $login, $activity) !== $expected) {
        throw new RuntimeException('Sitzungsablauf wurde falsch berechnet.');
    }
}
foreach ([[$now - 899, false], [$now - 900, true], [$now + 1, true]] as [$regenerated, $expected]) {
    if (SessionPolicy::shouldRegenerate($now, $regenerated) !== $expected) {
        throw new RuntimeException('Sitzungserneuerung wurde falsch berechnet.');
    }
}
fwrite(STDOUT, "Sitzungszeiten erfolgreich geprüft.\n");
