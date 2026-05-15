<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

use TropikalAI\Connect\Domain\Security\Base64Url;

final readonly class OAuthState
{
    private function __construct(
        public string $plain,
        public string $hash,
        public \DateTimeImmutable $expiresAt,
    ) {}

    public static function generate(int $ttlSeconds = 600, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable;
        $plain = Base64Url::encode(random_bytes(48));

        return new self($plain, self::hash($plain), $now->modify("+{$ttlSeconds} seconds"));
    }

    public static function hash(string $state): string
    {
        return hash('sha256', $state);
    }

    public static function valid(string $plain, string $expectedHash, \DateTimeInterface $expiresAt, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable;

        return $expiresAt > $now && hash_equals($expectedHash, self::hash($plain));
    }
}
