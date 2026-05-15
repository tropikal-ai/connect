<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

use TropikalAI\Connect\Domain\Security\Base64Url;

final readonly class PkcePair
{
    private function __construct(
        public string $verifier,
        public string $challenge,
    ) {}

    public static function generate(int $bytes = 64): self
    {
        return self::fromVerifier(Base64Url::encode(random_bytes($bytes)));
    }

    public static function fromVerifier(string $verifier): self
    {
        $length = strlen($verifier);
        if ($length < 43 || $length > 128 || preg_match('/^[A-Za-z0-9._~-]+$/', $verifier) !== 1) {
            throw new \InvalidArgumentException('PKCE verifier must be 43-128 unreserved characters.');
        }

        return new self($verifier, Base64Url::encode(hash('sha256', $verifier, true)));
    }
}
