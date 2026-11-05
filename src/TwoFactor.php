<?php

declare(strict_types=1);

namespace Schulverwaltung;

use RuntimeException;

final class TwoFactor
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function encryptSecret(string $secret, int $userId): string
    {
        self::requireOpenSsl();
        $key = self::encryptionKey();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::associatedData($userId)
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Der Zwei-Faktor-Schlüssel konnte nicht verschlüsselt werden.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decryptSecret(string $encryptedSecret, int $userId): string
    {
        self::requireOpenSsl();
        $payload = base64_decode($encryptedSecret, true);

        if ($payload === false || strlen($payload) < 29) {
            throw new RuntimeException('Der gespeicherte Zwei-Faktor-Schlüssel ist ungültig.');
        }

        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $secret = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::associatedData($userId)
        );

        if ($secret === false) {
            throw new RuntimeException('Der Zwei-Faktor-Schlüssel konnte nicht entschlüsselt werden.');
        }

        return $secret;
    }

    public static function matchingCounter(string $secret, mixed $code, ?int $timestamp = null): ?int
    {
        if (!is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }

        $key = self::base32Decode($secret);
        $currentCounter = intdiv($timestamp ?? time(), 30);

        for ($counter = max(0, $currentCounter - 1); $counter <= $currentCounter + 1; $counter++) {
            if (hash_equals(self::codeForCounter($key, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    private static function codeForCounter(string $key, int $counter): string
    {
        $message = pack('N2', intdiv($counter, 0x100000000), $counter % 0x100000000);
        $hash = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function base32Decode(string $value): string
    {
        $normalized = strtoupper(rtrim(str_replace(' ', '', $value), '='));
        if ($normalized === '' || preg_match('/\A[A-Z2-7]+\z/', $normalized) !== 1) {
            throw new RuntimeException('Der Zwei-Faktor-Schlüssel ist ungültig.');
        }

        $bits = '';
        foreach (str_split($normalized) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('Der Zwei-Faktor-Schlüssel ist ungültig.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        if (strlen($decoded) < 16) {
            throw new RuntimeException('Der Zwei-Faktor-Schlüssel ist zu kurz.');
        }

        return $decoded;
    }

    private static function associatedData(int $userId): string
    {
        return 'schulverwaltung:totp:user:' . $userId;
    }

    private static function requireOpenSsl(): void
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('Die PHP-Erweiterung OpenSSL muss für die Zwei-Faktor-Anmeldung aktiviert sein.');
        }
    }

    private static function encryptionKey(): string
    {
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
        $path = $directory . DIRECTORY_SEPARATOR . 'totp.key';

        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('Das Verzeichnis für den Zwei-Faktor-Schlüssel konnte nicht angelegt werden.');
        }

        if (!is_file($path)) {
            $handle = @fopen($path, 'x+b');
            if ($handle !== false) {
                $generatedKey = random_bytes(32);
                $written = fwrite($handle, $generatedKey);
                fflush($handle);
                fclose($handle);

                if ($written !== 32) {
                    throw new RuntimeException('Der Zwei-Faktor-Verschlüsselungsschlüssel konnte nicht gespeichert werden.');
                }
                @chmod($path, 0600);
            }
        }

        $key = @file_get_contents($path);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Der Zwei-Faktor-Verschlüsselungsschlüssel fehlt oder ist ungültig.');
        }

        return $key;
    }
}
