<?php

declare(strict_types=1);

if (($argv[1] ?? '') === 'request') {
    $request = json_decode(base64_decode($argv[2], true), true, 512, JSON_THROW_ON_ERROR);
    $_GET = $request['get'];
    $_POST = $request['post'];
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SESSION = ['csrf_token' => 'test-csrf-token'];
    $testUser = $request['user'];
    $testDatabasePath = $request['database'];
    register_shutdown_function(static function (): void {
        echo "\nTEST_STATUS:", http_response_code() ?: 200;
    });
    require $request['endpoint'];
    exit;
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Für diesen Test wird die PDO-SQLite-Erweiterung benötigt.\n");
    exit(1);
}

function expectConversationDelete(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'conversation-delete-' . bin2hex(random_bytes(8));
$src = $directory . DIRECTORY_SEPARATOR . 'src';
$public = $directory . DIRECTORY_SEPARATOR . 'public';
$conversations = $public . DIRECTORY_SEPARATOR . 'gespraeche';
$bootstrap = $src . DIRECTORY_SEPARATOR . 'bootstrap.php';
$endpoint = $conversations . DIRECTORY_SEPARATOR . 'loeschen.php';
$databasePath = $directory . DIRECTORY_SEPARATOR . 'test.sqlite';

try {
    foreach ([$directory, $src, $public, $conversations] as $path) {
        if (!mkdir($path)) {
            throw new RuntimeException('Testverzeichnis konnte nicht angelegt werden.');
        }
    }
    if (!copy(__DIR__ . '/../public/gespraeche/loeschen.php', $endpoint)) {
        throw new RuntimeException('Test-Endpunkt konnte nicht kopiert werden.');
    }
    $bootstrapContent = '<?php' . "\n"
        . 'require_once ' . var_export(realpath(__DIR__ . '/../src/Auth.php'), true) . ";\n"
        . <<<'PHP'
$database = new PDO('sqlite:' . $testDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->exec('PRAGMA foreign_keys = ON');
$user = $testUser;
function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function renderSchoolHeader(PDO $database): string { return ''; }
function renderAccountMenu(array $user): string { return ''; }
PHP;
    if (file_put_contents($bootstrap, $bootstrapContent) === false) {
        throw new RuntimeException('Test-Bootstrap konnte nicht geschrieben werden.');
    }

    $database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec('CREATE TABLE schueler (id INTEGER PRIMARY KEY, vorname TEXT, nachname TEXT)');
    $database->exec('CREATE TABLE gespraeche (id INTEGER PRIMARY KEY, schueler_id INTEGER, datum TEXT)');
    $database->exec('CREATE TABLE gespraech_marketing_kanaele (
        gespraech_id INTEGER REFERENCES gespraeche(id) ON DELETE CASCADE, marketing_kanal_id INTEGER
    )');
    $database->exec("INSERT INTO schueler VALUES (1, 'Anna', 'Beispiel')");
    $database->exec("INSERT INTO gespraeche VALUES (1, 1, '2026-01-01 10:00:00'), (2, 1, '2026-01-02 10:00:00')");
    $database->exec('INSERT INTO gespraech_marketing_kanaele VALUES (1, 1), (2, 1)');

    $request = static function (string $method, array $get, array $post, ?array $user) use ($databasePath, $endpoint): array {
        $input = base64_encode(json_encode([
            'method' => $method, 'get' => $get, 'post' => $post, 'user' => $user,
            'database' => $databasePath, 'endpoint' => $endpoint,
        ], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY];
        $sqliteExtension = rtrim(ini_get('extension_dir'), '/\\') . DIRECTORY_SEPARATOR
            . (PHP_OS_FAMILY === 'Windows' ? 'php_pdo_sqlite.dll' : 'pdo_sqlite.so');
        if (is_file($sqliteExtension)) {
            $command[] = '-d';
            $command[] = 'extension=' . $sqliteExtension;
        }
        array_push($command, __FILE__, 'request', $input);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Testanfrage konnte nicht gestartet werden.');
        }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        expectConversationDelete($exitCode === 0 && preg_match('/\nTEST_STATUS:(\d+)$/', $output, $matches) === 1,
            'Testanfrage fehlgeschlagen: ' . $errors . $output);
        return [(int) $matches[1], $output];
    };

    $allowed = ['can_delete_students' => true];
    $denied = ['can_delete_students' => false];
    [$status] = $request('GET', ['id' => '1'], [], null);
    expectConversationDelete($status === 302, 'Nicht angemeldeter Zugriff wurde nicht umgeleitet.');
    [$status] = $request('POST', ['id' => '1'], ['csrf_token' => 'test-csrf-token'], $denied);
    expectConversationDelete($status === 403, 'Löschen ohne Berechtigung wurde nicht abgewiesen.');
    [$status] = $request('GET', ['id' => 'invalid'], [], $allowed);
    expectConversationDelete($status === 400, 'Ungültige ID wurde nicht abgewiesen.');
    [$status] = $request('GET', ['id' => '999'], [], $allowed);
    expectConversationDelete($status === 404, 'Fehlendes Gespräch wurde nicht erkannt.');
    [$status] = $request('GET', ['id' => '1', 'von' => 'unbekannt'], [], $allowed);
    expectConversationDelete($status === 400, 'Ungültige Herkunft wurde nicht abgewiesen.');
    [$status, $output] = $request('GET', ['id' => '1', 'von' => 'akte'], [], $allowed);
    expectConversationDelete($status === 200 && str_contains($output, '/schueler/zeigen.php?id=1'),
        'Bestätigung oder Rückweg zur Schülerinnenakte fehlt.');
    [$status] = $request('POST', ['id' => '1'], ['csrf_token' => 'wrong'], $allowed);
    expectConversationDelete($status === 400, 'Ungültiger CSRF-Token wurde nicht abgewiesen.');
    expectConversationDelete((int) $database->query('SELECT COUNT(*) FROM gespraeche')->fetchColumn() === 2,
        'Ein Gespräch wurde ohne gültige Bestätigung gelöscht.');
    [$status] = $request('POST', ['id' => '1', 'von' => 'akte'], ['csrf_token' => 'test-csrf-token'], $allowed);
    expectConversationDelete($status === 303, 'Bestätigtes Löschen wurde nicht abgeschlossen.');
    [$status] = $request('POST', ['id' => '1'], ['csrf_token' => 'test-csrf-token'], $allowed);
    expectConversationDelete($status === 404, 'Ein bereits gelöschtes Gespräch wurde nicht als fehlend erkannt.');
    expectConversationDelete((int) $database->query('SELECT COUNT(*) FROM schueler')->fetchColumn() === 1
        && (int) $database->query('SELECT COUNT(*) FROM gespraeche WHERE id = 2')->fetchColumn() === 1
        && (int) $database->query('SELECT COUNT(*) FROM gespraech_marketing_kanaele WHERE gespraech_id = 1')->fetchColumn() === 0
        && (int) $database->query('SELECT COUNT(*) FROM gespraech_marketing_kanaele WHERE gespraech_id = 2')->fetchColumn() === 1,
        'Schülerin, anderes Gespräch oder Marketingkanal-Zuordnungen wurden falsch behandelt.');
    [$status, $output] = $request('GET', ['id' => '2', 'von' => 'liste'], [], $allowed);
    expectConversationDelete($status === 200 && str_contains($output, 'href="/gespraeche/"'),
        'Rückweg zur Gesprächsliste fehlt.');
    [$status] = $request('POST', ['id' => '2', 'von' => 'liste'], ['csrf_token' => 'test-csrf-token'], $allowed);
    expectConversationDelete($status === 303 && (int) $database->query('SELECT COUNT(*) FROM gespraeche')->fetchColumn() === 0,
        'Löschen aus der Gesprächsliste ist fehlgeschlagen.');
    $database = null;
    fwrite(STDOUT, "Gesprächslöschung erfolgreich geprüft.\n");
} finally {
    $database = null;
    foreach ([$endpoint, $bootstrap, $databasePath] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach ([$conversations, $public, $src, $directory] as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
