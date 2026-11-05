<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\Database;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/TwoFactor.php';
require_once __DIR__ . '/helpers.php';

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

function renderAccountMenu(array $user): string
{
    $username = escape($user['username']);
    $csrfToken = escape(Auth::csrfToken());

    return <<<HTML
<details class="account-menu">
    <summary class="profile-icon" aria-label="Kontomenü für {$username}" title="Kontomenü für {$username}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="8" r="4"></circle>
            <path d="M4 21a8 8 0 0 1 16 0z"></path>
        </svg>
    </summary>
    <div class="account-dropdown">
        <p class="account-name">{$username}</p>
        <a href="/konto/">Kontoeinstellungen</a>
        <form method="post" action="/">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <button type="submit">Abmelden</button>
        </form>
    </div>
</details>
HTML;
}

function renderEditLink(string $href, string $label): string
{
    $safeHref = escape($href);
    $safeLabel = escape($label);

    return <<<HTML
<a class="edit-link" href="{$safeHref}" aria-label="{$safeLabel}" title="{$safeLabel}">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm17.71-10.04a1 1 0 0 0 0-1.42l-2.5-2.5a1 1 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 2-1.66z"></path>
    </svg>
</a>
HTML;
}
