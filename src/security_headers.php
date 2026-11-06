<?php

declare(strict_types=1);

$securityHeaders = [
    'Strict-Transport-Security' => 'max-age=31536000',
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'same-origin',
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
];

$existingHeaders = array_map(
    static fn (string $header): string => strtolower(strtok($header, ':')),
    headers_list()
);
foreach ($securityHeaders as $name => $value) {
    if (!in_array(strtolower($name), $existingHeaders, true)) {
        header($name . ': ' . $value);
    }
}
header('Cache-Control: no-store, private');
header_remove('X-Powered-By');
