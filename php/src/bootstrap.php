<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\Database;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);

session_start();

$database = Database::connect();
$user = Auth::user($database);

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
