<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels;

final readonly class PublicResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body, array $headers = []): self
    {
        return new self(
            $status,
            (string) json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', ...$headers],
        );
    }
}
