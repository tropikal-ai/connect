<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Security;

final class SensitiveData
{
    private const KEY_MARKERS = [
        'access_token',
        'api_key',
        'assertion',
        'bearer',
        'client_secret',
        'credential',
        'hmac',
        'key',
        'password',
        'private',
        'refresh',
        'secret',
        'signature',
        'token',
    ];

    private const VALUE_MARKERS = [
        'Bearer ',
    ];

    public static function assertPublicPayload(array $payload): void
    {
        self::walk($payload, []);
    }

    public static function assertPublicKey(string $key): void
    {
        $normalized = strtolower($key);
        foreach (self::KEY_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                throw new \InvalidArgumentException("Public payload contains a server-only key: {$key}");
            }
        }
    }

    public static function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $child) {
            $keyName = (string) $key;
            $redacted[$key] = self::isSensitiveKey($keyName) ? '[redacted]' : self::redact($child);
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::KEY_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function walk(mixed $value, array $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $keyName = (string) $key;
                self::assertPublicKey($keyName);
                self::walk($child, [...$path, $keyName]);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        foreach (self::VALUE_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                $location = implode('.', $path) ?: '<root>';
                throw new \InvalidArgumentException("Public payload contains a server-only value at {$location}");
            }
        }
    }
}
