<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Security;

final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new \InvalidArgumentException('Invalid base64url value.');
        }

        return $decoded;
    }
}
