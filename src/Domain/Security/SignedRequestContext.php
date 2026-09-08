<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Security;

/** Optional opaque context authenticated against an already signed request. */
final class SignedRequestContext
{
    public const PAYLOAD_HEADER = 'X-Tropikal-Connect-Context';

    public const PROOF_HEADER = 'X-Tropikal-Connect-Context-Signature';

    public const MAX_PAYLOAD_BYTES = 4096;

    /** @return array<string, string> */
    public static function headers(string $secret, string $requestSignature, string $payload): array
    {
        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('Invalid request context size.');
        }
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return [
            self::PAYLOAD_HEADER => $encoded,
            self::PROOF_HEADER => self::proof($secret, $requestSignature, $encoded),
        ];
    }

    /** @param array<string, string> $headers */
    public static function verify(string $secret, string $requestSignature, array $headers): ?string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $encoded = $headers[strtolower(self::PAYLOAD_HEADER)] ?? null;
        $proof = $headers[strtolower(self::PROOF_HEADER)] ?? null;
        if ($encoded === null && $proof === null) {
            return null;
        }
        if ($encoded === null || $proof === null || strlen($encoded) > 5462
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof) !== 1
            || ! hash_equals(self::proof($secret, $requestSignature, $encoded), $proof)) {
            throw new \InvalidArgumentException('Invalid signed request context.');
        }
        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($payload === false || $payload === '' || strlen($payload) > self::MAX_PAYLOAD_BYTES
            || rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') !== $encoded) {
            throw new \InvalidArgumentException('Invalid request context encoding.');
        }

        return $payload;
    }

    private static function proof(string $secret, string $requestSignature, string $encoded): string
    {
        $secret = trim($secret);
        if ($secret === '' || preg_match('/\A[a-f0-9]{64}\z/D', $requestSignature) !== 1) {
            throw new \InvalidArgumentException('A signing secret and request signature are required.');
        }

        return hash_hmac('sha256', "connect-context.v1\n".$requestSignature."\n".$encoded, $secret);
    }
}
