<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\Catalogs;

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Catalogs.php';

header('Content-Type: application/json; charset=utf-8');

if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Bitte melde dich erneut an.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Diese Anfrage ist nicht zulässig.']);
    exit;
}

if (!Auth::verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Das Formular ist abgelaufen. Bitte lade die Seite neu.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Anfrage.']);
    exit;
}

$type = $payload['type'] ?? null;
$name = $payload['name'] ?? null;
$location = $payload['ort'] ?? '';

if (!is_string($type) || !is_string($name) || !is_string($location)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bitte gib gültige Katalogdaten ein.']);
    exit;
}

$definition = Catalogs::definition($type);
if ($definition === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Dieser Katalog kann hier nicht ergänzt werden.']);
    exit;
}

$name = trim($name);
$location = trim($location);
$nameLength = preg_match_all('/./us', $name);
$locationLength = preg_match_all('/./us', $location);

if (
    $nameLength === false
    || $locationLength === false
    || $nameLength < 1
    || $nameLength > $definition['name_limit']
    || (!$definition['has_location'] && $location !== '')
    || $locationLength > $definition['location_limit']
) {
    http_response_code(422);
    echo json_encode(['error' => 'Name oder Ort ist leer, ungültig oder zu lang.']);
    exit;
}

if (Catalogs::findDuplicate($database, $definition, $name, $location)) {
    http_response_code(409);
    echo json_encode(['error' => 'Dieser Eintrag ist bereits vorhanden.']);
    exit;
}

try {
    $id = Catalogs::create($database, $definition, $name, $location);
} catch (PDOException $exception) {
    error_log('Katalogeintrag konnte nicht angelegt werden: ' . $exception->getMessage());
    http_response_code('23000' === $exception->getCode() ? 409 : 500);
    echo json_encode([
        'error' => 'Der Katalogeintrag konnte nicht gespeichert werden. Bitte prüfe, ob er bereits vorhanden ist.',
    ]);
    exit;
}

$entry = ['id' => $id, 'name' => $name, 'ort' => $location];
http_response_code(201);
echo json_encode([
    'id' => $id,
    'label' => Catalogs::label($entry, $definition),
]);
