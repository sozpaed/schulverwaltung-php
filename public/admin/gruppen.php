<?php

declare(strict_types=1);

use Schulverwaltung\Admin;
use Schulverwaltung\Auth;
use Schulverwaltung\PermissionGroups;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Admin.php';
require_once __DIR__ . '/../../src/PermissionGroups.php';

Admin::requireAdministrator($user);

$groupIdValue = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['gruppe_id'] ?? null)
    : ($_GET['id'] ?? null);
$groupId = null;
if ($groupIdValue !== null && $groupIdValue !== '') {
    $groupId = is_string($groupIdValue)
        ? filter_var($groupIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($groupId === false) {
        http_response_code(400);
        exit('Ungültige Gruppen-ID.');
    }
}

$group = null;
if ($groupId !== null) {
    $statement = $database->prepare('SELECT * FROM berechtigungsgruppen WHERE id = :id');
    $statement->execute(['id' => $groupId]);
    $group = $statement->fetch();
    if ($group === false) {
        http_response_code(404);
        exit('Die Berechtigungsgruppe wurde nicht gefunden.');
    }
}

$name = is_array($group) ? $group['name'] : '';
$rights = array_fill_keys(array_keys(PermissionGroups::RIGHTS), 0);
if (is_array($group)) {
    foreach ($rights as $right => $_) {
        $rights[$right] = (int) $group[$right];
    }
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        $errors[] = 'Das Formular ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.';
    } else {
        $action = $_POST['action'] ?? null;
        if ($action === 'delete' && $groupId !== null) {
            $delete = $database->prepare('DELETE FROM berechtigungsgruppen WHERE id = :id');
            $delete->execute(['id' => $groupId]);
            header('Location: /admin/gruppen.php');
            exit;
        }
        if ($action !== 'save') {
            http_response_code(400);
            $errors[] = 'Ungültige Formularaktion.';
        } else {
            try {
                $name = PermissionGroups::validateName($_POST['name'] ?? null);
                $rights = PermissionGroups::validateRights($_POST['rechte'] ?? []);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }

            if ($errors === []) {
                $columns = implode(', ', array_keys($rights));
                $placeholders = implode(', ', array_map(static fn (string $right): string => ':' . $right, array_keys($rights)));
                $parameters = ['name' => $name] + $rights;
                try {
                    if ($groupId === null) {
                        $statement = $database->prepare(
                            "INSERT INTO berechtigungsgruppen (name, $columns) VALUES (:name, $placeholders)"
                        );
                    } else {
                        $assignments = implode(', ', array_map(
                            static fn (string $right): string => "$right = :$right",
                            array_keys($rights)
                        ));
                        $statement = $database->prepare(
                            "UPDATE berechtigungsgruppen SET name = :name, $assignments WHERE id = :id"
                        );
                        $parameters['id'] = $groupId;
                    }
                    $statement->execute($parameters);
                    header('Location: /admin/gruppen.php');
                    exit;
                } catch (PDOException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }
                    $errors[] = 'Eine Berechtigungsgruppe mit diesem Namen existiert bereits.';
                }
            }
        }
    }
}

$groupList = $database->query('SELECT id, name FROM berechtigungsgruppen ORDER BY name, id')->fetchAll();
$csrfToken = Auth::csrfToken();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
    <title>Berechtigungsgruppen – Schulverwaltung</title>
</head>
<body>
<main>
    <?= renderSchoolHeader($database) ?>
    <a class="home-link" href="/" aria-label="Zur Startseite" title="Zur Startseite">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"></path>
        </svg>
    </a>
    <?= renderAccountMenu($user) ?>
    <p><a href="/admin/">Zur Benutzerverwaltung</a></p>
    <h1>Berechtigungsgruppen</h1>
    <?php if ($errors !== []): ?>
        <div role="alert">
            <ul>
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <h2>Vorhandene Gruppen</h2>
    <?php if ($groupList === []): ?>
        <p>Es sind noch keine Berechtigungsgruppen vorhanden.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($groupList as $listedGroup): ?>
                <li><a href="/admin/gruppen.php?id=<?= (int) $listedGroup['id'] ?>"><?= escape($listedGroup['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <h2><?= $groupId === null ? 'Neue Gruppe anlegen' : 'Gruppe bearbeiten' ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <?php if ($groupId !== null): ?>
            <input type="hidden" name="gruppe_id" value="<?= (int) $groupId ?>">
        <?php endif; ?>
        <input type="hidden" name="action" value="save">
        <p>
            <label for="name">Gruppenname</label>
            <input id="name" name="name" maxlength="100" required value="<?= escape($name) ?>">
        </p>
        <fieldset>
            <legend>Gruppenrechte</legend>
            <?php foreach (PermissionGroups::RIGHTS as $right => $label): ?>
                <p>
                    <input id="<?= escape($right) ?>" name="rechte[<?= escape($right) ?>]" type="checkbox"
                           value="1"<?= (bool) $rights[$right] ? ' checked' : '' ?>>
                    <label for="<?= escape($right) ?>"><?= escape($label) ?></label>
                </p>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit">Gruppe speichern</button>
    </form>
    <?php if ($groupId !== null): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <input type="hidden" name="gruppe_id" value="<?= (int) $groupId ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit">Gruppe löschen</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
