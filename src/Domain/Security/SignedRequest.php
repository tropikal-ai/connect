<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Security;

final class SignedRequest
{
    public const INSTALLATION_HEADER = 'X-Tropikal-Connect-Installation';

    public const TIMESTAMP_HEADER = 'X-Tropikal-Connect-Timestamp';

    public const NONCE_HEADER = 'X-Tropikal-Connect-Nonce';

    public const BODY_HASH_HEADER = 'X-Tropikal-Connect-Body-SHA256';

    public const SIGNATURE_HEADER = 'X-Tropikal-Connect-Signature';

    public static function headers(
        string $secret,
        string $installationId,
        string $method,
        string $path,
        array|string|null $query = null,
        string $body = '',
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        $bodyHash = self::bodyHash($body);

        return [
            self::INSTALLATION_HEADER => $installationId,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::NONCE_HEADER => $nonce,
            self::BODY_HASH_HEADER => $bodyHash,
            self::SIGNATURE_HEADER => self::sign($secret, $installationId, $method, $path, $query, $timestamp, $nonce, $bodyHash),
        ];
    }

    public static function sign(
        string $secret,
        string $installationId,
        string $method,
        string $path,
        array|string|null $query,
        int $timestamp,
        string $nonce,
        string $bodyHash,
    ): string {
        $secret = trim($secret);
        if ($secret === '') {
            throw new \InvalidArgumentException('A signing secret is required.');
        }

        return hash_hmac('sha256', self::canonical($installationId, $method, $path, $query, $timestamp, $nonce, $bodyHash), $secret);
    }

    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function canonical(
        string $installationId,
        string $method,
        string $path,
        array|string|null $query,
        int $timestamp,
        string $nonce,
        string $bodyHash,
    ): string {
        return implode("\n", [
            $installationId,
            strtoupper($method),
            '/'.ltrim($path, '/'),
            CanonicalQuery::normalize($query),
            (string) $timestamp,
            $nonce,
            $bodyHash,
        ]);
    }
}
