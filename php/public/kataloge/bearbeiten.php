<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
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

$entryId = null;
$existingEntry = null;
if (array_key_exists('id', $_GET)) {
    $entryIdValue = $_GET['id'];
    $entryId = is_string($entryIdValue)
        ? filter_var($entryIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if ($entryId === false) {
        http_response_code(400);
        exit('Ungültige Eintrags-ID.');
    }

    $columns = $definition['has_location'] ? 'id, name, ort' : 'id, name';
    $statement = $database->prepare(
        sprintf('SELECT %s FROM %s WHERE id = :id', $columns, $definition['table'])
    );
    $statement->execute(['id' => $entryId]);
    $existingEntry = $statement->fetch();

    if ($existingEntry === false) {
        http_response_code(404);
        exit('Der Auswahllisteneintrag wurde nicht gefunden.');
    }
}

$name = $existingEntry['name'] ?? '';
$location = $existingEntry['ort'] ?? '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        $nameValue = $_POST['name'] ?? null;
        $locationValue = $_POST['ort'] ?? '';
        if (!is_string($nameValue) || !is_string($locationValue)) {
            $errors[] = 'Ungültige Formulardaten.';
        } else {
            $name = trim($nameValue);
            $location = trim($locationValue);
            $nameLength = preg_match_all('/./us', $name);
            $locationLength = preg_match_all('/./us', $location);

            if (
                $nameLength === false
                || $locationLength === false
                || $nameLength < 1
                || $nameLength > $definition['name_limit']
            ) {
                $errors[] = sprintf('Der Name muss zwischen 1 und %d Zeichen lang sein.', $definition['name_limit']);
            }

            if (
                $locationLength === false
                || (!$definition['has_location'] && $location !== '')
                || $locationLength > $definition['location_limit']
            ) {
                $errors[] = 'Der Ort ist ungültig oder zu lang.';
            }

            if (
                $errors === []
                && Catalogs::findDuplicate($database, $definition, $name, $location, $entryId)
            ) {
                $errors[] = 'Dieser Eintrag ist bereits vorhanden.';
            }
        }

        if ($errors === []) {
            if ($entryId === null) {
                Catalogs::create($database, $definition, $name, $location);
            } else {
                Catalogs::update($database, $definition, $entryId, $name, $location);
            }

            header('Location: /kataloge/liste.php?typ=' . rawurlencode($type));
            exit;
        }
    }
}

$csrfToken = Auth::csrfToken();
$pageTitle = $entryId === null
    ? 'Eintrag hinzufügen'
    : $definition['singular'] . ' bearbeiten';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($pageTitle) ?> – Schulverwaltung</title>
</head>
<body>
<main>
    <p><a href="/kataloge/liste.php?typ=<?= rawurlencode($type) ?>">Zurück zu <?= escape($definition['title']) ?></a></p>
    <h1><?= escape($pageTitle) ?>: <?= escape($definition['singular']) ?></h1>

    <?php if ($errors !== []): ?>
        <div role="alert">
            <ul>
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <p>
            <label for="name">Name</label>
            <input id="name" name="name" maxlength="<?= (int) $definition['name_limit'] ?>" required value="<?= escape($name) ?>">
        </p>
        <?php if ($definition['has_location']): ?>
            <p>
                <label for="ort">Ort (optional)</label>
                <input id="ort" name="ort" maxlength="<?= (int) $definition['location_limit'] ?>" value="<?= escape($location) ?>">
            </p>
        <?php endif; ?>
        <button type="submit">Speichern</button>
    </form>
</main>
</body>
</html>
