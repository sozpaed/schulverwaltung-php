<?php

declare(strict_types=1);

namespace Schulverwaltung;

final class SessionPolicy
{
    public const IDLE_SECONDS = 1800;
    public const ABSOLUTE_SECONDS = 28800;
    public const REGENERATE_SECONDS = 900;

    public static function expired(int $now, int $loggedInAt, int $lastActivity): bool
    {
        return $loggedInAt > $now || $lastActivity > $now
            || $now - $lastActivity >= self::IDLE_SECONDS
            || $now - $loggedInAt >= self::ABSOLUTE_SECONDS;
    }

    public static function shouldRegenerate(int $now, int $lastRegenerated): bool
    {
        return $lastRegenerated > $now || $now - $lastRegenerated >= self::REGENERATE_SECONDS;
    }
}
