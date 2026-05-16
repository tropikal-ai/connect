<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class TokenSet
{
    private function __construct(
        public string $accessToken,
        public string $refreshToken,
        public array $payload,
    ) {}

    public static function fromArray(array $payload): self
    {
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        $refreshToken = trim((string) ($payload['refresh_token'] ?? ''));
        if ($accessToken === '' || $refreshToken === '') {
            throw new \InvalidArgumentException('Token response must include access and refresh tokens.');
        }

        return new self($accessToken, $refreshToken, $payload);
    }

    public function redacted(): array
    {
        return SensitiveData::redact($this->payload);
    }
}
