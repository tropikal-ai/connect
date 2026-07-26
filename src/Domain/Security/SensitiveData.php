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
        if (! self::isPublicKey($key)) {
            throw new \InvalidArgumentException("Public payload contains a server-only key: {$key}");
        }
    }

    public static function isPublicKey(string $key): bool
    {
        return ! self::isSensitiveKey($key);
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

    /**
     * Keys that are public BY DESIGN despite matching a marker.
     *
     * Exact matches only — never prefixes or substrings — so widening this list
     * can only ever admit the one key named, and can never accidentally admit
     * `access_token`, `refresh_token`, `client_secret` and friends.
     *
     * `resume_token` matches the `token` marker but is a browser-facing
     * capability: it is minted per reply by the control plane, HMAC-signed with
     * the installation secret, scoped to a single session, and rotated on every
     * use. It exists so a visitor can resume their OWN conversation, so the
     * bridge must relay it. Stripping it here would silently kill session
     * resume on every bridged site.
     */
    private const PUBLIC_KEY_ALLOW_LIST = [
        'resource_key',
        'resume_token',
    ];

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        if (in_array($normalized, self::PUBLIC_KEY_ALLOW_LIST, true)) {
            return false;
        }

        if ($normalized === 'key' || str_ends_with($normalized, '_key')) {
            return true;
        }

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
