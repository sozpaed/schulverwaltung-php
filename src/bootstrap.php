<?php

declare(strict_types=1);

use Schulverwaltung\Auth;
use Schulverwaltung\Database;

require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/SessionPolicy.php';
require_once __DIR__ . '/LoginThrottle.php';
require_once __DIR__ . '/TwoFactor.php';
require_once __DIR__ . '/RecoveryCodes.php';
require_once __DIR__ . '/SchoolSettings.php';
require_once __DIR__ . '/helpers.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) \Schulverwaltung\SessionPolicy::ABSOLUTE_SECONDS);
session_cache_limiter('');

$configuration = Database::configuration();
$insecureCookies = $configuration['DEV_INSECURE_COOKIES'] ?? false;
if (!is_bool($insecureCookies)) {
    throw new RuntimeException('DEV_INSECURE_COOKIES muss ein boolescher Wert sein.');
}
if ($insecureCookies && (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true))) {
    throw new RuntimeException('DEV_INSECURE_COOKIES ist nur für lokale Anfragen zulässig.');
}
session_name($insecureCookies ? 'sv_dev_session' : '__Host-sv_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'httponly' => true,
    'secure' => !$insecureCookies,
    'samesite' => 'Strict',
]);

session_start();

$database = Database::connect();
$user = Auth::user($database);
if (isset($_SESSION['session_expired'])
    && (($_SERVER['SCRIPT_NAME'] ?? '') !== '/index.php' || $_SERVER['REQUEST_METHOD'] !== 'GET')) {
    header('Location: /', true, 303);
    exit;
}

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

function renderSchoolHeader(PDO $database): string
{
    return renderPageHeader(\Schulverwaltung\SchoolSettings::schoolName($database));
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

function renderDeleteLink(string $href, string $label): string
{
    $safeHref = escape($href);
    $safeLabel = escape($label);

    return <<<HTML
<a class="delete-link" href="{$safeHref}" aria-label="{$safeLabel}" title="{$safeLabel}">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-3 6h12l-1 11H7L6 9Zm4 2v7h2v-7h-2Zm3 0v7h2v-7h-2Z"></path>
    </svg>
</a>
HTML;
}

function guardianRelationships(): array
{
    return [
        'mutter' => 'Mutter',
        'vater' => 'Vater',
        'vormund' => 'Vormund',
        'pflegeeltern' => 'Pflegeeltern',
        'sonstiges' => 'Sonstiges',
    ];
}

function renderStudentDetailLink(int $studentId, string $name): string
{
    $label = escape('Schülerinnendaten anzeigen: ' . $name);

    return <<<HTML
<a class="detail-link" href="/schueler/zeigen.php?id={$studentId}" aria-label="{$label}" title="{$label}">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M19.14 12.94a7.5 7.5 0 0 0 0-1.88l2.03-1.58-2-3.46-2.39.96a7.4 7.4 0 0 0-1.62-.94L14.8 3H9.2l-.36 3.04c-.58.23-1.12.55-1.62.94l-2.39-.96-2 3.46 2.03 1.58a7.5 7.5 0 0 0 0 1.88l-2.03 1.58 2 3.46 2.39-.96c.5.39 1.04.71 1.62.94L9.2 21h5.6l.36-3.04c.58-.23 1.12-.55 1.62-.94l2.39.96 2-3.46-2.03-1.58ZM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"></path>
    </svg>
</a>
HTML;
}
