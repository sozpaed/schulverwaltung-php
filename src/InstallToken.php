<?php

declare(strict_types=1);

namespace Schulverwaltung;

final class InstallToken
{
    public static function load(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $token = file_get_contents($path);
        if (!is_string($token)) {
            return null;
        }

        $token = rtrim($token, "\r\n");

        return $token !== '' ? $token : null;
    }

    public static function matches(?string $expectedToken, mixed $candidate): bool
    {
        return $expectedToken !== null
            && $expectedToken !== ''
            && is_string($candidate)
            && $candidate !== ''
            && hash_equals($expectedToken, $candidate);
    }
}
